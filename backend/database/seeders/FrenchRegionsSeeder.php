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
        // --- 13 régions métropolitaines + 5 DROM (codes INSEE 2026) ---
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

        // --- 96 départements métropolitains + 5 DROM (codes INSEE) ---
        // Format : [code_dept, region_code_insee, name, population]
        $departments = [
            ['01', '84', 'Ain',                       663000],
            ['02', '32', 'Aisne',                     531000],
            ['03', '84', 'Allier',                    335000],
            ['04', '93', 'Alpes-de-Haute-Provence',   166000],
            ['05', '93', 'Hautes-Alpes',              141000],
            ['06', '93', 'Alpes-Maritimes',          1094000],
            ['07', '84', 'Ardèche',                   325000],
            ['08', '44', 'Ardennes',                  269000],
            ['09', '76', 'Ariège',                    153000],
            ['10', '44', 'Aube',                      310000],
            ['11', '76', 'Aude',                      370000],
            ['12', '76', 'Aveyron',                   279000],
            ['13', '93', 'Bouches-du-Rhône',         2024000],
            ['14', '28', 'Calvados',                  691000],
            ['15', '84', 'Cantal',                    144000],
            ['16', '75', 'Charente',                  351000],
            ['17', '75', 'Charente-Maritime',         644000],
            ['18', '24', 'Cher',                      300000],
            ['19', '75', 'Corrèze',                   240000],
            ['2A', '94', 'Corse-du-Sud',              158000],
            ['2B', '94', 'Haute-Corse',               184000],
            ['21', '27', "Côte-d'Or",                 535000],
            ['22', '53', "Côtes-d'Armor",             598000],
            ['23', '75', 'Creuse',                    115000],
            ['24', '75', 'Dordogne',                  413000],
            ['25', '27', 'Doubs',                     540000],
            ['26', '84', 'Drôme',                     520000],
            ['27', '28', 'Eure',                      603000],
            ['28', '24', 'Eure-et-Loir',              429000],
            ['29', '53', 'Finistère',                 914000],
            ['30', '76', 'Gard',                      748000],
            ['31', '76', 'Haute-Garonne',            1418000],
            ['32', '76', 'Gers',                      193000],
            ['33', '75', 'Gironde',                  1622000],
            ['34', '76', 'Hérault',                  1175000],
            ['35', '53', 'Ille-et-Vilaine',          1071000],
            ['36', '24', 'Indre',                     219000],
            ['37', '24', 'Indre-et-Loire',            609000],
            ['38', '84', 'Isère',                    1264000],
            ['39', '27', 'Jura',                      258000],
            ['40', '75', 'Landes',                    413000],
            ['41', '24', 'Loir-et-Cher',              331000],
            ['42', '84', 'Loire',                     762000],
            ['43', '84', 'Haute-Loire',               227000],
            ['44', '52', 'Loire-Atlantique',         1429000],
            ['45', '24', 'Loiret',                    682000],
            ['46', '76', 'Lot',                       175000],
            ['47', '75', 'Lot-et-Garonne',            330000],
            ['48', '76', 'Lozère',                     77000],
            ['49', '52', 'Maine-et-Loire',            822000],
            ['50', '28', 'Manche',                    495000],
            ['51', '44', 'Marne',                     567000],
            ['52', '44', 'Haute-Marne',               172000],
            ['53', '52', 'Mayenne',                   307000],
            ['54', '44', 'Meurthe-et-Moselle',        733000],
            ['55', '44', 'Meuse',                     185000],
            ['56', '53', 'Morbihan',                  759000],
            ['57', '44', 'Moselle',                  1043000],
            ['58', '27', 'Nièvre',                    202000],
            ['59', '32', 'Nord',                     2607000],
            ['60', '32', 'Oise',                      829000],
            ['61', '28', 'Orne',                      278000],
            ['62', '32', 'Pas-de-Calais',            1450000],
            ['63', '84', 'Puy-de-Dôme',               664000],
            ['64', '75', 'Pyrénées-Atlantiques',      684000],
            ['65', '76', 'Hautes-Pyrénées',           226000],
            ['66', '76', 'Pyrénées-Orientales',       482000],
            ['67', '44', 'Bas-Rhin',                 1140000],
            ['68', '44', 'Haut-Rhin',                 766000],
            ['69', '84', 'Rhône',                    1876000],
            ['70', '27', 'Haute-Saône',               233000],
            ['71', '27', 'Saône-et-Loire',            552000],
            ['72', '52', 'Sarthe',                    566000],
            ['73', '84', 'Savoie',                    434000],
            ['74', '84', 'Haute-Savoie',              829000],
            ['75', '11', 'Paris',                    2161000],
            ['76', '28', 'Seine-Maritime',           1252000],
            ['77', '11', 'Seine-et-Marne',           1437000],
            ['78', '11', 'Yvelines',                 1448000],
            ['79', '75', 'Deux-Sèvres',               377000],
            ['80', '32', 'Somme',                     569000],
            ['81', '76', 'Tarn',                      390000],
            ['82', '76', 'Tarn-et-Garonne',           260000],
            ['83', '93', 'Var',                      1085000],
            ['84', '93', 'Vaucluse',                  561000],
            ['85', '52', 'Vendée',                    685000],
            ['86', '75', 'Vienne',                    438000],
            ['87', '75', 'Haute-Vienne',              374000],
            ['88', '44', 'Vosges',                    361000],
            ['89', '27', 'Yonne',                     333000],
            ['90', '27', 'Territoire de Belfort',     141000],
            ['91', '11', 'Essonne',                  1311000],
            ['92', '11', 'Hauts-de-Seine',           1626000],
            ['93', '11', 'Seine-Saint-Denis',        1672000],
            ['94', '11', 'Val-de-Marne',             1408000],
            ['95', '11', "Val-d'Oise",               1248000],
            ['971', '01', 'Guadeloupe',               384000],
            ['972', '02', 'Martinique',               358000],
            ['973', '03', 'Guyane',                   299000],
            ['974', '04', 'La Réunion',               868000],
            ['976', '06', 'Mayotte',                  299000],
        ];

        foreach ($departments as [$code, $regionCode, $name, $population]) {
            DB::table('departments')->updateOrInsert(
                ['code' => $code],
                [
                    'region_code' => $regionCode,
                    'name'        => $name,
                    'population'  => $population,
                    'created_at'  => now(),
                ],
            );
        }
    }
}
