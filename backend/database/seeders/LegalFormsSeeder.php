<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Formes juridiques INSEE (catégories juridiques, niveau III) — table
 * `legal_forms`.
 *
 * 2026-10-03 — LIBELLÉS CORRIGÉS. La version précédente portait vingt lignes
 * dont plusieurs fausses : 5202 étiqueté « SARL » (c'est la société en nom
 * collectif), 5499 écrit DEUX fois (« EURL » puis « Société civile » : la
 * seconde écrasait la première), 7322 écrit deux fois lui aussi (« Établissement
 * public administratif local » puis « Département » : c'est l'association
 * foncière urbaine), 7344 « Établissement public local » (c'est la métropole),
 * 7361 « Commune » (c'est le CCAS), 5410 « SCP » (c'est la SARL nationale),
 * 6533 « SEL à forme anonyme » (c'est le GAEC), 6540 « coopérative de
 * production » (c'est la SCI)…
 *
 * Source UNIQUE des libellés : `frontend/src/lib/categories-juridiques.ts`
 * (nomenclature INSEE relue et validée). Seuls les codes présents à la fois
 * dans l'ancienne liste et dans cette nomenclature figurent ici ; aucun libellé
 * n'est inventé. La garde `tests/Feature/Database/LegalFormsSeederTest.php`
 * compare chaque libellé à ce fichier.
 *
 * Les codes 1100, 1200 et 1300 (« Artisan-commerçant », « Commerçant »,
 * « Artisan ») n'existent pas dans la nomenclature actuelle (l'entrepreneur
 * individuel y est le seul code 1000). Ils sortent de cette liste, mais le
 * seeder NE SUPPRIME RIEN : une ligne déjà en base reste telle quelle.
 *
 * Idempotent (`updateOrInsert` par code) : le rejouer corrige les lignes
 * existantes sans en créer de doublon ni en effacer.
 *   php artisan db:seed --class=Database\\Seeders\\LegalFormsSeeder --force
 */
class LegalFormsSeeder extends Seeder
{
    /**
     * [code, libellé INSEE, is_company]. `is_company` vaut `false` pour
     * l'entrepreneur individuel, les associations et les personnes morales de
     * droit public (famille 7).
     *
     * @var list<array{0: string, 1: string, 2: bool}>
     */
    public const FORMES = [
        ['1000', 'Entrepreneur individuel', false],
        ['5202', 'Société en nom collectif', true],
        ['5410', 'SARL nationale', true],
        ['5485', 'Société d’exercice libéral à responsabilité limitée (SELARL)', true],
        ['5499', 'SARL, société à responsabilité limitée', true],
        ['5599', 'SA à conseil d’administration', true],
        ['5710', 'SAS, société par actions simplifiée', true],
        ['5720', 'SASU, société par actions simplifiée à associé unique', true],
        ['6533', 'Groupement agricole d’exploitation en commun (GAEC)', true],
        ['6540', 'Société civile immobilière (SCI)', true],
        ['7322', 'Association foncière urbaine', false],
        ['7344', 'Métropole', false],
        ['7361', 'Centre communal d’action sociale (CCAS)', false],
        ['9210', 'Association non déclarée', false],
        ['9220', 'Association déclarée', false],
    ];

    public function run(): void
    {
        foreach (self::FORMES as [$code, $label, $isCompany]) {
            DB::table('legal_forms')->updateOrInsert(
                ['code' => $code],
                ['label' => $label, 'is_company' => $isCompany],
            );
        }
    }
}
