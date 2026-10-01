<?php

namespace App\Crm\Presse;

use App\Crm\Taxonomy;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * LES DOUBLONS DE LA PRESSE — une seule définition (constat en production du
 * 2026-10-01, après l'harmonisation #264).
 *
 * L'harmonisation a créé UNE FICHE PAR LIGNE `media` sans fiche : un même
 * titre présent dans plusieurs sources (ARCOM et kit presse, Wikidata et kit
 * presse…) a donc plusieurs fiches. `crm:presse:importer` ne sait plus
 * laquelle rejoindre (`rapprochement_ambigu`). `crm:presse:doublons` les
 * regroupe ; cette classe DÉCIDE, la commande et `FusionFiches` lisent la base.
 *
 * ── Le groupe ───────────────────────────────────────────────────────────
 * Une CLÉ = (nom normalisé de la ligne `media`, par `normalize_name` — la
 * même normalisation que l'importeur et l'harmoniseur ; FAMILLE de son type).
 * Deux familles ne se mélangent jamais : télévision (`tv`, `tv_emission`),
 * radio, presse écrite (`presse_*`), agence, web (`portail_web`, `blog`). La
 * production audiovisuelle n'est pas de la presse : jamais groupée.
 *
 * ── Le jugement d'une paire, pour UNE clé (`juger`) ─────────────────────
 *  1. ÉDITIONS — les deux fiches ont un département, et aucun en commun
 *     (La Dépêche 31 / La Dépêche 81) : deux éditions distinctes, PAS un
 *     doublon. Jamais fusionnées, jamais proposées.
 *  2. STRICTE — et seulement alors fusion automatique (annulable) :
 *     - aucune des deux n'a de SIREN (décision de Will du 29-30/09 : un
 *       journal n'est JAMAIS fusionné d'office avec la fiche de son éditeur) ;
 *     - les deux ont la relation `presse_media` ;
 *     - aucune relation saisie à la main (`relation_saisie_manuelle_at`) ;
 *     - le MÊME département, ou toutes deux sans département (un titre sans
 *       département n'absorbe jamais une édition : relecture A09 de #276) ;
 *     - le MÊME type exact (une émission n'est pas sa chaîne) ;
 *     - aucune adresse contradictoire : si les deux en ont (adresse générique
 *       de la fiche, adresse de rédaction des lignes `media` de la clé), ce
 *       sont les mêmes.
 *  3. À VÉRIFIER — tout le reste (une fiche à SIREN, deux SIREN différents,
 *     adresses contradictoires, émission et chaîne…) : la file « Doublons à
 *     vérifier » (motif `presse_homonyme`), jamais fusionné sans un humain.
 *
 * ── La fiche gardée (`meilleure`) ───────────────────────────────────────
 * La source la plus fiable de ses lignes de la clé (registres ARCOM / CPPAP /
 * SPEL, puis Wikidata, puis kit presse, puis liste presse, puis extraction
 * NAF), puis le plus de personnes vivantes, puis le plus petit identifiant.
 *
 * Aucune donnée nominative ne sort d'ici : des identifiants, des compteurs.
 */
final class DoublonsPresse
{
    public const STRICTE = 'stricte';

    public const EDITIONS = 'editions';

    public const A_VERIFIER = 'a_verifier';

    /**
     * `media.media_type` => famille. La production audiovisuelle n'y est pas :
     * elle n'est jamais groupée.
     *
     * @var array<string, string>
     */
    public const FAMILLES = [
        'presse_quotidien' => 'ecrite',
        'presse_hebdo' => 'ecrite',
        'presse_mensuel' => 'ecrite',
        'presse_revue' => 'ecrite',
        'presse_journal' => 'ecrite',
        'presse_autre' => 'ecrite',
        'radio' => 'radio',
        'tv' => 'tv',
        'tv_emission' => 'tv',
        'agence_presse' => 'agence',
        'portail_web' => 'web',
        'blog' => 'web',
    ];

    /**
     * Fiabilité d'une source de `media.source` (la plus haute l'emporte pour
     * choisir la fiche gardée). Une source absente de la liste vaut 0.
     *
     * @var array<string, int>
     */
    public const RANG_SOURCES = [
        'arcom' => 5,
        'cppap' => 5,
        'spel' => 5,
        'wikidata' => 4,
        'press-kit' => 3,
        'liste-presse' => 2,
        'naf-extract' => 1,
    ];

    /** SQL : la famille du type `$colonne` (NULL hors presse). Aucun argument utilisateur. */
    public static function familleSql(string $colonne): string
    {
        $cas = [];
        foreach (self::FAMILLES as $type => $famille) {
            $cas[] = "WHEN '{$type}' THEN '{$famille}'";
        }

        return "(CASE {$colonne} " . implode(' ', $cas) . ' END)';
    }

    /**
     * Le jugement d'une paire pour une clé — règle PURE.
     *
     * @param  array{id: int, siren: ?string, presse: bool, manuelle: bool, departements: list<string>, types: list<string>, emails: list<string>, rang: int, contacts: int}  $a
     * @param  array{id: int, siren: ?string, presse: bool, manuelle: bool, departements: list<string>, types: list<string>, emails: list<string>, rang: int, contacts: int}  $b
     */
    public static function juger(array $a, array $b): string
    {
        $da = $a['departements'];
        $db = $b['departements'];
        if ($da !== [] && $db !== [] && array_intersect($da, $db) === []) {
            return self::EDITIONS;
        }
        if ($a['siren'] !== null || $b['siren'] !== null) {
            return self::A_VERIFIER;
        }
        if (! $a['presse'] || ! $b['presse'] || $a['manuelle'] || $b['manuelle']) {
            return self::A_VERIFIER;
        }
        // Mêmes départements, ou tous deux sans : un titre sans département
        // (national ?) n'absorbe jamais une édition sans un humain.
        if (count($da) > 1 || count($db) > 1 || $da !== $db) {
            return self::A_VERIFIER;
        }
        if (count($a['types']) !== 1 || $a['types'] !== $b['types']) {
            return self::A_VERIFIER;
        }
        if ($a['emails'] !== [] && $b['emails'] !== [] && $a['emails'] !== $b['emails']) {
            return self::A_VERIFIER;
        }

        return self::STRICTE;
    }

    /**
     * La fiche à garder parmi ces profils.
     *
     * @param  non-empty-list<array{id: int, rang: int, contacts: int}>  $profils
     */
    public static function meilleure(array $profils): int
    {
        usort($profils, static fn (array $x, array $y): int => [$y['rang'], $y['contacts'], $x['id']] <=> [$x['rang'], $x['contacts'], $y['id']]);

        return $profils[0]['id'];
    }

    /**
     * Le profil de la fiche gardée après l'absorption de l'autre (ce que la
     * fusion lui rattache : lignes `media`, personnes, adresse générique).
     *
     * @param  array{id: int, siren: ?string, presse: bool, manuelle: bool, departements: list<string>, types: list<string>, emails: list<string>, rang: int, contacts: int}  $garde
     * @param  array{id: int, siren: ?string, presse: bool, manuelle: bool, departements: list<string>, types: list<string>, emails: list<string>, rang: int, contacts: int}  $absorbee
     * @return array{id: int, siren: ?string, presse: bool, manuelle: bool, departements: list<string>, types: list<string>, emails: list<string>, rang: int, contacts: int}
     */
    public static function apresAbsorption(array $garde, array $absorbee): array
    {
        foreach (['departements', 'types', 'emails'] as $cle) {
            $garde[$cle] = self::ensemble(array_merge($garde[$cle], $absorbee[$cle]));
        }
        $garde['rang'] = max($garde['rang'], $absorbee['rang']);
        $garde['contacts'] += $absorbee['contacts'];

        return $garde;
    }

    /**
     * Les profils de ces fiches POUR UNE CLÉ, lus en base (dans le contexte
     * de l'espace). Une fiche sans ligne vivante de la clé est absente.
     *
     * @param  list<int>  $ids
     * @return array<int, array{id: int, siren: ?string, presse: bool, manuelle: bool, departements: list<string>, types: list<string>, emails: list<string>, rang: int, contacts: int}>
     */
    public static function profils(string $ws, array $ids, string $nom, string $famille): array
    {
        if ($ids === []) {
            return [];
        }
        $tableau = '{' . implode(',', array_map('intval', $ids)) . '}';
        $lignes = DB::select(
            'SELECT dp_m.company_id, dp_m.media_type, dp_m.source,
                    upper(btrim(dp_m.department_code)) AS departement,
                    lower(btrim(dp_m.email)) AS email
             FROM media dp_m
             WHERE dp_m.workspace_id = ? AND dp_m.company_id = ANY(?::bigint[]) AND dp_m.deleted_at IS NULL
               AND normalize_name(dp_m.name) = ? AND ' . self::familleSql('dp_m.media_type') . ' = ?',
            [$ws, $tableau, $nom, $famille],
        );
        $parFiche = [];
        foreach ($lignes as $l) {
            if ($l instanceof stdClass) {
                $parFiche[(int) $l->company_id][] = $l;
            }
        }
        if ($parFiche === []) {
            return [];
        }

        $fiches = DB::select(
            'SELECT dp_c.id, dp_c.siren, dp_c.relation_type, dp_c.relation_saisie_manuelle_at IS NOT NULL AS manuelle,
                    upper(btrim(dp_c.department_code)) AS departement, lower(btrim(dp_c.email_generic)) AS email,
                    (SELECT count(*) FROM contacts dp_ct WHERE dp_ct.workspace_id = dp_c.workspace_id
                        AND dp_ct.company_id = dp_c.id AND dp_ct.deleted_at IS NULL) AS contacts
             FROM companies dp_c
             WHERE dp_c.workspace_id = ? AND dp_c.id = ANY(?::bigint[]) AND dp_c.deleted_at IS NULL',
            [$ws, '{' . implode(',', array_keys($parFiche)) . '}'],
        );

        $profils = [];
        foreach ($fiches as $f) {
            if (! $f instanceof stdClass) {
                continue;
            }
            $id = (int) $f->id;
            $siren = self::texte($f->siren);
            $departements = [];
            $types = [];
            $emails = [];
            $rang = 0;
            foreach ($parFiche[$id] ?? [] as $m) {
                $departements[] = self::texte($m->departement);
                $types[] = (string) $m->media_type;
                $emails[] = self::texte($m->email);
                $rang = max($rang, self::RANG_SOURCES[(string) $m->source] ?? 0);
            }
            $departements = self::ensemble($departements);
            // Faute de département sur ses lignes, celui de la fiche — sauf
            // pour une fiche à SIREN (celui du siège de l'éditeur, pas du titre).
            if ($departements === [] && $siren === null) {
                $departements = self::ensemble([self::texte($f->departement)]);
            }
            $emails[] = self::texte($f->email);

            $profils[$id] = [
                'id' => $id,
                'siren' => $siren,
                'presse' => $f->relation_type === QualificationPresse::RELATION,
                'manuelle' => (bool) $f->manuelle,
                'departements' => $departements,
                'types' => self::ensemble($types),
                'emails' => self::ensemble($emails),
                'rang' => $rang,
                'contacts' => (int) $f->contacts,
            ];
        }
        ksort($profils);

        return $profils;
    }

    /**
     * La paire est-elle STRICTE sur les données du moment ? Toutes les clés
     * que les deux fiches partagent (au moins une) doivent l'être. Relu par
     * `FusionFiches` dans la transaction de la fusion, les deux fiches
     * verrouillées : la décision de la commande ne suffit jamais.
     */
    public static function paireStricte(string $ws, int $gardeId, int $absorbeeId): bool
    {
        $famille = self::familleSql('dp_k.media_type');
        $cles = DB::select(
            "SELECT normalize_name(dp_k.name) AS nom, {$famille} AS famille
             FROM media dp_k
             WHERE dp_k.workspace_id = ? AND dp_k.company_id = ? AND dp_k.deleted_at IS NULL AND {$famille} IS NOT NULL
             INTERSECT
             SELECT normalize_name(dp_k.name), {$famille}
             FROM media dp_k
             WHERE dp_k.workspace_id = ? AND dp_k.company_id = ? AND dp_k.deleted_at IS NULL AND {$famille} IS NOT NULL",
            [$ws, $gardeId, $ws, $absorbeeId],
        );
        if ($cles === []) {
            return false;
        }
        foreach ($cles as $c) {
            if (! $c instanceof stdClass || $c->nom === null) {
                return false;
            }
            $p = self::profils($ws, [$gardeId, $absorbeeId], (string) $c->nom, (string) $c->famille);
            if (! isset($p[$gardeId], $p[$absorbeeId]) || self::juger($p[$gardeId], $p[$absorbeeId]) !== self::STRICTE) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<?string>  $valeurs
     * @return list<string>
     */
    private static function ensemble(array $valeurs): array
    {
        $v = array_values(array_unique(array_filter($valeurs, static fn (?string $x): bool => $x !== null && $x !== '')));
        sort($v);

        return $v;
    }

    private static function texte(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }

    /** Les types groupés (garde : chaque type de presse a sa famille, sauf la production). */
    public static function typesCouverts(): bool
    {
        return array_values(array_diff(array_keys(Taxonomy::MEDIA_TYPE_VERS_ETIQUETTE), array_keys(self::FAMILLES))) === ['production_audiovisuelle'];
    }
}
