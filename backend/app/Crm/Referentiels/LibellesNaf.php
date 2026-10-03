<?php

namespace App\Crm\Referentiels;

use RuntimeException;

/**
 * Les LIBELLÉS de la NAF rév. 2, aux cinq niveaux, tels que les tables de
 * référence `naf_sections` … `naf_subclasses` les portent.
 *
 * Lot N7 (2026-10-03). La table `naf_subclasses` était VIDE en production : la
 * seule commande qui la remplissait (`naf:import`) attendait un fichier INSEE
 * jamais déposé sur le serveur. L'API ne pouvait donc pas dire « Programmation
 * informatique » pour « 62.01Z », et l'écran se rabattait sur le libellé de la
 * DIVISION (« Programmation, conseil et autres activités informatiques »).
 *
 * Source : les CSV versionnés de `resources/referentiels/` (provenance INSEE,
 * cf. LISEZMOI.md) — `naf_rev2_niveaux.csv` pour les quatre niveaux supérieurs,
 * `naf_rev2_secteurs.csv` pour les 732 sous-classes.
 *
 * ── LES DEUX ÉCRITURES D'UN CODE ────────────────────────────────────────────
 *
 * L'INSEE et `companies.naf` / `companies.naf_rev2` écrivent « 62.01Z » ; les
 * tables de référence (CHAR(5), CHAR(4), CHAR(3)) écrivent « 6201Z », « 6201 »,
 * « 620 » — sans le point. La jointure retire donc le point du côté fiche
 * (`sqlLibelleSousClasse`) ; le chargement le retire du côté CSV (`sansPoint`).
 *
 * Pure : aucune base. Les fichiers sont relus à chaque appel (le chargement est
 * une commande ponctuelle).
 */
final class LibellesNaf
{
    /** Les niveaux de `naf_rev2_niveaux.csv`, du plus haut au plus bas. */
    public const NIVEAUX_SUPERIEURS = ['section', 'division', 'groupe', 'classe'];

    /**
     * Les lignes à charger, niveau par niveau (clés : section, division,
     * groupe, classe, sous_classe), codes au format des tables.
     *
     * @return array<string, list<array{code: string, parent: string|null, label: string}>>
     */
    public static function lignes(): array
    {
        $res = ['section' => [], 'division' => [], 'groupe' => [], 'classe' => [], 'sous_classe' => []];

        foreach (self::lire('naf_rev2_niveaux.csv') as $l) {
            [$niveau, $code, $parent, $libelle] = array_pad($l, 4, '');
            if (! in_array($niveau, self::NIVEAUX_SUPERIEURS, true)) {
                throw new RuntimeException("Niveau NAF inconnu dans naf_rev2_niveaux.csv : « {$niveau} »");
            }
            $res[$niveau][] = [
                'code' => self::sansPoint($code),
                'parent' => $parent === '' ? null : self::sansPoint($parent),
                'label' => $libelle,
            ];
        }

        foreach (self::lire('naf_rev2_secteurs.csv') as $l) {
            [$code, , $libelle] = array_pad($l, 3, '');
            $code = self::sansPoint($code);
            $res['sous_classe'][] = ['code' => $code, 'parent' => substr($code, 0, 4), 'label' => $libelle];
        }

        return $res;
    }

    /** « 62.01Z » → « 6201Z », « 01.1 » → « 011 » ; une section (« J ») ne change pas. */
    public static function sansPoint(string $code): string
    {
        return strtoupper(str_replace('.', '', trim($code)));
    }

    /**
     * Expression SQL : le libellé de la sous-classe d'une fiche `companies`.
     *
     * Le code lu est `naf_rev2` (le code rév. 2 d'origine, ou celui que la table
     * de passage INSEE retient pour un code de 1993), sinon `naf`. Un code qui
     * n'a pas la forme d'une sous-classe rév. 2 (« 52.1D », « 67.01 », vide)
     * donne NULL, comme un code absent de la table.
     *
     * 🔴 Sous-requête SCALAIRE, et non `LEFT JOIN` : elle n'est évaluée que
     * pour les lignes RENDUES (après `ORDER BY … LIMIT`), ne touche ni au
     * comptage de la pagination ni au plan de la liste, et — grâce au
     * `::char(5)` — sonde la clé primaire de `naf_subclasses` (une comparaison
     * `char = text` serait résolue en `text = text` et ignorerait l'index). Le
     * `CASE` borne la conversion : `'62.01ZX'::char(5)` tronquerait en
     * « 6201Z » et trouverait un libellé faux.
     *
     * @param  string  $table  nom (ou alias) de la table `companies` dans la requête
     */
    public static function sqlLibelleSousClasse(string $table = 'companies'): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $table) !== 1) {
            throw new RuntimeException("Nom de table invalide : {$table}");
        }

        $code = "upper(replace(btrim(coalesce({$table}.naf_rev2, {$table}.naf)), '.', ''))";

        return '(SELECT naf_s.label FROM naf_subclasses naf_s WHERE naf_s.code = '
            . "CASE WHEN {$code} ~ '^[0-9]{4}[A-Z]\$' THEN {$code}::char(5) END)";
    }

    /**
     * @return list<list<string>> lignes du CSV, en-tête retiré
     */
    private static function lire(string $fichier): array
    {
        // backend/app/Crm/Referentiels → backend/resources/referentiels.
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
            throw new RuntimeException("Référentiel vide : {$chemin}");
        }

        return $lignes;
    }
}
