<?php

namespace App\Envoi\Reponses;

use App\Crm\Ingest\ContactUpserter;
use App\Crm\Ingest\IngestOutcome;
use App\Envoi\Desinscription\EnregistrerOpposition;
use App\Envoi\Desinscription\OpposerAdresse;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Lecture et gestes sur les réponses (R12). Aucun envoi, jamais : « répondre » reste fermé (409 au contrôleur).
 * Un geste humain « stop » met l'adresse à l'écart (`EnregistrerOpposition` puis ses effets), sans rien effacer.
 * Chaque geste prend un verrou consultatif PAR RÉPONSE : deux gestes simultanés se suivent.
 *
 * @phpstan-type LigneReponse object{id: int, recue_le: string, expediteur_chiffre: string, objet_chiffre: string, extrait_chiffre: string, contact_id: int|null, boite_id: string|null, empreinte_expediteur: string, classement: string, sug_classement: string, sug_confiance: int|string, auteur: string, evenement_id: int, emp_evt: string|null, emp_dest: string|null, rattachee: bool}
 */
final class ServiceReponses
{
    /** Message de l'exception levée quand un classement « stop » est corrigé : une opposition ne se lève jamais depuis l'écran. */
    public const STOP_DEFINITIF = 'stop_definitif';

    public const CLASSEMENTS = ['interesse', 'pas_maintenant', 'question', 'refus', 'stop', 'absence'];

    public function __construct(
        private readonly EnregistrerOpposition $opposition,
        private readonly ContactUpserter $contacts,
        private readonly EffetsClassement $effets = new EffetsClassement,
    ) {}

    private const BASE = <<<'SQL'
        SELECT r.id, r.recue_le, r.expediteur_chiffre, r.objet_chiffre, r.extrait_chiffre, r.empreinte_expediteur,
               e.id AS evenement_id, e.statut, e.boite, e.empreinte_expediteur AS emp_evt, e.empreinte_destinataire AS emp_dest,
               CASE WHEN c.geste = 'annulation' THEN COALESCE(s.classement, 'a_rattacher') ELSE c.classement END AS classement,
               CASE WHEN c.geste = 'annulation' THEN 'classificateur' ELSE c.auteur END AS auteur,
               COALESCE(s.classement, 'a_rattacher') AS sug_classement, COALESCE(s.confiance, 0) AS sug_confiance,
               f.contact_id,
               (e.statut = 'rattache' OR f.contact_id IS NOT NULL
                OR EXISTS (SELECT 1 FROM envoi_rattachements t WHERE t.workspace_id = r.workspace_id AND t.evenement_id = e.id)) AS rattachee,
               (SELECT b.id FROM envoi_boites b WHERE b.workspace_id = r.workspace_id AND (b.adresse = e.boite OR b.id::text = e.boite) ORDER BY b.cree_le LIMIT 1) AS boite_id
        FROM envoi_reponses r
        JOIN envoi_evenements e ON e.id = r.evenement_id AND e.workspace_id = r.workspace_id
        JOIN LATERAL (SELECT geste, classement, auteur FROM envoi_reponses_classements
                      WHERE reponse_id = r.id AND geste IN ('classement', 'annulation') ORDER BY id DESC LIMIT 1) c ON true
        LEFT JOIN LATERAL (SELECT classement, confiance FROM envoi_reponses_classements
                      WHERE reponse_id = r.id AND geste = 'classement' AND auteur = 'classificateur' ORDER BY id DESC LIMIT 1) s ON true
        LEFT JOIN LATERAL (SELECT contact_id FROM envoi_reponses_classements
                      WHERE reponse_id = r.id AND geste = 'fiche' ORDER BY id DESC LIMIT 1) f ON true
        WHERE r.workspace_id = ?
        SQL;

    /** @return array{data: array<array<string, mixed>>, meta: array<string, int>, par_file: array<string, int>} */
    public function lister(string $ws, string $file, ?string $classement, ?string $recherche, int $page, int $parPage): array
    {
        return WorkspaceContext::run($ws, function () use ($ws, $file, $classement, $recherche, $page, $parPage): array {
            $filtre = self::filtreFile($file);
            $params = [$ws];
            $sql = 'SELECT * FROM (' . self::BASE . ') x WHERE ' . $filtre;
            if ($classement !== null) {
                $sql .= ' AND classement = ?';
                $params[] = $classement;
            }
            if ($recherche !== null && trim($recherche) !== '') {
                $sql .= ' AND empreinte_expediteur = ?'; // adresse EXACTE seulement : jamais de recherche floue sur des adresses
                $params[] = ListeSuppression::empreinte($recherche);
            }
            $total = (int) DB::selectOne("SELECT count(*) AS n FROM ({$sql}) y", $params)->n;
            /** @var list<LigneReponse> $lignes */
            $lignes = DB::select($sql . ' ORDER BY recue_le DESC, id DESC LIMIT ? OFFSET ?', [...$params, $parPage, ($page - 1) * $parPage]);
            $compte = DB::selectOne(
                'SELECT count(*) FILTER (WHERE ' . self::filtreFile('a_traiter') . ') AS a_traiter,
                        count(*) FILTER (WHERE ' . self::filtreFile('a_rattacher') . ') AS a_rattacher,
                        count(*) FILTER (WHERE ' . self::filtreFile('traitees') . ') AS traitees
                 FROM (' . self::BASE . ') x',
                [$ws],
            );

            return [
                'data' => array_map(fn ($l): array => $this->resume($ws, $l), $lignes),
                'meta' => ['page' => $page, 'par_page' => $parPage, 'total' => $total, 'pages' => (int) ceil($total / $parPage)],
                'par_file' => ['a_traiter' => (int) $compte->a_traiter, 'a_rattacher' => (int) $compte->a_rattacher, 'traitees' => (int) $compte->traitees],
            ];
        });
    }

    /** Réponses non classées par un humain (« à traiter » + « à rattacher ») : nourrit le frein humain de la chauffe. */
    public static function nonTraitees(string $ws): int
    {
        return WorkspaceContext::run($ws, fn (): int => (int) DB::selectOne(
            'SELECT count(*) AS n FROM (' . self::BASE . ') x WHERE ' . self::filtreFile('a_traiter') . ' OR ' . self::filtreFile('a_rattacher'),
            [$ws],
        )->n);
    }

    private static function filtreFile(string $file): string
    {
        return match ($file) {
            'traitees' => "auteur = 'humain'",
            'a_rattacher' => "auteur <> 'humain' AND NOT rattachee",
            default => "auteur <> 'humain' AND rattachee",
        };
    }

    /** @return array<string, mixed>|null */
    public function lire(string $ws, int $id): ?array
    {
        return WorkspaceContext::run($ws, function () use ($ws, $id): ?array {
            $l = $this->ligne($ws, $id);
            if ($l === null) {
                return null;
            }

            return [
                'reponse' => $this->resume($ws, $l),
                'fil' => [[
                    'id' => 'r' . $l->id, 'sens' => 'recu', 'de' => Crypt::decryptString($l->expediteur_chiffre),
                    'le' => self::iso($l->recue_le), 'objet' => Crypt::decryptString($l->objet_chiffre),
                    'corps' => Crypt::decryptString($l->extrait_chiffre),
                ]],
                'suggestions_rattachement' => [],
            ];
        });
    }

    /** @return array<string, mixed>|null  null = réponse inconnue de cet espace */
    public function resumeDe(string $ws, int $id): ?array
    {
        return WorkspaceContext::run($ws, fn (): ?array => ($l = $this->ligne($ws, $id)) === null ? null : $this->resume($ws, $l));
    }

    /** @return LigneReponse|null */
    private function ligne(string $ws, int $id): ?object
    {
        return DB::selectOne('SELECT * FROM (' . self::BASE . ') x WHERE id = ?', [$ws, $id]);
    }

    /**
     * @param  LigneReponse  $l
     * @return array<string, mixed> forme `ReponseResume` du contrat
     */
    private function resume(string $ws, object $l): array
    {
        $fiche = $l->contact_id === null ? null : DB::table('contacts as c')
            ->leftJoin('companies as o', 'o.id', '=', 'c.company_id')
            ->where('c.workspace_id', $ws)->where('c.id', $l->contact_id)->first(['c.first_name', 'c.last_name', 'o.denomination']);

        return [
            'id' => (string) $l->id,
            'recue_le' => self::iso($l->recue_le),
            'campagne_id' => null,
            'destinataire_id' => $l->contact_id === null ? null : (string) $l->contact_id,
            'boite_id' => (string) ($l->boite_id ?? 'inconnue'),
            'de' => Crypt::decryptString($l->expediteur_chiffre),
            'prenom' => $fiche->first_name ?? null,
            'nom' => $fiche->last_name ?? null,
            'organisation' => $fiche->denomination ?? null,
            'objet' => Crypt::decryptString($l->objet_chiffre),
            'extrait' => Crypt::decryptString($l->extrait_chiffre),
            'classement' => $l->classement,
            'suggestion' => ['classement' => $l->sug_classement, 'confiance' => (int) $l->sug_confiance],
            'traitee' => $l->auteur === 'humain',
        ];
    }

    /**
     * Classement HUMAIN. « stop » : opposition d'abord (idempotente), puis la ligne de classement.
     * Rejouer le même geste ne crée ni seconde opposition ni seconde ligne ; un rejeu de « stop » refait les effets.
     *
     * Corriger depuis « Traitées » : permis, sauf si le classement courant est « stop » et le nouveau ne l'est pas.
     *
     * @return array<string, mixed>|null
     *
     * @throws \DomainException `stop_definitif` (aucune ligne écrite) ; la route RR-6B le traduit en 409
     */
    public function classer(string $ws, string $userId, int $id, string $classement, bool $creerTache = false): ?array
    {
        return WorkspaceContext::run($ws, function () use ($ws, $userId, $id, $classement, $creerTache): ?array {
            $l = $this->ligne($ws, $id);
            if ($l === null) {
                return null;
            }
            if ($l->auteur === 'humain' && $l->classement === 'stop' && $classement !== 'stop') {
                throw new \DomainException(self::STOP_DEFINITIF);
            }
            if ($classement === 'stop') {
                foreach (array_unique(array_filter([$l->emp_evt, $l->emp_dest])) as $empreinte) {
                    $this->opposer($ws, (string) $empreinte);
                }
            }
            // Ligne de classement, puis arrêts, puis tâche : une seule transaction (rien d'écrit si la tâche échoue ; le rejeu refait tout).
            $tache = DB::transaction(function () use ($ws, $userId, $id, $classement, $creerTache, $l): ?string {
                $this->verrou($ws, $id);
                $courant = $this->courant($id);
                if ($courant !== null && $courant->geste === 'classement' && $courant->auteur === 'humain' && $courant->classement === 'stop' && $classement !== 'stop') {
                    throw new \DomainException(self::STOP_DEFINITIF); // course : un « stop » posé entre-temps
                }
                if (! ($courant !== null && $courant->geste === 'classement' && $courant->auteur === 'humain' && $courant->classement === $classement)) {
                    DB::table('envoi_reponses_classements')->insert([
                        'workspace_id' => $ws, 'reponse_id' => $id, 'geste' => 'classement', 'classement' => $classement,
                        'confiance' => 100, 'auteur' => 'humain', 'auteur_id' => $userId, 'cree_le' => now(),
                    ]);
                }

                return $this->effets->appliquer($ws, $l, $classement, $creerTache);
            });

            return $this->resumeDe($ws, $id) + [
                'resume_crm' => ['classement' => $classement, 'le' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
                    'lien' => '/envoi/reponses/' . $id, 'tache' => $tache, 'opposition' => $classement === 'stop'],
            ];
        });
    }

    /**
     * Annule le classement HUMAIN courant (ajout d'une ligne `annulation`, rien n'est effacé ni défait : arrêts, tâches et avis restent).
     *
     * @return array{0: string, 1: ?array<string, mixed>} [état, résumé] : ok | inconnue | pas_traitee | stop_definitif
     */
    public function annuler(string $ws, string $userId, int $id): array
    {
        return WorkspaceContext::run($ws, function () use ($ws, $userId, $id): array {
            if ($this->ligne($ws, $id) === null) {
                return ['inconnue', null];
            }

            $etat = DB::transaction(function () use ($ws, $userId, $id): string {
                $this->verrou($ws, $id);
                $courant = $this->courant($id);
                if ($courant === null || $courant->geste !== 'classement' || $courant->auteur !== 'humain') {
                    return 'pas_traitee';
                }
                if ($courant->classement === 'stop') {
                    return 'stop_definitif';
                }
                DB::table('envoi_reponses_classements')->insert([
                    'workspace_id' => $ws, 'reponse_id' => $id, 'geste' => 'annulation', 'classement' => $courant->classement,
                    'confiance' => 100, 'auteur' => 'humain', 'auteur_id' => $userId, 'cree_le' => now(),
                ]);

                return 'ok';
            });

            return $etat === 'ok'
                ? ['ok', ($this->resumeDe($ws, $id) ?? []) + ['message' => 'Classement annulé. Les arrêts et les tâches déjà créés sont conservés.']]
                : [$etat, null];
        });
    }

    /** Dernière ligne COURANTE (classement ou annulation) de la réponse ; à lire sous le verrou. */
    private function courant(int $id): ?object
    {
        return DB::table('envoi_reponses_classements')->where('reponse_id', $id)->whereIn('geste', ['classement', 'annulation'])->orderByDesc('id')->first();
    }

    /** Un lien par adresse (réutilisé au rejeu) → UNE opposition, mise à l'écart sans effacement. */
    private function opposer(string $ws, string $empreinte): void
    {
        (new OpposerAdresse($this->opposition))->executer($ws, $empreinte, EnregistrerOpposition::CANAL_REPONSE);
    }

    /** @return array{0: string, 1: ?array<string, mixed>}  [état, résultat] : ok | inconnue | inconnue_injection | deja */
    public function rattacher(string $ws, string $userId, int $id, string $injection): array
    {
        return WorkspaceContext::run($ws, function () use ($ws, $userId, $id, $injection): array {
            $l = $this->ligne($ws, $id);
            if ($l === null) {
                return ['inconnue', null];
            }
            // Même règle que le déclencheur de base : l'identifiant du registre vit dans l'une OU l'autre table, pour cet espace.
            $connue = DB::table('envoi_injections_smtp')->where('workspace_id', $ws)->where('id_injection', $injection)->exists()
                || DB::table('envoi_injections_locales')->where('workspace_id', $ws)->where('id_injection', $injection)->exists();
            if (! $connue) {
                return ['inconnue_injection', null];
            }
            try {
                $ecrit = DB::transaction(function () use ($ws, $userId, $id, $injection, $l): bool {
                    $this->verrou($ws, $id);
                    if ($this->ligne($ws, $id)?->rattachee === true) {
                        return false;
                    }
                    DB::table('envoi_rattachements')->insert([
                        'workspace_id' => $ws, 'evenement_id' => $l->evenement_id, 'id_injection' => $injection,
                        'empreinte_destinataire' => $l->emp_evt, 'decide_par' => 'utilisateur:' . $userId,
                    ]);

                    return true;
                });
            } catch (QueryException) {
                $ecrit = false; // l'index unique a refusé un second rattachement
            }

            return $ecrit ? ['ok', $this->resumeDe($ws, $id) + ['resume_crm' => null]] : ['deja', null];
        });
    }

    /**
     * Fiche existante (même adresse) renvoyée telle quelle, sans modification. Sinon création par le service CRM
     * (base légale : intérêt légitime B2B, art. 14 — provenance gardée), dans une organisation DÉJÀ connue.
     *
     * @return array{0: string, 1: ?array<string, mixed>} [état, résultat] : ok | inconnue | organisation_inconnue
     */
    public function creerFiche(string $ws, int $id, string $prenom, string $nom, ?string $organisation, string $provenance): array
    {
        return WorkspaceContext::run($ws, function () use ($ws, $id, $prenom, $nom, $organisation, $provenance): array {
            $l = $this->ligne($ws, $id);
            if ($l === null) {
                return ['inconnue', null];
            }
            $email = mb_strtolower(trim(Crypt::decryptString($l->expediteur_chiffre)));

            return DB::transaction(function () use ($ws, $id, $prenom, $nom, $organisation, $provenance, $email): array {
                $this->verrou($ws, $id);
                $contactId = DB::table('contacts')->where('workspace_id', $ws)->where('email', $email)->orderBy('id')->value('id');
                if ($contactId === null) {
                    $societe = $organisation === null ? null : DB::table('companies')->where('workspace_id', $ws)
                        ->whereRaw('lower(denomination) = ?', [mb_strtolower(trim($organisation))])->orderBy('id')->value('id');
                    if ($societe === null) {
                        return ['organisation_inconnue', null];
                    }
                    $r = $this->contacts->upsert(
                        $ws,
                        (int) $societe,
                        ListeSuppression::empreinte($email),
                        null,
                        $email,
                        $prenom,
                        $nom,
                        null,
                        'legitimate_interest_b2b',
                        discoverySource: 'envoi_reponse',
                    );
                    if ($r === null) {
                        return ['organisation_inconnue', null];
                    }
                    $contactId = $r[0];
                    if ($r[1] === IngestOutcome::CREATED) {
                        DB::update(
                            'UPDATE contacts SET metadata = metadata || ?::jsonb WHERE id = ? AND workspace_id = ?',
                            [json_encode(['provenance' => mb_substr($provenance, 0, 200)], JSON_THROW_ON_ERROR), $contactId, $ws],
                        );
                    }
                }
                $courant = DB::table('envoi_reponses_classements')->where('reponse_id', $id)->where('geste', 'classement')->orderByDesc('id')->first();
                $deja = DB::table('envoi_reponses_classements')->where('reponse_id', $id)->where('geste', 'fiche')->orderByDesc('id')->value('contact_id');
                if ($courant !== null && (int) $deja !== (int) $contactId) {
                    DB::table('envoi_reponses_classements')->insert([
                        'workspace_id' => $ws, 'reponse_id' => $id, 'geste' => 'fiche', 'classement' => $courant->classement,
                        'confiance' => $courant->confiance, 'auteur' => 'humain', 'contact_id' => $contactId, 'cree_le' => now(),
                    ]);
                }

                return ['ok', $this->resumeDe($ws, $id) + ['resume_crm' => null]];
            });
        });
    }

    private function verrou(string $ws, int $id): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['envoi_reponse|' . $ws . '|' . $id]);
    }

    private static function iso(mixed $v): string
    {
        return Carbon::parse((string) $v)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
