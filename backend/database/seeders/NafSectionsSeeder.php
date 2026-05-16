<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * NAF Rev. 2 — 21 sections A-U (INSEE).
 * Les divisions / groupes / classes / sub-classes (732 codes) seront importés
 * via `php artisan naf:import` (Sprint 5) depuis le CSV INSEE officiel.
 */
class NafSectionsSeeder extends Seeder
{
    public function run(): void
    {
        $sections = [
            ['A', 'Agriculture, sylviculture et pêche'],
            ['B', 'Industries extractives'],
            ['C', 'Industrie manufacturière'],
            ['D', "Production et distribution d'électricité, de gaz, de vapeur et d'air conditionné"],
            ['E', "Production et distribution d'eau ; assainissement, gestion des déchets et dépollution"],
            ['F', 'Construction'],
            ['G', 'Commerce ; réparation d\'automobiles et de motocycles'],
            ['H', 'Transports et entreposage'],
            ['I', 'Hébergement et restauration'],
            ['J', 'Information et communication'],
            ['K', 'Activités financières et d\'assurance'],
            ['L', 'Activités immobilières'],
            ['M', 'Activités spécialisées, scientifiques et techniques'],
            ['N', 'Activités de services administratifs et de soutien'],
            ['O', 'Administration publique'],
            ['P', 'Enseignement'],
            ['Q', 'Santé humaine et action sociale'],
            ['R', 'Arts, spectacles et activités récréatives'],
            ['S', 'Autres activités de services'],
            ['T', 'Activités des ménages en tant qu\'employeurs'],
            ['U', 'Activités extra-territoriales'],
        ];

        foreach ($sections as [$code, $label]) {
            DB::table('naf_sections')->updateOrInsert(['code' => $code], ['label' => $label]);
        }
    }
}
