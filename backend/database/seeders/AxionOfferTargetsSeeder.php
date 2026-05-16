<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Catalogue offre Axion-IA — aligné `pricing.ts` Axion-IA (mémoire user 2026-05-08).
 */
class AxionOfferTargetsSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['audit_flash',          'Audit Flash',                       ['artisan','tpe','pme'],         390,   890, 'Diagnostic IA en ½ journée → 2 jours'],
            ['audit_essentielle',    'Audit Essentielle',                 ['tpe','pme'],                   490,  1190, 'Audit 1 journée, livrable structuré'],
            ['audit_approfondie',    'Audit Approfondie',                 ['pme','eti'],                   890,  1990, 'Audit 2 jours + plan d\'action'],
            ['mission_pme',          'Mission PME',                       ['pme'],                        3500,  9000, 'Implémentation IA opérationnelle'],
            ['mission_eti',          'Mission ETI',                       ['eti'],                        9000, 25000, 'Mission cadrée + delivery 4-8 semaines'],
            ['grand_programme',      'Grand programme',                   ['eti','grande_entreprise'],   25000, 80000, 'Programme transformation IA'],
            ['ia_custom',            'IA custom',                         ['pme','eti','grande_entreprise'],8000, 50000, 'Développement modèle/agent custom'],
            ['maintenance',          'Maintenance IA',                    ['pme','eti','grande_entreprise'],290,  990, 'Forfait mensuel (par mois)'],
        ];

        foreach ($rows as [$code, $name, $sizes, $min, $max, $desc]) {
            DB::table('axion_offer_targets')->updateOrInsert(
                ['code' => $code],
                [
                    'name'           => $name,
                    'size_focus'     => '{' . implode(',', $sizes) . '}',
                    'price_min_eur'  => $min,
                    'price_max_eur'  => $max,
                    'description'    => $desc,
                ],
            );
        }
    }
}
