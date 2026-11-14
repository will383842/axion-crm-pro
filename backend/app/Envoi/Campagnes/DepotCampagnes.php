<?php

namespace App\Envoi\Campagnes;

use App\Envoi\Exceptions\CampagneRefusee;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Campagnes (modèle de données ; les routes de suivi et de gestes sont dans GestesCampagne / LectureCampagnes, R09). Aucun lien avec les séquences ni les inscriptions.
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

    public const SAISIE_MAX_OCTETS = 65536;

    /** Marge côté PHP : la base fait foi (octet_length du texte jsonb) ; un écart résiduel devient un refus propre (23514). */
    public const SAISIE_MARGE_OCTETS = 1024;

    public const CIBLE_MAX_OCTETS = 2048;

    public const CIBLE_MARGE_OCTETS = 64;

    public const BOITES_MAX = 50;

    /** Codes de validation : plafond de tentatives et de codes par campagne (la base porte les mêmes valeurs). */
    public const CODE_TENTATIVES_MAX = 5;

    public const CODES_PAR_CAMPAGNE_MAX = 10;

    private const CHAMPS_MODIFIABLES = ['nom', 'cible', 'boites', 'nb_etapes', 'saisie'];

    /** Clé du verrou consultatif de l'ESPACE : toute écriture de création (brouillon, étape, code, validation) le prend d'abord. */
    public static function cleVerrouEspace(string $ws): string
    {
        return 'envoi_campagnes_espace:' . $ws;
    }

    /**
     * Exécute `$f` dans UNE transaction, sous le verrou de l'espace puis, si fournie, celui de la campagne (toujours dans cet
     * ordre : pas d'interblocage). Relâchés au COMMIT/ROLLBACK.
     *
     * @template T
     *
     * @param  \Closure(): T  $f
     * @return T
     */
    public function sousVerrou(string $ws, ?string $campagne, \Closure $f): mixed
    {
        return WorkspaceContext::run($ws, fn () => DB::transaction(function () use ($ws, $campagne, $f) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [self::cleVerrouEspace($ws)]);
            if ($campagne !== null) {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['envoi_campagne:' . $campagne]);
            }

            return $f();
        }));
    }

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
            $this->sansErreurDeMesure(fn () => DB::table('envoi_campagnes')->insert(['id' => $id, 'workspace_id' => $ws, 'cree_par' => $auteur] + $champs));

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

    /** Pose la date du prochain envoi (versionnée comme tout changement). L'appelant a vérifié statut et fenêtre. */
    public function changerDate(string $ws, string $id, int $version, \DateTimeInterface $le): int
    {
        return WorkspaceContext::run($ws, fn (): int => $this->ecrire($ws, $id, $version, ['envoi_prevu_le' => $le->format('Y-m-d H:i:sP')]));
    }

    /**
     * Change le statut et journalise (même transaction). Idempotent : une clé déjà journalisée ne rejoue rien (retourne false).
     */
    public function transitionner(string $ws, string $id, string $vers, int $version, string $auteur, string $motif, string $cleIdempotence): bool
    {
        return WorkspaceContext::run($ws, function () use ($ws, $id, $vers, $version, $auteur, $motif, $cleIdempotence): bool {
            if ($this->cleDejaJournalisee($ws, $id, $vers, $cleIdempotence)) {
                return false;
            }
            $c = $this->lire($ws, $id) ?? throw CampagneRefusee::introuvable();
            if ($c->version !== $version) {
                throw CampagneRefusee::versionPerimee();   // la version LUE doit être celle que l'appelant a lue
            }
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
                if ($e->getCode() === '23505' && $this->cleDejaJournalisee($ws, $id, $vers, $cleIdempotence)) {   // prise entre-temps : déjà appliqué
                    return false;
                }
                throw $e;
            }

            return true;
        });
    }

    /**
     * @phpstan-impure
     *
     * Vrai si la clé a déjà journalisé CETTE transition de CETTE campagne (rejeu) ; une clé prise ailleurs est un refus explicite.
     */
    private function cleDejaJournalisee(string $ws, string $id, string $vers, string $cle): bool
    {
        $ligne = DB::table('envoi_campagnes_journal')->where('workspace_id', $ws)->where('cle_idempotence', $cle)->first(['campagne_id', 'transition']);
        if ($ligne === null) {
            return false;
        }
        if ($ligne->campagne_id !== $id || ! str_ends_with($ligne->transition, '>' . $vers)) {
            throw CampagneRefusee::cleReutilisee();
        }

        return true;
    }

    /**
     * Émet un code de validation (6 chiffres) : on ne garde que son HMAC-SHA256 (clé serveur) et la version de clé.
     *
     * @return int identifiant de la ligne
     */
    public function emettreCode(string $ws, string $campagneId, string $code, int $validiteMinutes = 10): int
    {
        if (! preg_match('/^[0-9]{6}$/', $code)) {
            throw CampagneRefusee::contenu('le code compte 6 chiffres');
        }
        $empreinte = self::empreinteCode($code, $version = (int) config('envoi.cle_codes_campagne_version', 1));

        return WorkspaceContext::run($ws, function () use ($ws, $campagneId, $empreinte, $version, $validiteMinutes): int {
            $this->lire($ws, $campagneId) ?? throw CampagneRefusee::introuvable();
            try {
                return (int) DB::transaction(fn () => DB::table('envoi_campagne_codes')->insertGetId([
                    'workspace_id' => $ws, 'campagne_id' => $campagneId, 'empreinte' => $empreinte, 'cle_version' => $version,
                    'expire_le' => DB::raw('now() + make_interval(mins => ' . (int) $validiteMinutes . ')'),
                ]));
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), '10 codes au plus')) {
                    throw CampagneRefusee::contenu('10 codes au plus par campagne');
                }
                throw $e;
            }
        });
    }

    /**
     * Consomme un code : vrai UNIQUEMENT si le code est juste ET vivant (non consommé, non expiré, tentatives sous le plafond)
     * ET qu'une seule ligne a changé (UPDATE conditionnel atomique : deux consommations simultanées, une seule réussit).
     * Un mauvais code compte une tentative sur les codes vivants de la campagne.
     */
    public function consommerCode(string $ws, string $campagneId, string $code): bool
    {
        return WorkspaceContext::run($ws, function () use ($ws, $campagneId, $code): bool {
            $vivants = fn () => DB::table('envoi_campagne_codes')->where('workspace_id', $ws)->where('campagne_id', $campagneId)
                ->whereNull('consomme_le')->where('expire_le', '>', DB::raw('now()'))->where('tentatives', '<', self::CODE_TENTATIVES_MAX);
            foreach ($vivants()->get(['id', 'empreinte', 'cle_version']) as $ligne) {
                try {
                    $attendue = self::empreinteCode($code, (int) $ligne->cle_version);
                } catch (CampagneRefusee) {
                    continue;   // clé de cette version indisponible : ce code ne peut pas être vérifié
                }
                if (hash_equals($ligne->empreinte, $attendue)) {
                    return $vivants()->where('id', $ligne->id)->update(['consomme_le' => DB::raw('now()')]) === 1;
                }
            }
            $vivants()->increment('tentatives');

            return false;
        });
    }

    /** HMAC-SHA256 (hex) du code avec la clé serveur de cette version. Clé absente ou de moins de 32 caractères : porte fermée. */
    public static function empreinteCode(string $code, int $cleVersion): string
    {
        $cle = config('envoi.cles_codes_campagne')[(string) $cleVersion] ?? '';
        if (! is_string($cle) || strlen($cle) < 32) {
            throw CampagneRefusee::cleCodeAbsente();
        }

        return hash_hmac('sha256', $code, $cle);
    }

    /**
     * Taille EXACTE (octets) du texte jsonb de la base (`octet_length(x::jsonb::text)`) : une espace après chaque « : » et « , »
     * structurels. Les nombres flottants à exposant s'écrivent plus longs en base : d'où la marge ET la conversion de 23514.
     *
     * @param  array<mixed>  $valeur
     */
    public static function tailleJsonb(array $valeur): int
    {
        $json = json_encode($valeur === [] ? new \stdClass : $valeur, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $taille = strlen($json);
        $dansChaine = false;
        for ($i = 0, $n = $taille; $i < $n; $i++) {
            $c = $json[$i];
            if ($dansChaine) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === '"') {
                    $dansChaine = false;
                }
            } elseif ($c === '"') {
                $dansChaine = true;
            } elseif ($c === ':' || $c === ',') {
                $taille++;
            }
        }

        return $taille;
    }

    /**
     * Une erreur « contrainte violée » (23514) de la base devient un refus propre.
     *
     * @param  \Closure(): mixed  $ecriture
     */
    private function sansErreurDeMesure(\Closure $ecriture): mixed
    {
        try {
            return DB::transaction($ecriture);   // point de sauvegarde : l'échec n'abîme pas la transaction englobante
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '23514') {
                throw CampagneRefusee::contenu('contenu hors limites (cible 2 Ko, saisie de 64 Ko au plus, mesurés par la base)');
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $champs */
    private function ecrire(string $ws, string $id, int $version, array $champs): int
    {
        $n = $this->sansErreurDeMesure(fn () => DB::table('envoi_campagnes')->where('workspace_id', $ws)->where('id', $id)->where('version', $version)
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
            $json = json_encode($cible, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (array_diff(array_keys($cible), ['type', 'reference', 'libelle']) !== [] || count($cible) !== 3
                || ! in_array($cible['type'] ?? null, ['liste', 'audience', 'etiquette', 'carnet'], true)) {
                throw CampagneRefusee::contenu('cible = type, reference, libelle seulement');
            }
            if (! is_string($cible['reference']) || ! is_string($cible['libelle']) || trim($cible['reference']) === '' || trim($cible['libelle']) === '') {
                throw CampagneRefusee::contenu('cible : reference et libelle sont des chaînes non vides');
            }
            if (str_contains($json, '@') || self::tailleJsonb($cible) > self::CIBLE_MAX_OCTETS - self::CIBLE_MARGE_OCTETS) {
                throw CampagneRefusee::contenu('la cible ne contient aucune adresse e-mail (2 Ko au plus, mesuré comme la base)');
            }
            $out['cible'] = $json;
        }
        if (array_key_exists('boites', $c)) {
            $boites = array_values(array_map('strval', (array) $c['boites']));
            if (count($boites) > self::BOITES_MAX) {
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
            $saisie = $c['saisie'];
            if (! is_array($saisie) || ($saisie !== [] && array_is_list($saisie))) {
                throw CampagneRefusee::contenu('saisie = un objet (clés nommées), pas une liste');
            }
            if (self::tailleJsonb($saisie) > self::SAISIE_MAX_OCTETS - self::SAISIE_MARGE_OCTETS) {
                throw CampagneRefusee::contenu('saisie de 64 Ko au plus (mesurée comme la base)');
            }
            $out['saisie'] = json_encode($saisie === [] ? new \stdClass : $saisie, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        if (! $partiel && count($out) !== count(self::CHAMPS_MODIFIABLES)) {
            throw CampagneRefusee::contenu('champs obligatoires manquants');
        }

        return $out;
    }
}
