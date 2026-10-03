<?php

/**
 * GARDE — `LegalFormsSeeder` : des libellés INSEE justes, sans doublon, et un
 * rejeu qui corrige sans rien effacer (2026-10-03).
 *
 * La version précédente étiquetait 5202 « SARL » (c'est la société en nom
 * collectif) et écrivait 5499 et 7322 deux fois. Les libellés font désormais
 * référence à `frontend/src/lib/categories-juridiques.ts` (nomenclature
 * relue) : cette garde les compare un à un.
 *
 * Le seeder doit pouvoir être rejoué en production : il corrige les lignes
 * existantes et ne supprime JAMAIS une ligne (interdit de purger), même celle
 * d'un code qu'il ne connaît plus.
 */

use Database\Seeders\LegalFormsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** @return array<string, string> code → libellé de la nomenclature front. */
function nomenclatureFront(): array
{
    $chemin = base_path('../frontend/src/lib/categories-juridiques.ts');
    expect(is_file($chemin))->toBeTrue('Nomenclature front introuvable : la garde ne peut rien comparer.');

    $source = (string) file_get_contents($chemin);
    preg_match_all("/'(\\d{4})':\\s*'([^']*)'/u", $source, $m, PREG_SET_ORDER);

    $table = [];
    foreach ($m as $ligne) {
        $table[$ligne[1]] = $ligne[2];
    }

    return $table;
}

it('relève bien la nomenclature front (témoin)', function () {
    $table = nomenclatureFront();

    expect(count($table))->toBeGreaterThan(200)
        ->and($table['5202'] ?? null)->toBe('Société en nom collectif');
});

it('ne déclare aucun code deux fois', function () {
    $codes = array_map(static fn (array $f): string => $f[0], LegalFormsSeeder::FORMES);

    expect($codes)->toBe(array_values(array_unique($codes)));
});

it('ne porte que des codes de la nomenclature, avec son libellé exact', function () {
    $table = nomenclatureFront();

    foreach (LegalFormsSeeder::FORMES as [$code, $libelle]) {
        // `toHaveKey($cle, $valeur)` : le second argument est la VALEUR attendue,
        // pas un message — on vérifie la présence, puis le libellé.
        expect(array_key_exists($code, $table))->toBeTrue("Code {$code} absent de la nomenclature INSEE.");
        expect($libelle)->toBe($table[$code], "Libellé de {$code} différent de la nomenclature.");
    }
});

it('corrige les lignes fausses déjà en base, sans doublon et sans rien effacer', function () {
    // L'état de la production avant correction (extrait).
    DB::table('legal_forms')->insert([
        ['code' => '5202', 'label' => 'Société à responsabilité limitée (SARL)', 'is_company' => true],
        ['code' => '5499', 'label' => 'Société civile', 'is_company' => true],
        ['code' => '7322', 'label' => 'Département', 'is_company' => true],
        ['code' => '1100', 'label' => 'Artisan-commerçant', 'is_company' => false],
        ['code' => '9999', 'label' => 'Ligne étrangère au seeder', 'is_company' => true],
    ]);
    $avant = DB::table('legal_forms')->count();

    (new LegalFormsSeeder)->run();

    $libelle = fn (string $code) => DB::table('legal_forms')->where('code', $code)->value('label');

    expect($libelle('5202'))->toBe('Société en nom collectif')
        ->and($libelle('5499'))->toBe('SARL, société à responsabilité limitée')
        ->and($libelle('7322'))->toBe('Association foncière urbaine')
        // Codes inconnus du seeder : intacts, pas supprimés.
        ->and($libelle('1100'))->toBe('Artisan-commerçant')
        ->and($libelle('9999'))->toBe('Ligne étrangère au seeder');

    // Trois codes existaient déjà : seuls les autres sont ajoutés.
    $attendu = $avant + count(LegalFormsSeeder::FORMES) - 3;
    expect(DB::table('legal_forms')->count())->toBe($attendu);

    // Rejeu : rien ne bouge.
    $instantane = DB::table('legal_forms')->orderBy('code')->get()->toArray();
    (new LegalFormsSeeder)->run();
    expect(DB::table('legal_forms')->orderBy('code')->get()->toArray())->toEqual($instantane);
});
