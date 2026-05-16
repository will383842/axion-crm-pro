<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 13 régions métropolitaines + 5 DROM (INSEE 2026).
 * Géométrie laissée NULL — sera remplie par `php artisan ign:import-admin-express`
 * (Sprint 9, cf. spec/11).
 */
class FrenchRegionsSeeder extends Seeder
{
    public function run(): void
    {
        $regions = [
            ['11', 'Île-de-France',              12317279],
            ['24', 'Centre-Val de Loire',         2557000],
            ['27', 'Bourgogne-Franche-Comté',     2807000],
            ['28', 'Normandie',                   3327000],
            ['32', 'Hauts-de-France',             6004000],
            ['44', 'Grand Est',                   5552000],
            ['52', 'Pays de la Loire',            3801000],
            ['53', 'Bretagne',                    3357000],
            ['75', 'Nouvelle-Aquitaine',          6018000],
            ['76', 'Occitanie',                   5982000],
            ['84', 'Auvergne-Rhône-Alpes',        8076000],
            ['93', "Provence-Alpes-Côte d'Azur",  5089000],
            ['94', 'Corse',                        342000],
            ['01', 'Guadeloupe',                   384000],
            ['02', 'Martinique',                   358000],
            ['03', 'Guyane',                       299000],
            ['04', 'La Réunion',                   868000],
            ['06', 'Mayotte',                      299000],
        ];

        foreach ($regions as [$code, $name, $population]) {
            DB::table('regions')->updateOrInsert(
                ['code' => $code],
                [
                    'country_code' => 'FR',
                    'name'         => $name,
                    'population'   => $population,
                    'created_at'   => now(),
                ],
            );
        }
    }
}
