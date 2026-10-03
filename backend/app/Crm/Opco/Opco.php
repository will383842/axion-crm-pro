<?php

namespace App\Crm\Opco;

/**
 * LES ONZE OPCO (lot O14) et la traduction FERMÉE des libellés de la table
 * SIRET → OPCO de France compétences (« table SIRO ») vers la liste de la
 * contrainte `companies_opco_opco_check`.
 *
 * Un libellé absent de `LIBELLES_SIRO` n'est JAMAIS deviné : la ligne est
 * rejetée et comptée (`rejet_opco_inconnu`). Le libellé est d'abord réduit à
 * ses lettres et chiffres, en majuscules et sans accent (« OPCO Santé »,
 * « OPCO SANTE » et « opco-sante » donnent tous `OPCOSANTE`).
 */
final class Opco
{
    /**
     * La liste fermée, dans l'ordre de la contrainte SQL (recopiée, figée, dans
     * la migration `2026_10_05_000010_create_companies_opco_table`).
     *
     * @var list<string>
     */
    public const VALEURS = [
        'opco2i', 'afdas', 'atlas', 'uniformation', 'constructys', 'opco_ep',
        'akto', 'opcommerce', 'mobilites', 'ocapiat', 'opco_sante',
    ];

    /**
     * Libellé SIRO normalisé → valeur. Les anciens noms et les noms longs
     * officiels sont repris quand ils désignent sans ambiguïté le même OPCO
     * (Uniformation s'appelle « OPCO Cohésion sociale » depuis 2023).
     *
     * @var array<string, string>
     */
    public const LIBELLES_SIRO = [
        'OPCO2I' => 'opco2i',
        'AFDAS' => 'afdas',
        'ATLAS' => 'atlas',
        'OPCOATLAS' => 'atlas',
        'UNIFORMATION' => 'uniformation',
        'OPCOCOHESIONSOCIALE' => 'uniformation',
        'UNIFORMATIONOPCOCOHESIONSOCIALE' => 'uniformation',
        // Libellé réel du fichier siro-202606.csv (relevé le 2026-10-04 : 89 274 lignes).
        'UNIFORMATIONCOHESIONSOCIALE' => 'uniformation',
        'CONSTRUCTYS' => 'constructys',
        'OPCOCONSTRUCTYS' => 'constructys',
        'OPCOEP' => 'opco_ep',
        'OPCODESENTREPRISESDEPROXIMITE' => 'opco_ep',
        'AKTO' => 'akto',
        'OPCOAKTO' => 'akto',
        'OPCOMMERCE' => 'opcommerce',
        'LOPCOMMERCE' => 'opcommerce',
        'MOBILITES' => 'mobilites',
        'OPCOMOBILITES' => 'mobilites',
        'OCAPIAT' => 'ocapiat',
        'OPCOOCAPIAT' => 'ocapiat',
        'OPCOSANTE' => 'opco_sante',
    ];

    /** Les libellés lisibles, pour l'affichage. */
    public const LIBELLES = [
        'opco2i' => 'OPCO 2i',
        'afdas' => 'AFDAS',
        'atlas' => 'ATLAS',
        'uniformation' => 'Uniformation (OPCO Cohésion sociale)',
        'constructys' => 'Constructys',
        'opco_ep' => 'OPCO EP',
        'akto' => 'AKTO',
        'opcommerce' => "L'Opcommerce",
        'mobilites' => 'OPCO Mobilités',
        'ocapiat' => 'OCAPIAT',
        'opco_sante' => 'OPCO Santé',
    ];

    /**
     * La valeur d'un libellé SIRO, ou null s'il est inconnu (jamais deviné).
     */
    public static function depuisLibelleSiro(string $libelle): ?string
    {
        $cle = self::normaliser($libelle);

        return $cle === '' ? null : (self::LIBELLES_SIRO[$cle] ?? null);
    }

    public static function normaliser(string $libelle): string
    {
        $sansAccent = strtr(mb_strtoupper(trim($libelle), 'UTF-8'), [
            'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ç' => 'C', 'É' => 'E', 'È' => 'E',
            'Ê' => 'E', 'Ë' => 'E', 'Î' => 'I', 'Ï' => 'I', 'Ô' => 'O', 'Ö' => 'O',
            'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
        ]);

        return (string) preg_replace('/[^A-Z0-9]/', '', $sansAccent);
    }
}
