<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionsAndRolesSeeder::class,
            CountriesSeeder::class,
            FrenchRegionsSeeder::class,
            CitiesLargeSeeder::class,
            EffectifRangesSeeder::class,
            AxionOfferTargetsSeeder::class,
            SearchEnginesSeeder::class,
            NafSectionsSeeder::class,
            LegalFormsSeeder::class,
            UserAgentsSeeder::class,
            LlmUseCasesSeeder::class,
            OwnerUserSeeder::class,
            AiActRegisterSeeder::class,
            DemoCompaniesSeeder::class,
            DefaultAudiencesSeeder::class,
        ]);
    }
}
