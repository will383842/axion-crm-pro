<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Top 20 formes juridiques INSEE — extrait du référentiel complet.
 * Import exhaustif via `php artisan legal-forms:import` (Sprint 5).
 */
class LegalFormsSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['1000', 'Entrepreneur individuel',         false],
            ['5202', 'Société à responsabilité limitée (SARL)', true],
            ['5499', 'SARL unipersonnelle (EURL)',      true],
            ['5710', 'Société par actions simplifiée (SAS)', true],
            ['5720', 'SAS unipersonnelle (SASU)',       true],
            ['5599', 'Société anonyme à conseil d\'administration (SA)', true],
            ['5499', 'Société civile',                  true],
            ['9220', 'Association déclarée',            false],
            ['7344', 'Établissement public local',      true],
            ['7322', 'Établissement public administratif local', true],
            ['1100', 'Artisan-commerçant',              false],
            ['1200', 'Commerçant',                      false],
            ['1300', 'Artisan',                         false],
            ['5410', 'Société civile professionnelle (SCP)', true],
            ['5485', 'Société d\'exercice libéral (SELARL)', true],
            ['6533', 'Société d\'exercice libéral à forme anonyme', true],
            ['6540', 'Société coopérative de production', true],
            ['9210', 'Association non déclarée',        false],
            ['7361', 'Commune et commune nouvelle',     true],
            ['7322', 'Département',                     true],
        ];

        foreach ($rows as [$code, $label, $isCompany]) {
            DB::table('legal_forms')->updateOrInsert(
                ['code' => $code],
                ['label' => $label, 'is_company' => $isCompany],
            );
        }
    }
}
