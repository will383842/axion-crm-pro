<?php

/**
 * IMPORT DES OFFICINES FINESS — `crm:pharmacies:import-finess` et
 * `crm:pharmacies:classer-taille`.
 *
 * Fixtures FICTIVES (dépôt public) : SIREN/SIRET 94xxxxxxx…, noms « ZZ »,
 * communes inventées, CSV écrit dans un dossier temporaire.
 *
 * Verrouillé : essai à blanc par défaut (n'écrit rien) ; 620 état A seulement
 * créées, 627-690 comptées à part ; dédoublonnage SIRET puis SIREN ; SIREN
 * connu sous un autre SIRET = établissement noté, fiche intacte ; corbeille
 * jamais restaurée ; aucun écrasement ; classer-taille ne remplit que le vide.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** @param list<array<string, string>> $lignes */
function csvFiness(array $lignes): string
{
    $chemin = tempnam(sys_get_temp_dir(), 'zzfiness');
    $f = fopen($chemin, 'w');
    fputcsv($f, ['finess_et', 'siret', 'categorie', 'etat', 'nom_long', 'code_postal', 'commune', 'dep', 'statut', 'ape'], ',', '"', '');
    foreach ($lignes as $l) {
        fputcsv($f, array_merge([
            'finess_et' => '990000001', 'siret' => '', 'categorie' => '620', 'etat' => 'A', 'nom_long' => 'ZZ OFFICINE',
            'code_postal' => '99100', 'commune' => 'ZZVILLE', 'dep' => '99', 'statut' => 'SARL', 'ape' => '4773Z',
        ], $l), ',', '"', '');
    }
    fclose($f);

    return $chemin;
}

function lancer(string $commande, array $options): string
{
    Artisan::call($commande, $options);

    return Artisan::output();
}

it('par défaut est un essai à blanc : totaux par département, rien d\'écrit', function () {
    $espace = F::espace();
    $csv = csvFiness([['siret' => '94000000100011'], ['siret' => '94000000200011', 'dep' => '98']]);

    $sortie = lancer('crm:pharmacies:import-finess', ['fichier' => $csv, '--workspace' => $espace]);

    expect($sortie)->toContain('ESSAI À BLANC')->toContain('99 : 1')->toContain('98 : 1')->not->toContain('ZZ OFFICINE');
    expect(DB::table('companies')->where('workspace_id', $espace)->count())->toBe(0);
    expect(DB::table('finess_etablissements')->count())->toBe(0);
});

it('--apply crée seulement les officines 620 actives, source finess, et classe le reste à part', function () {
    $espace = F::espace();
    $csv = csvFiness([
        ['siret' => '94000000100011'],
        ['siret' => '94000000200011', 'etat' => 'F'],
        ['siret' => '94000000300011', 'categorie' => '627'],
        ['siret' => '94000000400011', 'categorie' => '690'],
        ['siret' => ''],
        ['siret' => '94000000100011'],
    ]);

    $sortie = lancer('crm:pharmacies:import-finess', ['fichier' => $csv, '--workspace' => $espace, '--apply' => true]);

    expect(DB::table('companies')->where('workspace_id', $espace)->count())->toBe(1);
    $f = DB::table('companies')->where('workspace_id', $espace)->first();
    expect($f->siren)->toBe('940000001')->and($f->siret)->toBe('94000000100011')->and($f->discovery_source)->toBe('finess');
    expect($sortie)->toContain('627 = 1')->toContain('690 = 1')->toContain('sans SIRET : 1')->toContain('doublons du fichier : 1');
    expect(DB::table('finess_etablissements')->where('workspace_id', $espace)->count())->toBe(1);
});

it('ne touche jamais une fiche existante : SIRET connu, SIREN connu sous un autre SIRET, corbeille', function () {
    $espace = F::espace();
    $a = F::fiche($espace, 'ZZ A', ['siren' => '940000011', 'siret' => '94000001100011', 'phone' => '0199000011']);
    $b = F::fiche($espace, 'ZZ B', ['siren' => '940000012', 'siret' => '94000001200011']);
    $c = F::fiche($espace, 'ZZ C', ['siren' => '940000013', 'siret' => '94000001300011', 'deleted_at' => now()]);
    $avant = DB::table('companies')->where('workspace_id', $espace)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

    $sortie = lancer('crm:pharmacies:import-finess', ['fichier' => csvFiness([
        ['siret' => '94000001100011', 'nom_long' => 'ZZ AUTRE NOM'],
        ['siret' => '94000001200022'],
        ['siret' => '94000001300022'],
    ]), '--workspace' => $espace, '--apply' => true]);

    $apres = DB::table('companies')->where('workspace_id', $espace)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    expect($apres)->toBe($avant);
    expect($sortie)->toContain('déjà au CRM par SIRET : 1')->toContain('autre SIRET (établissement ajouté, fiche intacte) : 1')->toContain('corbeille (ignorées) : 1');
    expect(DB::table('finess_etablissements')->where('company_id', $b)->where('siret', '94000001200022')->exists())->toBeTrue();
    expect(DB::table('finess_etablissements')->where('company_id', $c)->exists())->toBeFalse();
});

it('deux officines d\'une même société créent une fiche et un établissement de plus', function () {
    $espace = F::espace();

    lancer('crm:pharmacies:import-finess', ['fichier' => csvFiness([
        ['siret' => '94000002100011'], ['siret' => '94000002100029'],
    ]), '--workspace' => $espace, '--apply' => true]);

    expect(DB::table('companies')->where('workspace_id', $espace)->count())->toBe(1);
    expect(DB::table('finess_etablissements')->where('workspace_id', $espace)->count())->toBe(2);
});

it('est idempotente : relancée, elle ne crée plus rien', function () {
    $espace = F::espace();
    $csv = csvFiness([['siret' => '94000003100011']]);

    lancer('crm:pharmacies:import-finess', ['fichier' => $csv, '--workspace' => $espace, '--apply' => true]);
    $sortie = lancer('crm:pharmacies:import-finess', ['fichier' => $csv, '--workspace' => $espace, '--apply' => true]);

    expect(DB::table('companies')->where('workspace_id', $espace)->count())->toBe(1);
    expect($sortie)->toContain('fiches créées : 0');
});

it('classer-taille ne remplit que les champs vides et affiche la part inconnue', function () {
    $espace = F::espace();
    lancer('crm:pharmacies:import-finess', ['fichier' => csvFiness([
        ['siret' => '94000004100011'], ['siret' => '94000004100029'], ['siret' => '94000004200011'],
    ]), '--workspace' => $espace, '--apply' => true]);
    DB::table('companies')->where('workspace_id', $espace)->where('siren', '940000041')->update(['effectif_range' => '12']);
    DB::table('companies')->where('workspace_id', $espace)->where('siren', '940000042')->update(['effectif_range' => '03', 'size_category' => 'eti']);

    $essai = lancer('crm:pharmacies:classer-taille', ['--workspace' => $espace, '--dry-run' => true]);
    expect(DB::table('companies')->where('siren', '940000041')->value('size_category'))->toBeNull();
    expect($essai)->toContain('ESSAI À BLANC');

    $sortie = lancer('crm:pharmacies:classer-taille', ['--workspace' => $espace]);

    $un = DB::table('companies')->where('siren', '940000041')->first();
    expect($un->size_category)->toBe('pme')->and((int) $un->effectif_min)->toBe(20)->and((int) $un->effectif_max)->toBe(49);
    expect(json_decode($un->metadata, true)['nb_officines_societe'])->toBe(2);
    $deux = DB::table('companies')->where('siren', '940000042')->first();
    expect($deux->size_category)->toBe('eti');
    expect($sortie)->toContain('taille inconnue avant : 1 (50,0 %)')->toContain('taille inconnue après : 0 (0,0 %)');
});
