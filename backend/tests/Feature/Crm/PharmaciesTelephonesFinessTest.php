<?php

/**
 * `crm:pharmacies:telephones-finess` — fixtures FICTIVES (ZZ, SIREN 94xxxxxxx).
 * Verrouillé : essai à blanc par défaut ; ajout sur champ vide seulement ; jamais d'écrasement ;
 * opposition, numéro invalide et personne physique écartés ; isolation par espace.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** @param list<array<string, string>> $lignes */
function csvTel(array $lignes): string
{
    $chemin = tempnam(sys_get_temp_dir(), 'zztel');
    $f = fopen($chemin, 'w');
    fputcsv($f, ['finess_et', 'siret', 'categorie', 'etat', 'telephone'], ';', '"', '');
    foreach ($lignes as $l) {
        fputcsv($f, array_merge(['finess_et' => '990000001', 'siret' => '', 'categorie' => '620', 'etat' => 'A', 'telephone' => ''], $l), ';', '"', '');
    }
    fclose($f);

    return $chemin;
}

/** Fiche + établissement FINESS ; renvoie [id, siret]. */
function ficheTel(string $espace, array $attrs = []): array
{
    $siren = F::siren();
    $siret = $siren . '00011';
    $id = F::fiche($espace, 'ZZ OFFICINE', array_merge(['siren' => $siren, 'siret' => $siret, 'legal_form' => '5499'], $attrs));
    DB::table('finess_etablissements')->insert(['workspace_id' => $espace, 'company_id' => $id, 'siret' => $siret, 'categorie' => '620', 'created_at' => now()]);

    return [$id, $siret];
}

function lancerTel(string $espace, string $csv, bool $apply = true): string
{
    Artisan::call('crm:pharmacies:telephones-finess', array_filter(['fichier' => $csv, '--workspace' => $espace, '--apply' => $apply ?: null]));

    return Artisan::output();
}

it('ajoute le téléphone normalisé sur un champ vide et pose telephone_source', function () {
    $espace = F::espace();
    [$a, $sa] = ficheTel($espace);
    [$b, $sb] = ficheTel($espace, ['phone' => '  ']);

    $sortie = lancerTel($espace, csvTel([['siret' => $sa, 'telephone' => '01 40 00 00 01'], ['siret' => $sb, 'telephone' => '+33 4 00 00 00 02']]));

    expect(DB::table('companies')->where('id', $a)->value('phone'))->toBe('0140000001');
    expect(DB::table('companies')->where('id', $b)->value('phone'))->toBe('0400000002');
    expect(json_decode((string) DB::table('companies')->where('id', $a)->value('metadata'), true)['telephone_source'])->toBe('finess');
    expect($sortie)->toContain('téléphones ajoutés : 2')->not->toContain('0140000001');
});

it('n\'écrase jamais un téléphone existant', function () {
    $espace = F::espace();
    [$id, $siret] = ficheTel($espace, ['phone' => '0499999999']);

    $sortie = lancerTel($espace, csvTel([['siret' => $siret, 'telephone' => '0140000001']]));

    expect(DB::table('companies')->where('id', $id)->value('phone'))->toBe('0499999999');
    expect(json_decode((string) DB::table('companies')->where('id', $id)->value('metadata'), true))->not->toHaveKey('telephone_source');
    expect($sortie)->toContain('déjà renseignées : 1')->toContain('téléphones ajoutés : 0');
});

it('écarte un numéro en liste d\'opposition, quelle que soit son écriture', function () {
    $espace = F::espace();
    [$id, $siret] = ficheTel($espace);
    DB::table('opt_out')->insert(['phone' => '+33 1 40 00 00 01', 'scope' => 'business', 'source' => 'test', 'created_at' => now()]);

    $sortie = lancerTel($espace, csvTel([['siret' => $siret, 'telephone' => '01 40 00 00 01']]));

    expect(DB::table('companies')->where('id', $id)->value('phone'))->toBeNull();
    expect($sortie)->toContain('écartés pour opposition : 1');
});

it('écarte les personnes physiques (forme 1…, vide, ou métadonnée)', function () {
    $espace = F::espace();
    [$a, $sa] = ficheTel($espace, ['legal_form' => ' 1000']);
    [$b, $sb] = ficheTel($espace, ['legal_form' => null]);
    [$c, $sc] = ficheTel($espace, ['metadata' => json_encode(['personne_physique' => true])]);

    $sortie = lancerTel($espace, csvTel([
        ['siret' => $sa, 'telephone' => '0140000001'], ['siret' => $sb, 'telephone' => '0140000002'], ['siret' => $sc, 'telephone' => '0140000003'],
    ]));

    expect(DB::table('companies')->whereIn('id', [$a, $b, $c])->whereNotNull('phone')->count())->toBe(0);
    expect($sortie)->toContain('personnes physiques écartées : 3');
});

it('écarte un numéro invalide', function () {
    $espace = F::espace();
    [$id, $siret] = ficheTel($espace);

    $sortie = lancerTel($espace, csvTel([['siret' => $siret, 'telephone' => '12345']]));

    expect(DB::table('companies')->where('id', $id)->value('phone'))->toBeNull();
    expect($sortie)->toContain('numéros invalides : 1');
});

it('respecte l\'isolation par espace de travail', function () {
    $autre = F::espace();
    $espace = F::espace();
    [$idAutre, $siret] = ficheTel($autre);

    $sortie = lancerTel($espace, csvTel([['siret' => $siret, 'telephone' => '0140000001']]));

    expect(DB::table('companies')->where('id', $idAutre)->value('phone'))->toBeNull();
    expect($sortie)->toContain('introuvables : 1');
});

it('par défaut est un essai à blanc : rien n\'est écrit', function () {
    $espace = F::espace();
    [$id, $siret] = ficheTel($espace);

    $sortie = lancerTel($espace, csvTel([['siret' => $siret, 'telephone' => '0140000001']]), false);

    expect(DB::table('companies')->where('id', $id)->value('phone'))->toBeNull();
    expect($sortie)->toContain('ESSAI À BLANC')->toContain('téléphones à ajouter : 1');
});
