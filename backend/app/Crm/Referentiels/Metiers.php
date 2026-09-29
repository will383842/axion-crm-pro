<?php

namespace App\Crm\Referentiels;

use RuntimeException;

/**
 * LE MÉTIER d'une organisation, lu dans sa sous-classe NAF rév. 2 (chantier 2,
 * 2026-09-29).
 *
 * Le secteur (`Taxonomy::SECTEURS`, 31 valeurs) est trop large pour viser « les
 * experts-comptables » ou « les plombiers » : le métier est la maille fine, que
 * Will lit sans connaître la NAF. Il n'est PAS une colonne de `companies` : il
 * vit dans l'étiquette automatique `metier-<clé>`, posée par la même synchro
 * que `sector-`/`size-`/`region-` (`EtiquettesClassement`).
 *
 * Deux fichiers versionnés, construits par `resources/referentiels/construire_metiers.py`
 * (provenance dans `LISEZMOI.md`) :
 *   - `metiers.csv` : la liste des métiers et leur libellé, dans l'ordre de l'écran ;
 *   - `naf_rev2_metiers.csv` : sous-classe NAF rév. 2 → métier.
 *
 * Une sous-classe absente de la table n'a PAS de métier : on n'invente jamais
 * un métier (pas de repli par groupe ni par division, contrairement au secteur).
 * Le calcul part de `companies.naf_rev2` — le code rév. 2 d'origine, ou celui
 * que la table officielle INSEE donne pour un code de 1993 — et jamais d'un code
 * que la nomenclature n'a pas su convertir.
 */
final class Metiers
{
    /** @var array<string, string>|null clé => libellé, dans l'ordre de l'écran */
    private static ?array $liste = null;

    /** @var array<string, string> sous-classe NAF rév. 2 => clé de métier */
    private static array $parCode = [];

    /**
     * Les métiers, clé => libellé.
     *
     * @return array<string, string>
     */
    public static function liste(): array
    {
        self::charger();

        return self::$liste ?? [];
    }

    public static function existe(string $cle): bool
    {
        return array_key_exists($cle, self::liste());
    }

    public static function libelle(string $cle): string
    {
        return self::liste()[$cle] ?? $cle;
    }

    /**
     * Le métier d'une sous-classe NAF rév. 2 (`62.01Z`), ou null si la
     * sous-classe n'en a pas — ou si le code est absent, vide, ou n'est pas
     * un code rév. 2.
     */
    public static function pourNafRev2(?string $nafRev2): ?string
    {
        $code = strtoupper(trim((string) $nafRev2));
        if (preg_match('/^\d{2}\.\d{2}[A-Z]$/', $code) !== 1) {
            return null;
        }
        self::charger();

        return self::$parCode[$code] ?? null;
    }

    /**
     * Les sous-classes NAF rév. 2 d'un métier.
     *
     * @return list<string>
     */
    public static function sousClasses(string $cle): array
    {
        self::charger();

        return array_keys(array_filter(self::$parCode, static fn (string $m): bool => $m === $cle));
    }

    /**
     * La table complète, sous-classe => métier (gardes, bilan).
     *
     * @return array<string, string>
     */
    public static function table(): array
    {
        self::charger();

        return self::$parCode;
    }

    private static function charger(): void
    {
        if (self::$liste !== null) {
            return;
        }

        $liste = [];
        foreach (self::lire('metiers.csv') as $l) {
            $liste[$l[0]] = $l[1];
        }
        $parCode = [];
        foreach (self::lire('naf_rev2_metiers.csv') as $l) {
            if (! array_key_exists($l[1], $liste)) {
                // Jamais d'étiquette `metier-…` dont personne ne connaît le
                // libellé : une table incohérente arrête tout.
                throw new RuntimeException("Métier inconnu dans naf_rev2_metiers.csv : {$l[1]}");
            }
            $parCode[$l[0]] = $l[1];
        }

        self::$parCode = $parCode;
        // En dernier : c'est lui qui dit « chargé ».
        self::$liste = $liste;
    }

    /**
     * Lignes d'un CSV du référentiel, en-tête retiré.
     *
     * @return list<list<string>>
     */
    private static function lire(string $fichier): array
    {
        $chemin = dirname(__DIR__, 3) . '/resources/referentiels/' . $fichier;
        $flux = @fopen($chemin, 'rb');
        if ($flux === false) {
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
            // Sans table, toutes les étiquettes `metier-` seraient retirées.
            throw new RuntimeException("Référentiel vide : {$chemin}");
        }

        return $lignes;
    }
}
