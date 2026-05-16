<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

/**
 * Seed des grandes villes françaises (>30 000 hab) — sous-ensemble du référentiel IGN.
 * Utilisé pour démarrer en local sans nécessiter le téléchargement complet
 * `php artisan ign:import-admin-express` (~250 Mo).
 *
 * Pour le set complet ~2150 communes >5k hab, utiliser :
 *   php artisan ign:import-admin-express --since-population=5000
 */
class CitiesLargeSeeder extends Seeder
{
    public function run(): void
    {
        // Format : [code_insee, departement, name, population]
        $cities = [
            ['75056', '75', 'Paris',                2161000],
            ['13055', '13', 'Marseille',             870731],
            ['69123', '69', 'Lyon',                  522969],
            ['31555', '31', 'Toulouse',              498003],
            ['06088', '06', 'Nice',                  341522],
            ['44109', '44', 'Nantes',                323204],
            ['67482', '67', 'Strasbourg',            287228],
            ['34172', '34', 'Montpellier',           299096],
            ['33063', '33', 'Bordeaux',              260958],
            ['59350', '59', 'Lille',                 233098],
            ['35238', '35', 'Rennes',                225081],
            ['76540', '76', 'Le Havre',              165830],
            ['51454', '51', 'Reims',                 181194],
            ['42218', '42', 'Saint-Étienne',         171924],
            ['83137', '83', 'Toulon',                176198],
            ['38185', '38', 'Grenoble',              158346],
            ['21231', '21', 'Dijon',                 159346],
            ['49007', '49', 'Angers',                155850],
            ['30189', '30', 'Nîmes',                 148236],
            ['76351', '76', 'Rouen',                 114083],
            ['72181', '72', 'Le Mans',               147325],
            ['63113', '63', 'Clermont-Ferrand',      147865],
            ['54395', '54', 'Nancy',                 104592],
            ['37261', '37', 'Tours',                 135787],
            ['80021', '80', 'Amiens',                134057],
            ['25056', '25', 'Besançon',              115934],
            ['57463', '57', 'Metz',                  117890],
            ['38421', '38', 'Saint-Martin-d\'Hères',  39800],
            ['69266', '69', 'Villeurbanne',          153934],
            ['92012', '92', 'Boulogne-Billancourt',  121334],
            ['92050', '92', 'Nanterre',               96807],
            ['92024', '92', 'Courbevoie',             83260],
            ['93066', '93', 'Saint-Denis',           113116],
            ['93048', '93', 'Montreuil',             109897],
            ['94028', '94', 'Créteil',                89712],
            ['78646', '78', 'Versailles',             85771],
            ['68224', '68', 'Mulhouse',              108942],
            ['68066', '68', 'Colmar',                 70907],
            ['62041', '62', 'Arras',                  41019],
            ['62498', '62', 'Lens',                   31537],
        ];

        foreach ($cities as [$insee, $dept, $name, $pop]) {
            $slug = Str::slug($name);
            DB::table('cities')->updateOrInsert(
                ['code_insee' => $insee],
                [
                    'department'   => $dept,
                    'name'         => $name,
                    'slug'         => $slug,
                    'postal_codes' => '{}',  // populé par IGN import
                    'population'   => $pop,
                    'created_at'   => now(),
                ],
            );
        }

        DB::table('departments')->updateOrInsert(['code' => '13'], ['region_code' => '93', 'name' => 'Bouches-du-Rhône', 'population' => 2024000, 'created_at' => now()]);
        DB::table('departments')->updateOrInsert(['code' => '06'], ['region_code' => '93', 'name' => 'Alpes-Maritimes', 'population' => 1083000, 'created_at' => now()]);
        DB::table('departments')->updateOrInsert(['code' => '83'], ['region_code' => '93', 'name' => 'Var',              'population' => 1076000, 'created_at' => now()]);
        DB::table('departments')->updateOrInsert(['code' => '34'], ['region_code' => '76', 'name' => 'Hérault',          'population' => 1175000, 'created_at' => now()]);
        DB::table('departments')->updateOrInsert(['code' => '33'], ['region_code' => '75', 'name' => 'Gironde',          'population' => 1633000, 'created_at' => now()]);
        DB::table('departments')->updateOrInsert(['code' => '59'], ['region_code' => '32', 'name' => 'Nord',             'population' => 2606000, 'created_at' => now()]);
        DB::table('departments')->updateOrInsert(['code' => '67'], ['region_code' => '44', 'name' => 'Bas-Rhin',         'population' => 1147000, 'created_at' => now()]);
        DB::table('departments')->updateOrInsert(['code' => '44'], ['region_code' => '52', 'name' => 'Loire-Atlantique', 'population' => 1437000, 'created_at' => now()]);
        DB::table('departments')->updateOrInsert(['code' => '35'], ['region_code' => '53', 'name' => 'Ille-et-Vilaine',  'population' => 1080000, 'created_at' => now()]);
        DB::table('departments')->updateOrInsert(['code' => '31'], ['region_code' => '76', 'name' => 'Haute-Garonne',    'population' => 1432000, 'created_at' => now()]);
    }
}
