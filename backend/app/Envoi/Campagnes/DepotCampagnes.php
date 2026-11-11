<?php

namespace App\Envoi\Campagnes;

use App\Envoi\Exceptions\CampagneRefusee;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Campagnes (modèle de données seul : aucune route, aucun lien avec les séquences ni les inscriptions).
 * AUCUNE méthode n'écrit `sequence_id` : une campagne ne peut pas devenir exécutable par ce dépôt (et le rôle applicatif
 * n'a aucun droit sur cette colonne). Aucune suppression. Tout passe par WorkspaceContext (RLS) ET filtre l'espace.
 */
final class DepotCampagnes
{
    /** UNE seule table de vérité des transitions (la base porte la même matrice : un test les compare). */
    public const TRANSITIONS = [
        'brouillon' => ['a_valider'],
        'a_valider' => ['brouillon', 'programmee'],
        'programmee' => ['a_valider', 'en_cours', 'arretee'],
        'en_cours' => ['en_pause', 'terminee', 'arretee'],
        'en_pause' => ['en_cours', 'terminee', 'arretee'],
        'terminee' => [],
        'arretee' => [],
    ];

    /** Statuts qui exigent une séquence (CHECK en base). */
    public const EXIGENT_SEQUENCE = ['en_cours', 'en_pause', 'terminee', 'arretee'];

    public const CODE_TENTATIVES_MAX = 5;

    public const CODE_VALIDITE_MINUTES = 15;

    public const SAISIE_MAX_OCTETS = 65536;

    private const CHAMPS_MODIFIABLES = ['nom', 'cible', 'boites', 'nb_etapes', 'saisie'];

    public static function autorisee(string $de, string $vers): bool
    {
        return in_array($vers, self::TRANSITIONS[$de] ?? [], true);
    }

    /**
     * @param  array<string, mixed>  $cible  type, reference, libelle (jamais d'adresse e-mail)
     * @param  list<string>  $boites  identifiants de boîtes
     * @param  array<string, mixed>  $saisie  brouillon de saisie, 64 Ko au plus
     */
    public function creer(string $ws, string $nom, array $cible, array $boites, int $nbEtapes, array $saisie, string $auteur): string
    {
        $champs = $this->valider(['nom' => $nom, 'cible' => $cible, 'boites' => $boites, 'nb_etapes' => $nbEtapes, 'saisie' => $saisie]);

        return WorkspaceContext::run($ws, function () use ($ws, $champs, $auteur): string {
            $id = (string) Str::uuid();
            $this->traduire(fn () => DB::table('envoi_campagnes')->insert(['id' => $id, 'workspace_id' => $ws, 'cree_par' => $auteur] + $champs));

            return $id;
        });
    }

    public function lire(string $ws, string $id): ?\stdClass
    {
        return WorkspaceContext::run($ws, fn () => DB::table('envoi_campagnes')->where('workspace_id', $ws)->where('id', $id)->first());
    }

    /**
     * Modifie le contenu d'un BROUILLON. Verrou optimiste : `$version` doit être celle que l'appelant a lue.
     *
     * @param  array<string, mixed>  $changes  clés permises : nom, cible, boites, nb_etapes, saisie
     * @return int la nouvelle version
     */
    public function modifier(string $ws, string $id, int $version, array $changes): int
    {
        $champs = $this->valider($changes, partiel: true);

        return WorkspaceContext::run($ws, function () use ($ws, $id, $version, $champs): int {
            $c = $this->lire($ws, $id) ?? throw CampagneRefusee::introuvable();
            if ($c->statut !== 'brouillon') {
                throw CampagneRefusee::contenu("seul un brouillon se modifie (statut {$c->statut})");
            }

            return $this->ecrire($ws, $id, $version, $champs);
        });
    }

    /**
     * Change le statut et journalise (même transaction). Idempotent : une clé déjà journalisée ne rejoue rien (retourne false).
     */
    public function transitionner(string $ws, string $id, string $vers, int $version, string $auteur, string $motif, string $cleIdempotence): bool
    {
        return WorkspaceContext::run($ws, function () use ($ws, $id, $vers, $version, $auteur, $motif, $cleIdempotence): bool {
            $deja = DB::table('envoi_campagnes_journal')->where('workspace_id', $ws)->where('cle_idempotence', $cleIdempotence)->first();
            if ($deja !== null) {
                // Même clé sur une AUTRE campagne : refus explicite, jamais un « déjà fait » silencieux.
                return $deja->campagne_id === $id ? false : throw CampagneRefusee::contenu("clé d'idempotence déjà utilisée par une autre campagne");
            }
            $c = $this->lire($ws, $id) ?? throw CampagneRefusee::introuvable();
            if (! self::autorisee($c->statut, $vers)) {
                throw CampagneRefusee::transition($c->statut, $vers);
            }
            if (in_array($vers, self::EXIGENT_SEQUENCE, true) && $c->sequence_id === null) {
                throw CampagneRefusee::sansSequence($vers);
            }
            try {
                DB::transaction(function () use ($ws, $id, $vers, $version, $auteur, $motif, $cleIdempotence, $c): void {
                    $this->ecrire($ws, $id, $version, ['statut' => $vers]);
                    DB::table('envoi_campagnes_journal')->insert([
                        'workspace_id' => $ws, 'campagne_id' => $id, 'transition' => $c->statut . '>' . $vers,
                        'auteur' => $auteur, 'motif' => $motif, 'cle_idempotence' => $cleIdempotence,
                    ]);
                });
            } catch (QueryException $e) {
                if ($e->getCode() === '23505') {   // clé d'idempotence prise entre-temps : déjà appliqué
                    return false;
                }
                throw $e;
            }

            return true;
        });
    }

    /** Empreinte d'un code : HMAC-SHA256 avec la clé serveur (jamais un SHA nu : 10^6 codes se retrouvent en une seconde). */
    public static function empreinteCode(string $code, int $cleVersion = 1): string
    {
        $cle = (string) config('envoi.cle_empreinte');
        if ($cle === '' || $cleVersion !== 1) {
            throw CampagneRefusee::contenu('clé d\'empreinte absente ou version inconnue');
        }

        return hash_hmac('sha256', $code, $cle);
    }

    /** Enregistre l'EMPREINTE d'un code (jamais le code). La base plafonne à 10 codes par campagne. */
    public function emettreCode(string $ws, string $id, string $code): void
    {
        WorkspaceContext::run($ws, function () use ($ws, $id, $code): void {
            $this->lire($ws, $id) ?? throw CampagneRefusee::introuvable();
            $this->traduire(fn () => DB::table('envoi_campagne_codes')->insert([
                'workspace_id' => $ws, 'campagne_id' => $id, 'empreinte' => self::empreinteCode($code), 'cle_version' => 1,
                'expire_le' => now()->addMinutes(self::CODE_VALIDITE_MINUTES),
            ]));
        });
    }

    /**
     * Consomme un code, de façon atomique (une seule ligne change) : valide, non expiré, moins de 5 tentatives, non déjà consommé.
     * Un code faux compte une tentative sur le dernier code de la campagne ; retourne false sans rien révéler de plus.
     */
    public function consommerCode(string $ws, string $id, string $code): bool
    {
        return WorkspaceContext::run($ws, function () use ($ws, $id, $code): bool {
            $actif = fn () => DB::table('envoi_campagne_codes')->where('workspace_id', $ws)->where('campagne_id', $id)
                ->whereNull('consomme_le')->where('expire_le', '>', now())->where('tentatives', '<', self::CODE_TENTATIVES_MAX);
            $n = $actif()->where('empreinte', self::empreinteCode($code))->update(['consomme_le' => now()]);
            if ($n === 1) {
                return true;
            }
            $dernier = $actif()->orderByDesc('id')->value('id');
            if ($dernier !== null) {
                DB::table('envoi_campagne_codes')->where('id', $dernier)->increment('tentatives');
            }

            return false;
        });
    }

    /** Une contrainte de la base (23514) devient un refus propre : PHP et jsonb ne mesurent pas le même texte JSON. */
    private function traduire(\Closure $f): mixed
    {
        try {
            return DB::transaction(static fn (): mixed => $f());   // point de sauvegarde : un refus de la base n'abandonne pas la transaction englobante
        } catch (QueryException $e) {
            throw $e->getCode() === '23514' ? CampagneRefusee::contenu('contrainte de la base violée (taille ou forme)') : $e;
        }
    }

    /** @param array<string, mixed> $champs */
    private function ecrire(string $ws, string $id, int $version, array $champs): int
    {
        $n = $this->traduire(fn () => DB::table('envoi_campagnes')->where('workspace_id', $ws)->where('id', $id)->where('version', $version)
            ->update($champs + ['version' => $version + 1, 'maj_le' => now()]));
        if ($n !== 1) {
            throw CampagneRefusee::versionPerimee();
        }

        return $version + 1;
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed> colonnes prêtes à écrire (JSON encodé)
     */
    private function valider(array $c, bool $partiel = false): array
    {
        $inconnus = array_diff(array_keys($c), self::CHAMPS_MODIFIABLES);
        if ($inconnus !== []) {
            throw CampagneRefusee::contenu('champ non modifiable : ' . implode(', ', $inconnus));
        }
        $out = [];
        if (array_key_exists('nom', $c)) {
            $nom = trim((string) $c['nom']);
            if ($nom === '' || mb_strlen($nom) > 200) {
                throw CampagneRefusee::contenu('nom de 1 à 200 caractères');
            }
            $out['nom'] = $nom;
        }
        if (array_key_exists('cible', $c)) {
            $cible = (array) $c['cible'];
            if (! is_string($cible['reference'] ?? null) || ! is_string($cible['libelle'] ?? null)) {
                throw CampagneRefusee::contenu('reference et libelle sont des chaînes');
            }
            $json = json_encode($cible, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (array_diff(array_keys($cible), ['type', 'reference', 'libelle']) !== [] || count($cible) !== 3
                || ! in_array($cible['type'] ?? null, ['liste', 'audience', 'etiquette', 'carnet'], true)) {
                throw CampagneRefusee::contenu('cible = type, reference, libelle seulement');
            }
            if (str_contains($json, '@') || strlen($json) > 2000) {
                throw CampagneRefusee::contenu('la cible ne contient aucune adresse e-mail (2 Ko au plus)');
            }
            $out['cible'] = $json;
        }
        if (array_key_exists('boites', $c)) {
            $boites = array_values(array_map('strval', (array) $c['boites']));
            if (count($boites) > 50) {
                throw CampagneRefusee::contenu('50 boîtes au plus');
            }
            foreach ($boites as $b) {
                if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $b)) {
                    throw CampagneRefusee::contenu('boîtes = identifiants UUID');
                }
            }
            $out['boites'] = '{' . implode(',', $boites) . '}';
        }
        if (array_key_exists('nb_etapes', $c)) {
            if ((int) $c['nb_etapes'] < 1 || (int) $c['nb_etapes'] > 3) {
                throw CampagneRefusee::contenu('1 à 3 étapes');
            }
            $out['nb_etapes'] = (int) $c['nb_etapes'];
        }
        if (array_key_exists('saisie', $c)) {
            if (! is_array($c['saisie']) || ($c['saisie'] !== [] && array_is_list($c['saisie']))) {
                throw CampagneRefusee::contenu('saisie = objet (pas une liste)');
            }
            $json = json_encode((array) $c['saisie'] ?: new \stdClass, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (strlen($json) > self::SAISIE_MAX_OCTETS) {
                throw CampagneRefusee::contenu('saisie de 64 Ko au plus');
            }
            $out['saisie'] = $json;
        }
        if (! $partiel && count($out) !== count(self::CHAMPS_MODIFIABLES)) {
            throw CampagneRefusee::contenu('champs obligatoires manquants');
        }

        return $out;
    }
}
