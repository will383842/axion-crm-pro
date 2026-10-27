<?php

namespace App\Crm\Sante;

/**
 * Libellé de secours d'une catégorie FINESS, utilisé seulement si `categorie_libelle` du CSV est vide.
 * Les codes non listés reçoivent un libellé neutre : rien n'est deviné.
 */
final class CategoriesFiness
{
    /** @var array<string, string> */
    private const LIBELLES = [
        '101' => 'Centre hospitalier régional',
        '355' => 'Centre hospitalier',
        '362' => 'Établissement de soins longue durée',
        '365' => 'Établissement de soins pluridisciplinaire',
        '124' => 'Centre de santé',
        '603' => 'Maison de santé',
        '611' => 'Laboratoire de biologie médicale',
        '156' => 'Centre médico-psychologique',
        '500' => 'EHPAD',
        '202' => 'Résidence autonomie',
        '354' => 'SSIAD',
        '460' => 'Service autonomie aide',
        '462' => 'Lieu de vie et d\'accueil',
        '183' => 'IME',
        '182' => 'SESSAD',
        '246' => 'ESAT',
        '255' => 'MAS',
        '437' => 'FAM',
        '620' => 'Pharmacie d\'officine',
    ];

    public static function libelle(string $code, string $libelleCsv = ''): string
    {
        $libelleCsv = trim($libelleCsv);
        if ($libelleCsv !== '') {
            return $libelleCsv;
        }

        return self::LIBELLES[$code] ?? 'Établissement de santé (FINESS ' . $code . ')';
    }
}
