<?php

namespace App\Crm\Referentiels;

use App\Crm\Taxonomy;
use RuntimeException;

/**
 * LE classifieur « code d'activité → secteur » — il n'en existe pas d'autre.
 *
 * Avant le 2026-09-28 : `SectorClassifier` (collecte INSEE, 14 secteurs) et
 * `AutoClassifierService::NAF_TO_SECTOR` (enrichissement, 20 secteurs, qui
 * écrasait le premier) lisaient tous deux les DEUX premiers chiffres du code —
 * donc lisaient un code de la NAF rév. 1 (1993, « 52.1D ») comme s'il était de
 * la rév. 2. Mesuré en production : 472 785 fiches ainsi mal classées (ancien
 * 52 = commerce de détail rangé en « transport », ancien 85 = santé rangé en
 * « enseignement », ancien 64 = postes et télécoms rangé en « finance »…).
 *
 * Ici, la nomenclature est d'abord RECONNUE à la forme du code, puis traduite
 * par la table de passage officielle de l'INSEE (`resources/referentiels/`,
 * cf. LISEZMOI.md) :
 *
 *   NN.NNL  (62.01Z) → NAF rév. 2 : table des 732 sous-classes ;
 *   NN.NL   (52.1D)  → NAF rév. 1 : table de passage rév. 1 → rév. 2, sinon
 *                      repli par groupe NN.N, sinon par division NN ;
 *   NN.NN   (67.01)  → NAP 1973 (sans lettre) : table des 650 postes ;
 *   vide, ou 00…     → aucune activité connue : `non_classe`.
 *
 * Les formes sans point (« 6201Z », « 521D », « 6701 ») sont acceptées : le
 * point est réinséré avant la lecture.
 *
 * Pure : aucune base, aucun réseau. Les tables sont lues une fois par
 * processus (≈ 2 100 lignes, quelques millisecondes).
 */
final class NomenclatureNaf
{
    public const REV2 = 'naf_rev2';

    public const REV1 = 'naf_rev1';

    public const NAP = 'nap_1973';

    public const INCONNUE = 'inconnue';

    /** @var array<string, string>|null code rév. 2 => secteur */
    private static ?array $rev2 = null;

    /** @var array<string, array{0: string, 1: string}>|null code rév. 1 => [code rév. 2, secteur] */
    private static ?array $rev1 = null;

    /** @var array<string, string>|null groupe rév. 1 (NN.N) => secteur */
    private static ?array $rev1Groupes = null;

    /** @var array<string, string>|null division rév. 1 (NN) => secteur */
    private static ?array $rev1Divisions = null;

    /** @var array<string, string>|null poste NAP (NN.NN) => secteur */
    private static ?array $nap = null;

    /** @var array<string, string|null>|null groupe rév. 2 => secteur s'il est UNIQUE dans le groupe */
    private static ?array $rev2Groupes = null;

    /** @var array<string, string|null>|null division rév. 2 => secteur s'il est UNIQUE dans la division */
    private static ?array $rev2Divisions = null;

    /**
     * Classe un code d'activité tel qu'il est stocké dans `companies.naf`.
     */
    public static function classer(?string $naf): ClassementNaf
    {
        self::charger();

        $code = strtoupper(trim((string) $naf));
        if ($code === '') {
            return new ClassementNaf(null, null, Taxonomy::SECTEUR_NON_CLASSE, 'vide');
        }

        // Formes sans point : on réinsère le point après la division.
        if (preg_match('/^\d{3,4}[A-Z]?$/', $code) === 1) {
            $code = substr($code, 0, 2) . '.' . substr($code, 2);
        }

        // « 00.00Z », « 00.0Z », « 00.98 » : codes d'attente, aucune activité.
        if (str_starts_with($code, '00')) {
            return new ClassementNaf(self::INCONNUE, null, Taxonomy::SECTEUR_NON_CLASSE, 'sans_activite');
        }

        if (preg_match('/^\d\d\.\d\d[A-Z]$/', $code) === 1) {
            return self::classerRev2($code);
        }
        if (preg_match('/^\d\d\.\d[A-Z]$/', $code) === 1) {
            return self::classerRev1($code);
        }
        if (preg_match('/^\d\d\.\d\d$/', $code) === 1) {
            $secteur = self::$nap[$code] ?? null;

            return $secteur === null
                ? new ClassementNaf(self::NAP, null, Taxonomy::SECTEUR_NON_CLASSE, 'nap_inconnu')
                : new ClassementNaf(self::NAP, null, $secteur, 'nap_table');
        }

        return new ClassementNaf(self::INCONNUE, null, Taxonomy::SECTEUR_NON_CLASSE, 'forme_inconnue');
    }

    /** Raccourci : le seul secteur du code. */
    public static function secteur(?string $naf): string
    {
        return self::classer($naf)->secteur;
    }

    /**
     * Toutes les clés de secteur citées par les tables de passage — pour la
     * garde qui vérifie qu'aucune n'échappe au référentiel.
     *
     * @return list<string>
     */
    public static function secteursCites(): array
    {
        self::charger();
        $cites = [];
        foreach ([self::$rev2, self::$rev1Groupes, self::$rev1Divisions, self::$nap] as $table) {
            foreach ($table ?? [] as $secteur) {
                $cites[$secteur] = true;
            }
        }
        foreach (self::$rev1 ?? [] as $lien) {
            $cites[$lien[1]] = true;
        }

        return array_map(static fn (int|string $s): string => (string) $s, array_keys($cites));
    }

    /**
     * Clés et libellés de `secteurs.csv`, dans l'ordre du fichier — pour la
     * garde qui le compare à `Taxonomy::SECTEURS`.
     *
     * @return array<string, string>
     */
    public static function secteursDuFichier(): array
    {
        $sortie = [];
        foreach (self::lire('secteurs.csv') as $ligne) {
            $sortie[$ligne[0]] = $ligne[1];
        }

        return $sortie;
    }

    private static function classerRev2(string $code): ClassementNaf
    {
        $secteur = self::$rev2[$code] ?? null;
        if ($secteur !== null) {
            return new ClassementNaf(self::REV2, $code, $secteur, 'rev2_table');
        }

        // Code de forme rév. 2 mais absent de la liste officielle (saisie
        // fautive, sous-classe créée après 2008) : on ne devine que si le
        // groupe, ou à défaut la division, ne mène qu'à UN secteur.
        $parGroupe = self::$rev2Groupes[substr($code, 0, 4)] ?? null;
        if ($parGroupe !== null) {
            return new ClassementNaf(self::REV2, null, $parGroupe, 'rev2_groupe');
        }
        $parDivision = self::$rev2Divisions[substr($code, 0, 2)] ?? null;
        if ($parDivision !== null) {
            return new ClassementNaf(self::REV2, null, $parDivision, 'rev2_division');
        }

        return new ClassementNaf(self::REV2, null, Taxonomy::SECTEUR_NON_CLASSE, 'rev2_inconnu');
    }

    private static function classerRev1(string $code): ClassementNaf
    {
        $lien = self::$rev1[$code] ?? null;
        if ($lien !== null) {
            return new ClassementNaf(self::REV1, $lien[0], $lien[1], 'rev1_table');
        }

        // Codes de la révision 2003 absents de la table de passage (ex. 72.2Z,
        // 51.6G) : secteur majoritaire des liens RETENUS du groupe, puis
        // de la division (cf. resources/referentiels/LISEZMOI.md). Aucun
        // code rév. 2 n'est inventé dans ce cas.
        $parGroupe = self::$rev1Groupes[substr($code, 0, 4)] ?? null;
        if ($parGroupe !== null) {
            return new ClassementNaf(self::REV1, null, $parGroupe, 'rev1_groupe');
        }
        $parDivision = self::$rev1Divisions[substr($code, 0, 2)] ?? null;
        if ($parDivision !== null) {
            return new ClassementNaf(self::REV1, null, $parDivision, 'rev1_division');
        }

        return new ClassementNaf(self::REV1, null, Taxonomy::SECTEUR_NON_CLASSE, 'rev1_inconnu');
    }

    private static function charger(): void
    {
        if (self::$rev2 !== null) {
            return;
        }

        $rev2 = [];
        $groupes = [];
        $divisions = [];
        foreach (self::lire('naf_rev2_secteurs.csv') as $l) {
            $rev2[$l[0]] = $l[1];
            $groupes[substr($l[0], 0, 4)][$l[1]] = true;
            $divisions[substr($l[0], 0, 2)][$l[1]] = true;
        }
        $rev1 = [];
        foreach (self::lire('naf_rev1_vers_rev2.csv') as $l) {
            $rev1[$l[0]] = [$l[1], $l[2]];
        }
        $rev1Groupes = [];
        foreach (self::lire('naf_rev1_groupes.csv') as $l) {
            $rev1Groupes[$l[0]] = $l[1];
        }
        $rev1Divisions = [];
        foreach (self::lire('naf_rev1_divisions.csv') as $l) {
            $rev1Divisions[$l[0]] = $l[1];
        }
        $nap = [];
        foreach (self::lire('nap600_secteurs.csv') as $l) {
            $nap[$l[0]] = $l[1];
        }

        self::$rev2Groupes = array_map(self::secteurUnique(...), $groupes);
        self::$rev2Divisions = array_map(self::secteurUnique(...), $divisions);
        self::$rev1 = $rev1;
        self::$rev1Groupes = $rev1Groupes;
        self::$rev1Divisions = $rev1Divisions;
        self::$nap = $nap;
        // En dernier : c'est lui qui dit « chargé ».
        self::$rev2 = $rev2;
    }

    /**
     * Le secteur d'un groupe (ou d'une division) s'il est le MÊME pour toutes
     * ses sous-classes ; null sinon — on ne devine pas entre deux secteurs.
     *
     * @param  array<string, true>  $secteurs
     */
    private static function secteurUnique(array $secteurs): ?string
    {
        return count($secteurs) === 1 ? (string) array_key_first($secteurs) : null;
    }

    /**
     * Lignes d'un CSV du référentiel, en-tête retiré.
     *
     * @return list<list<string>>
     */
    private static function lire(string $fichier): array
    {
        $chemin = self::dossier() . '/' . $fichier;
        $flux = @fopen($chemin, 'rb');
        if ($flux === false) {
            // Jamais de repli silencieux : sans table, TOUTES les fiches
            // tomberaient en `non_classe`, et un reclassement de masse
            // écraserait 4,3 M de secteurs justes.
            throw new RuntimeException("Référentiel illisible : {$chemin}");
        }

        $lignes = [];
        try {
            $entete = true;
            while (($l = fgetcsv($flux, 0, ',', '"', '')) !== false) {
                if ($entete) {
                    $entete = false;

                    continue;
                }
                if ($l === [null] || count($l) < 2) {
                    continue;
                }
                $lignes[] = array_map(static fn (?string $v): string => trim((string) $v), $l);
            }
        } finally {
            fclose($flux);
        }

        if ($lignes === []) {
            throw new RuntimeException("Référentiel vide : {$chemin}");
        }

        return $lignes;
    }

    private static function dossier(): string
    {
        // backend/app/Crm/Referentiels → backend/resources/referentiels.
        // Pas de `resource_path()` : la classe reste utilisable sans
        // application démarrée (tests unitaires, scripts).
        return dirname(__DIR__, 3) . '/resources/referentiels';
    }
}
