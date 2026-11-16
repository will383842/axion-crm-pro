<?php

namespace App\Crm\Contacts;

use App\Crm\Identite\CleDePersonne;
use App\Crm\Opposition\StatutOpposition;
use App\Support\AuditLogger;
use App\Support\MasquageCoordonnees;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ajout rapide d'un contact (G1-C). Jamais de création d'entreprise ; jamais de suppression ; aucun envoi.
 * Le filtre `workspace_id` est EXPLICITE (il ne dépend pas de la RLS : le propriétaire l'ignore).
 *
 * Une personne opposée ou ayant demandé l'effacement ne revient JAMAIS (échec fermé) : adresse en opposition,
 * registre des retraits (nom + organisme), ou absence de verdict → refus, rien n'est écrit.
 */
final class AjoutContact
{
    public const PROVENANCES = ['salon', 'rencontre', 'site', 'recommandation', 'fichier-client', 'autre'];

    private const MAX_DOUBLONS = 5;

    /**
     * @param  array<string, mixed>  $d  entrée déjà validée
     * @return array{etat: 'organisation_introuvable'|'organisation_ambigue'|'opposition'|'doublon'|'introuvable'|'fusion_invalide'}
     *                                                                                                                               |array{etat: 'doublons', doublons: list<array<string, mixed>>}
     *                                                                                                                               |array{etat: 'cree'|'fusionne', fiche: array<string, mixed>}
     */
    public function ajouter(string $workspaceId, array $d, string $userId): array
    {
        return DB::transaction(function () use ($workspaceId, $d, $userId): array {
            $entreprise = $this->entreprise($workspaceId, isset($d['company_id']) ? (int) $d['company_id'] : null, is_string($d['organisation'] ?? null) ? $d['organisation'] : null);
            if (! is_object($entreprise)) {
                return ['etat' => $entreprise];
            }
            $prenom = $this->texte($d['first_name'] ?? null);
            $nom = trim((string) ($d['last_name'] ?? ''));
            $email = $this->texte($d['email'] ?? null);
            $provenance = (string) ($d['provenance'] ?? '');
            $siren = is_string($entreprise->siren) ? $entreprise->siren : null;

            // 1. Opposition / effacement : refus avant toute écriture, y compris pour « garder » et « fusionner ».
            $verdict = $this->opposition($workspaceId, $email, $siren, $prenom, $nom);
            if ($verdict['opposee']) {
                return ['etat' => 'opposition'];
            }

            $choix = $d['doublon'] ?? null;

            // 2. Fusion : on ne complète que les champs VIDES d'une fiche existante de l'espace.
            if ($choix === 'fusionner') {
                if (! isset($d['fusionner_avec'])) {
                    return ['etat' => 'fusion_invalide'];
                }

                return $this->fusionner($workspaceId, (int) $d['fusionner_avec'], $entreprise, $prenom, $email, $this->texte($d['fonction'] ?? null), $provenance, $verdict);
            }

            // 3. Doublons : sans choix explicite, on les montre au lieu de décider.
            if ($choix === null) {
                $doublons = $this->doublons($workspaceId, $email, $prenom, $nom, (int) $entreprise->id);
                if ($doublons !== []) {
                    return ['etat' => 'doublons', 'doublons' => $doublons];
                }
            }

            // 4. Création. Le même nom dans la même entreprise (même en corbeille) est refusé par l'unicité : 409.
            $origines = ['last_name' => 'declared'];
            foreach (['first_name' => $prenom, 'email' => $email, 'role' => $this->texte($d['fonction'] ?? null)] as $colonne => $valeur) {
                if ($valeur !== null) {
                    $origines[$colonne] = 'declared';
                }
            }
            try {
                $id = (int) DB::transaction(fn () => DB::table('contacts')->insertGetId([
                    'workspace_id' => $workspaceId,
                    'company_id' => (int) $entreprise->id,
                    'first_name' => $prenom,
                    'last_name' => $nom,
                    'email' => $email,
                    'role' => $this->texte($d['fonction'] ?? null),
                    'discovery_source' => 'console',
                    'sources' => json_encode(['console:' . $provenance], JSON_THROW_ON_ERROR),
                    'legal_basis' => 'legitimate_interest_b2b',
                    'person_key' => CleDePersonne::pour($email),
                    'field_origins' => json_encode($origines, JSON_THROW_ON_ERROR),
                    'metadata' => '{}',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            } catch (QueryException $e) {
                if ($e->getCode() === '23505') {
                    return ['etat' => 'doublon'];
                }
                throw $e;
            }

            AuditLogger::log('contact.ajoute', [
                'workspace_id' => $workspaceId, 'resource_type' => 'contact', 'resource_id' => $id, 'provenance' => $provenance, 'par' => $userId,
            ]);

            return ['etat' => 'cree', 'fiche' => $this->fiche($workspaceId, $id, $entreprise, $provenance, $verdict)];
        });
    }

    /**
     * Doublons probables, vivants seulement (max 5) : même adresse, puis même nom dans la même entreprise.
     *
     * @return list<array{id: int, person_key: ?string, nom_complet: string, email: ?string, organisation: ?string, raison: 'meme_email'|'meme_nom'}>
     */
    public function doublons(string $workspaceId, ?string $email, ?string $prenom, ?string $nom, ?int $companyId): array
    {
        $trouves = [];
        $email = $this->texte($email);
        if ($email !== null) {
            foreach (DB::select(
                'SELECT c.id FROM contacts c WHERE c.workspace_id = ? AND c.deleted_at IS NULL AND c.email = ?::citext ORDER BY c.id LIMIT ' . self::MAX_DOUBLONS,
                [$workspaceId, $email],
            ) as $l) {
                $trouves[(int) $l->id] = 'meme_email';
            }
        }
        $nom = $this->texte($nom);
        if ($nom !== null && $companyId !== null && count($trouves) < self::MAX_DOUBLONS) {
            foreach (DB::select(
                "SELECT c.id FROM contacts c WHERE c.workspace_id = ? AND c.deleted_at IS NULL AND c.company_id = ?
                 AND c.normalized_hash = encode(digest(normalize_name(coalesce(?::text, '') || '_' || ?::text) || '_' || ?::text, 'sha256'), 'hex')
                 ORDER BY c.id LIMIT " . self::MAX_DOUBLONS,
                [$workspaceId, $companyId, $this->texte($prenom), $nom, $companyId],
            ) as $l) {
                $trouves[(int) $l->id] ??= 'meme_nom';
            }
        }
        if ($trouves === []) {
            return [];
        }
        $trouves = array_slice($trouves, 0, self::MAX_DOUBLONS, true);

        $lignes = DB::select(
            'SELECT c.id, c.person_key, c.first_name, c.last_name, c.email::text AS email, e.denomination
             FROM contacts c LEFT JOIN companies e ON e.id = c.company_id AND e.workspace_id = c.workspace_id
             WHERE c.workspace_id = ? AND c.deleted_at IS NULL AND c.id IN (' . implode(',', array_map('intval', array_keys($trouves))) . ') ORDER BY c.id',
            [$workspaceId],
        );
        $masquer = MasquageCoordonnees::requis();
        $rendu = [];
        foreach ($lignes as $l) {
            $courriel = is_string($l->email) ? $l->email : null;
            $rendu[] = [
                'id' => (int) $l->id,
                'person_key' => is_string($l->person_key) ? $l->person_key : null,
                'nom_complet' => trim((is_string($l->first_name) ? $l->first_name : '') . ' ' . $l->last_name),
                'email' => $masquer ? MasquageCoordonnees::email($courriel) : $courriel,
                'organisation' => is_string($l->denomination) ? $l->denomination : null,
                'raison' => $trouves[(int) $l->id],
            ];
        }

        return $rendu;
    }

    /** Entreprise de l'espace (jamais créée) : l'objet, ou `organisation_introuvable` / `organisation_ambigue`. */
    public function entreprise(string $workspaceId, ?int $companyId, ?string $organisation): object|string
    {
        $base = DB::table('companies')->where('workspace_id', $workspaceId)->whereNull('deleted_at');
        if ($companyId !== null) {
            $e = $base->where('id', $companyId)->first(['id', 'siren', 'denomination', 'relation_type', 'lifecycle_stage']);

            return $e ?? 'organisation_introuvable';
        }
        $saisie = trim((string) $organisation);
        if ($saisie === '') {
            return 'organisation_introuvable';
        }
        $sirenSaisi = preg_replace('/\s+/', '', $saisie);
        if (preg_match('/^\d{9}$/', (string) $sirenSaisi) === 1) {
            $base->where('siren', $sirenSaisi);
        } else {
            $base->whereRaw('denomination_normalized = normalize_name(?)', [$saisie]);
        }
        $trouvees = $base->orderBy('id')->limit(2)->get(['id', 'siren', 'denomination', 'relation_type', 'lifecycle_stage']);

        return match ($trouvees->count()) {
            0 => 'organisation_introuvable',
            1 => $trouvees->first(),
            default => 'organisation_ambigue',
        };
    }

    /**
     * Échec FERMÉ : adresse en opposition, registre des retraits, ou aucun verdict → `opposee`.
     *
     * @return array{opposee: bool, motifs: list<string>}
     */
    private function opposition(string $workspaceId, ?string $email, ?string $siren, ?string $prenom, string $nom): array
    {
        $statut = (new StatutOpposition)->pourLignes($workspaceId, [0 => ['email' => $email, 'siren' => $siren]]);
        $verdict = $statut[0] ?? ['opposee' => true, 'motifs' => ['verdict_absent']];
        if (($verdict['opposee'] ?? true) === true) {
            return ['opposee' => true, 'motifs' => $verdict['motifs'] ?? []];
        }
        if ($siren !== null && $nom !== '') {
            $r = DB::selectOne('SELECT contacts_retires_contient(?::uuid, ?, ?, ?) AS e', [$workspaceId, $siren, $prenom ?? '', $nom]);
            if ($r === null || $r->e !== false) {
                return ['opposee' => true, 'motifs' => ['retrait_enregistre']];
            }
        }

        return ['opposee' => false, 'motifs' => []];
    }

    /**
     * @param  array{opposee: bool, motifs: list<string>}  $verdict
     * @return array{etat: 'introuvable'|'opposition'|'doublon'}|array{etat: 'fusionne', fiche: array<string, mixed>}
     */
    private function fusionner(string $workspaceId, int $cibleId, object $entreprise, ?string $prenom, ?string $email, ?string $fonction, string $provenance, array $verdict): array
    {
        $cible = DB::table('contacts')->where('workspace_id', $workspaceId)->where('id', $cibleId)->whereNull('deleted_at')->lockForUpdate()->first();
        if ($cible === null) {
            return ['etat' => 'introuvable'];
        }
        // La fiche existante est aussi vérifiée (échec fermé) : on ne ravive pas une personne opposée.
        $siren = DB::table('companies')->where('workspace_id', $workspaceId)->where('id', $cible->company_id)->value('siren');
        $nomCible = (string) $cible->last_name;
        $v = $this->opposition($workspaceId, is_string($cible->email) ? $cible->email : null, is_string($siren) ? $siren : null, is_string($cible->first_name) ? $cible->first_name : null, $nomCible);
        if ($v['opposee']) {
            return ['etat' => 'opposition'];
        }

        $vide = fn (?string $v) => $v === null || trim($v) === '';
        $maj = [];
        $origines = json_decode(is_string($cible->field_origins ?? null) ? $cible->field_origins : '{}', true);
        $origines = is_array($origines) ? $origines : [];
        foreach (['first_name' => $prenom, 'email' => $email, 'role' => $fonction] as $colonne => $valeur) {
            if ($valeur !== null && $vide(is_string($cible->{$colonne}) ? $cible->{$colonne} : null)) {
                $maj[$colonne] = $valeur;
                $origines[$colonne] = 'declared';
            }
        }
        if (array_key_exists('email', $maj)) {
            $cle = CleDePersonne::pour($maj['email']);
            if ($cle !== null) {
                $maj['person_key'] = $cle;
            }
        }
        $sources = json_decode(is_string($cible->sources ?? null) ? $cible->sources : '[]', true);
        $sources = is_array($sources) ? $sources : [];
        $marque = 'console:' . $provenance;
        if (! in_array($marque, $sources, true)) {
            $sources[] = $marque;
        }
        $maj['sources'] = json_encode(array_values($sources), JSON_THROW_ON_ERROR);
        $maj['field_origins'] = json_encode($origines, JSON_THROW_ON_ERROR);
        $maj['updated_at'] = now();

        try {
            DB::transaction(fn () => DB::table('contacts')->where('workspace_id', $workspaceId)->where('id', $cibleId)->update($maj));
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                return ['etat' => 'doublon'];
            }
            throw $e;
        }
        AuditLogger::log('contact.ajoute', [
            'workspace_id' => $workspaceId, 'resource_type' => 'contact', 'resource_id' => $cibleId, 'provenance' => $provenance, 'fusion' => true,
        ]);

        $entrepriseCible = DB::table('companies')->where('workspace_id', $workspaceId)->where('id', $cible->company_id)->first(['id', 'siren', 'denomination', 'relation_type', 'lifecycle_stage']) ?? $entreprise;

        return ['etat' => 'fusionne', 'fiche' => $this->fiche($workspaceId, $cibleId, $entrepriseCible, $provenance, $v)];
    }

    /**
     * Forme `FicheContact` (`ajout-rapide/types.ts`). Champs que le serveur ne porte pas : valeur neutre explicite.
     *
     * @param  array{opposee: bool, motifs: list<string>}  $verdict
     * @return array<string, mixed>
     */
    private function fiche(string $workspaceId, int $id, object $entreprise, string $provenance, array $verdict): array
    {
        $c = DB::table('contacts')->where('workspace_id', $workspaceId)->where('id', $id)->first();
        $courriel = is_string($c->email ?? null) ? $c->email : null;

        return [
            'id' => (int) $c->id,
            'company_id' => (int) $c->company_id,
            'first_name' => (string) ($c->first_name ?? ''),
            'last_name' => (string) $c->last_name,
            'email' => MasquageCoordonnees::requis() ? (string) MasquageCoordonnees::email($courriel) : (string) $courriel,
            'fonction' => (string) ($c->role ?? ''),
            'organisation' => (string) ($entreprise->denomination ?? ''),
            'relation_type' => $entreprise->relation_type ?? null,
            'lifecycle_stage' => $entreprise->lifecycle_stage ?? null,
            'provenance' => $provenance,
            'person_key' => (string) ($c->person_key ?? ''),
            'retire_le' => null,
            'ne_plus_ecrire' => $verdict['opposee'],
            'verifie_il_y_a_jours' => null,
            'rappel_le' => null,
            'rappel_note' => null,
            'created_at' => Carbon::parse((string) $c->created_at)->toIso8601String(),
        ];
    }

    private function texte(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $v = trim($v);

        return $v === '' ? null : $v;
    }
}
