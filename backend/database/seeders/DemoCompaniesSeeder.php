<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeder démo — 5 entreprises réalistes pour smoke tests E2E + UI sans MOCK_INSEE.
 * Activé seulement si APP_ENV in [local, testing, staging].
 */
class DemoCompaniesSeeder extends Seeder
{
    public function run(): void
    {
        if (! in_array((string) env('APP_ENV', 'production'), ['local', 'testing', 'staging'], true)) {
            return;
        }

        $workspaceId = DB::table('workspaces')->where('slug', 'axion-ia')->value('id');
        if (! $workspaceId) {
            return;
        }

        $companies = [
            ['siren' => '552120222', 'name' => 'TotalEnergies SE',  'naf' => '0610Z', 'size' => 'grande_entreprise', 'priority' => 'haute',   'eff' => '53', 'city' => 'Courbevoie',  'cp' => '92400'],
            ['siren' => '388439629', 'name' => 'Carrefour SA',      'naf' => '4711F', 'size' => 'grande_entreprise', 'priority' => 'haute',   'eff' => '53', 'city' => 'Massy',       'cp' => '91300'],
            ['siren' => '433975859', 'name' => 'Boulangerie Du Coin','naf' => '1071C','size' => 'artisan',           'priority' => 'moyenne', 'eff' => '01', 'city' => 'Paris',       'cp' => '75001'],
            ['siren' => '512107063', 'name' => 'BlaBlaCar',         'naf' => '6201Z', 'size' => 'pme',               'priority' => 'haute',   'eff' => '21', 'city' => 'Paris',       'cp' => '75009'],
            ['siren' => '420624245', 'name' => 'Doctolib',          'naf' => '6201Z', 'size' => 'eti',               'priority' => 'haute',   'eff' => '32', 'city' => 'Levallois',  'cp' => '92300'],
        ];

        foreach ($companies as $c) {
            DB::table('companies')->updateOrInsert(
                ['workspace_id' => $workspaceId, 'siren' => $c['siren']],
                [
                    'denomination'    => $c['name'],
                    'naf'             => $c['naf'],
                    'size_category'   => $c['size'],
                    'effectif_range'  => $c['eff'],
                    'city'            => $c['city'],
                    'postcode'        => $c['cp'],
                    'priority'        => $c['priority'],
                    'discovery_source'=> 'demo_seed',
                    'quality_score'   => random_int(40, 95),
                    'signals'         => '{}',
                    'metadata'        => '{}',
                    'created_at'      => now()->subDays(random_int(1, 30)),
                    'updated_at'      => now(),
                ],
            );
        }
    }
}
