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
    fputcsv($f, ['finess_et', 'siret', 'categorie', 'etat', 'nom_long', 'code_postal', 'commune', 'dep', 'statut', 'ape', 'statut_code'], ',', '"', '');
    foreach ($lignes as $l) {
        fputcsv($f, array_merge([
            'finess_et' => '990000001', 'siret' => '', 'categorie' => '620', 'etat' => 'A', 'nom_long' => 'ZZ OFFICINE',
            'code_postal' => '99100', 'commune' => 'ZZVILLE', 'dep' => '99', 'statut' => 'SARL', 'ape' => '4773Z', 'statut_code' => '',
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

it('paquets multiples : deux SIRET du même SIREN dans deux paquets, essai à blanc et apply donnent le même total', function () {
    $espace = F::espace();
    $csv = csvFiness([['siret' => '94000005100011'], ['siret' => '94000005100029'], ['siret' => '94000005200011']]);

    $essai = lancer('crm:pharmacies:import-finess', ['fichier' => $csv, '--workspace' => $espace, '--paquet' => 1]);
    $apply = lancer('crm:pharmacies:import-finess', ['fichier' => $csv, '--workspace' => $espace, '--paquet' => 1, '--apply' => true]);

    expect($essai)->toContain('fiches à créer : 2');
    expect($apply)->toContain('fiches créées : 2');
    expect(DB::table('companies')->where('workspace_id', $espace)->count())->toBe(2);
    expect(DB::table('finess_etablissements')->where('workspace_id', $espace)->count())->toBe(3);
});

it('accepte le BOM et les alias d\'en-têtes de l\'export brut', function () {
    $espace = F::espace();
    $chemin = tempnam(sys_get_temp_dir(), 'zzfiness');
    file_put_contents($chemin, "\xEF\xBB\xBFfiness_et,siret,categorie,etat,nom_long,code_postal,commune_acheminement,departement,ej_statut_juridique_libelle,ej_code_ape\n"
        . "990000001,94000006100011,620,A,ZZ OFFICINE,99100,ZZVILLE,99,SARL,4773Z\n");

    lancer('crm:pharmacies:import-finess', ['fichier' => $chemin, '--workspace' => $espace, '--apply' => true]);

    $f = DB::table('companies')->where('workspace_id', $espace)->first();
    expect($f->city)->toBe('ZZVILLE')->and($f->department_code)->toBe('99')->and($f->naf)->toBe('47.73Z')->and($f->entity_nature)->toBe('entreprise');
});

it('convertit le département FINESS 9A en 971 ; département absent : laissé nul, jamais déduit du code postal', function () {
    $espace = F::espace();

    lancer('crm:pharmacies:import-finess', ['fichier' => csvFiness([
        ['siret' => '94000007100011', 'dep' => '9A', 'code_postal' => '97100'],
        ['siret' => '94000007200011', 'dep' => '', 'code_postal' => '75001'],
    ]), '--workspace' => $espace, '--apply' => true]);

    expect(DB::table('companies')->where('siren', '940000071')->value('department_code'))->toBe('971');
    $sans = DB::table('companies')->where('siren', '940000072')->first();
    expect($sans->department_code)->toBeNull();
    expect($sans->region_code)->not->toBeNull();
});

it('classer-taille conserve les autres clés de metadata', function () {
    $espace = F::espace();
    lancer('crm:pharmacies:import-finess', ['fichier' => csvFiness([['siret' => '94000008100011']]), '--workspace' => $espace, '--apply' => true]);

    lancer('crm:pharmacies:classer-taille', ['--workspace' => $espace]);

    $meta = json_decode(DB::table('companies')->where('siren', '940000081')->value('metadata'), true);
    expect($meta)->toHaveKey('finess_et')->toHaveKey('finess_statut')->and($meta['nb_officines_societe'])->toBe(1);
});

it('second passage : aucun nouvel établissement', function () {
    $espace = F::espace();
    $csv = csvFiness([['siret' => '94000009100011'], ['siret' => '94000009100029']]);

    lancer('crm:pharmacies:import-finess', ['fichier' => $csv, '--workspace' => $espace, '--apply' => true]);
    $sortie = lancer('crm:pharmacies:import-finess', ['fichier' => $csv, '--workspace' => $espace, '--apply' => true]);

    expect(DB::table('finess_etablissements')->where('workspace_id', $espace)->count())->toBe(2);
    expect($sortie)->toContain('établissements notés : 0');
});

it('société d\'exercice libéral : la fiche garde le nom et l\'adresse du fichier', function () {
    $espace = F::espace();

    lancer('crm:pharmacies:import-finess', ['fichier' => csvFiness([
        ['siret' => '94000010100011', 'statut' => 'Société d\'exercice libéral', 'statut_code' => '85', 'nom_long' => 'ZZ SELARL TEST'],
    ]), '--workspace' => $espace, '--apply' => true]);

    $f = DB::table('companies')->where('siren', '940000101')->first();
    expect($f->denomination)->toBe('ZZ SELARL TEST')->and($f->postcode)->toBe('99100')->and($f->city)->toBe('ZZVILLE');
    expect(json_decode($f->metadata, true))->not->toHaveKey('personne_physique');
});

it('personne physique (libellé ou code) : fiche sans nom ni adresse, garde d\'éligibilité posée', function () {
    $espace = F::espace();

    $sortie = lancer('crm:pharmacies:import-finess', ['fichier' => csvFiness([
        ['siret' => '94000011100011', 'statut' => 'Personne Physique', 'nom_long' => 'ZZ TITULAIRE UN', 'dep' => '75'],
        ['siret' => '94000011200011', 'statut' => '', 'statut_code' => '70', 'nom_long' => 'ZZ TITULAIRE DEUX', 'dep' => '75'],
        ['siret' => '94000011300011'],
    ]), '--workspace' => $espace, '--apply' => true]);

    foreach (['940000111', '940000112'] as $siren) {
        $f = DB::table('companies')->where('siren', $siren)->first();
        expect($f->denomination)->toBe('Entreprise individuelle pharmacie')
            ->and($f->postcode)->toBeNull()->and($f->city)->toBeNull()->and($f->city_name)->toBeNull()
            ->and($f->naf)->toBe('47.73Z')->and($f->department_code)->toBe('75')->and($f->region_code)->not->toBeNull()
            ->and($f->legal_form)->toBe('1000')->and($f->discovery_source)->toBe('finess');
        $meta = json_decode($f->metadata, true);
        expect($meta['personne_physique'])->toBeTrue()->and($meta['legal_form'])->toBe('1000')->and($meta['source'])->toBe('finess');
        expect(json_encode($f))->not->toContain('TITULAIRE');
    }
    expect(DB::table('companies')->where('siren', '940000113')->value('denomination'))->toBe('ZZ OFFICINE');
    $etabs = DB::table('finess_etablissements')->whereIn('siret', ['94000011100011', '94000011200011'])->get();
    expect($etabs)->toHaveCount(2);
    foreach ($etabs as $e) {
        expect($e->commune)->toBeNull()->and($e->code_postal)->toBeNull()->and($e->finess_et)->toBeNull()->and($e->departement)->toBe('75');
        expect(json_encode($e))->not->toContain('ZZVILLE')->not->toContain('99100');
    }
    expect($sortie)->toContain('personnes physiques : 2 (fiches sans nom ni adresse)')->not->toContain('TITULAIRE');
});

it('code 73 (société anonyme) : la fiche garde son nom', function () {
    $espace = F::espace();

    lancer('crm:pharmacies:import-finess', ['fichier' => csvFiness([
        ['siret' => '94000014100011', 'statut' => 'Société anonyme (SA)', 'statut_code' => '73', 'nom_long' => 'ZZ SA TEST'],
    ]), '--workspace' => $espace, '--apply' => true]);

    $f = DB::table('companies')->where('siren', '940000141')->first();
    expect($f->denomination)->toBe('ZZ SA TEST')->and(json_decode($f->metadata, true))->not->toHaveKey('personne_physique');
});

it('personne physique : essai à blanc et apply annoncent les mêmes totaux', function () {
    $espace = F::espace();
    $csv = csvFiness([['siret' => '94000012100011', 'statut' => 'Personne Physique'], ['siret' => '94000012200011']]);

    $essai = lancer('crm:pharmacies:import-finess', ['fichier' => $csv, '--workspace' => $espace]);
    $apply = lancer('crm:pharmacies:import-finess', ['fichier' => $csv, '--workspace' => $espace, '--apply' => true]);

    expect($essai)->toContain('personnes physiques : 1 (fiches sans nom ni adresse)')->toContain('fiches à créer : 2');
    expect($apply)->toContain('personnes physiques : 1 (fiches sans nom ni adresse)')->toContain('fiches créées : 2');
});

it('affiche le message des colonnes manquantes, sans donnée personnelle', function () {
    $espace = F::espace();
    $chemin = tempnam(sys_get_temp_dir(), 'zzfiness');
    file_put_contents($chemin, "finess_et,siret\n990000001,94000013100011\n");

    $sortie = lancer('crm:pharmacies:import-finess', ['fichier' => $chemin, '--workspace' => $espace]);

    expect($sortie)->toContain('Colonnes manquantes : categorie');
});

it('classer-taille : effectif_min rempli et effectif_max vide ne change rien ; metadata vide gérée ; updated_at posé', function () {
    $espace = F::espace();
    lancer('crm:pharmacies:import-finess', ['fichier' => csvFiness([['siret' => '94000014100011'], ['siret' => '94000014200011']]), '--workspace' => $espace, '--apply' => true]);
    DB::table('companies')->where('siren', '940000141')->update(['effectif_range' => '12', 'effectif_min' => 7, 'effectif_max' => null]);
    DB::table('companies')->where('siren', '940000142')->update(['metadata' => '{}', 'updated_at' => '2020-01-01 00:00:00']);

    lancer('crm:pharmacies:classer-taille', ['--workspace' => $espace]);

    $un = DB::table('companies')->where('siren', '940000141')->first();
    expect((int) $un->effectif_min)->toBe(7)->and($un->effectif_max)->toBeNull();
    $deux = DB::table('companies')->where('siren', '940000142')->first();
    expect(json_decode($deux->metadata, true)['nb_officines_societe'])->toBe(1);
    expect($deux->updated_at > '2020-01-02')->toBeTrue();
});
