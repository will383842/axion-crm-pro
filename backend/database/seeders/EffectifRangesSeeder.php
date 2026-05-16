<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 16 codes INSEE effectif (TrancheEffectif) — cf. https://www.insee.fr/fr/information/2406147
 */
class EffectifRangesSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['NN', 'Non renseigné',                'inconnue',          null, null],
            ['00', '0 salarié',                    'tpe',                  0,    0],
            ['01', '1 ou 2 salariés',              'tpe',                  1,    2],
            ['02', '3 à 5 salariés',               'tpe',                  3,    5],
            ['03', '6 à 9 salariés',               'tpe',                  6,    9],
            ['11', '10 à 19 salariés',             'tpe',                 10,   19],
            ['12', '20 à 49 salariés',             'pme',                 20,   49],
            ['21', '50 à 99 salariés',             'pme',                 50,   99],
            ['22', '100 à 199 salariés',           'pme',                100,  199],
            ['31', '200 à 249 salariés',           'pme',                200,  249],
            ['32', '250 à 499 salariés',           'eti',                250,  499],
            ['41', '500 à 999 salariés',           'eti',                500,  999],
            ['42', '1 000 à 1 999 salariés',       'eti',               1000, 1999],
            ['51', '2 000 à 4 999 salariés',       'eti',               2000, 4999],
            ['52', '5 000 à 9 999 salariés',       'grande_entreprise', 5000, 9999],
            ['53', '10 000 salariés et plus',      'grande_entreprise',10000, null],
        ];

        foreach ($rows as [$code, $label, $size, $min, $max]) {
            DB::table('effectif_ranges')->updateOrInsert(
                ['code' => $code],
                [
                    'label'         => $label,
                    'size_category' => $size,
                    'min_value'     => $min,
                    'max_value'     => $max,
                ],
            );
        }
    }
}
