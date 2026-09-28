<?php

namespace Database\Seeders;

use App\Crm\Referentiels\Classement;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 16 codes INSEE effectif (TrancheEffectif) — cf. https://www.insee.fr/fr/information/2406147
 */
class EffectifRangesSeeder extends Seeder
{
    public function run(): void
    {
        // La taille de chaque tranche n'est plus recopiée ici (elle disait
        // « 10 à 19 salariés = tpe », au rebours de la collecte) : elle vient du
        // calcul unique `Classement::tailleDepuisInsee()` (2026-09-28).
        $rows = [
            ['NN', 'Non renseigné',                null, null],
            ['00', '0 salarié',                       0,    0],
            ['01', '1 ou 2 salariés',                 1,    2],
            ['02', '3 à 5 salariés',                  3,    5],
            ['03', '6 à 9 salariés',                  6,    9],
            ['11', '10 à 19 salariés',               10,   19],
            ['12', '20 à 49 salariés',               20,   49],
            ['21', '50 à 99 salariés',               50,   99],
            ['22', '100 à 199 salariés',            100,  199],
            ['31', '200 à 249 salariés',            200,  249],
            ['32', '250 à 499 salariés',            250,  499],
            ['41', '500 à 999 salariés',            500,  999],
            ['42', '1 000 à 1 999 salariés',       1000, 1999],
            ['51', '2 000 à 4 999 salariés',       2000, 4999],
            ['52', '5 000 à 9 999 salariés',       5000, 9999],
            ['53', '10 000 salariés et plus',     10000, null],
        ];

        foreach ($rows as [$code, $label, $min, $max]) {
            DB::table('effectif_ranges')->updateOrInsert(
                ['code' => $code],
                [
                    'label' => $label,
                    'size_category' => $code === 'NN' ? 'inconnue' : Classement::tailleDepuisInsee($code, null),
                    'min_value' => $min,
                    'max_value' => $max,
                ],
            );
        }
    }
}
