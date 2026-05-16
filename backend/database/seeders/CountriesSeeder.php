<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CountriesSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['code_iso2' => 'FR', 'code_iso3' => 'FRA', 'name_fr' => 'France',  'name_en' => 'France',  'eu_member' => true,  'currency' => 'EUR'],
            ['code_iso2' => 'BE', 'code_iso3' => 'BEL', 'name_fr' => 'Belgique','name_en' => 'Belgium', 'eu_member' => true,  'currency' => 'EUR'],
            ['code_iso2' => 'CH', 'code_iso3' => 'CHE', 'name_fr' => 'Suisse',  'name_en' => 'Switzerland','eu_member' => false,'currency' => 'CHF'],
            ['code_iso2' => 'LU', 'code_iso3' => 'LUX', 'name_fr' => 'Luxembourg','name_en' => 'Luxembourg','eu_member' => true,'currency' => 'EUR'],
            ['code_iso2' => 'EE', 'code_iso3' => 'EST', 'name_fr' => 'Estonie', 'name_en' => 'Estonia', 'eu_member' => true,  'currency' => 'EUR'],
        ];

        foreach ($rows as $row) {
            DB::table('countries')->updateOrInsert(
                ['code_iso2' => $row['code_iso2']],
                $row + ['created_at' => now()],
            );
        }
    }
}
