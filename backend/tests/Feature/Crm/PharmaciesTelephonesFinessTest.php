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
    $defauts = [
        'finess_et' => '990000001', 'finess_ej' => '990000002', 'siret' => '', 'categorie' => '620', 'etat' => 'A', 'telephone' => '',
        'telecopie' => '', 'courriel' => '', 'numero_voie' => '', 'type_voie' => '', 'libelle_voie' => '', 'complement_voie' => '',
        'lieu_dit' => '', 'adresse_ligne_4' => '', 'code_postal' => '', 'commune_acheminement' => '', 'latitude' => '', 'longitude' => '',
        'date_ouverture' => '',
    ];
    fputcsv($f, array_keys($defauts), ';', '"', '');
    foreach ($lignes as $l) {
        fputcsv($f, array_values(array_merge($defauts, $l)), ';', '"', '');
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

/** @param array<string, string> $ej finess_ej → téléphone */
function csvEj(array $ej): string
{
    $chemin = tempnam(sys_get_temp_dir(), 'zzej');
    $f = fopen($chemin, 'w');
    fputcsv($f, ['finess_ej', 'raison_sociale', 'telephone', 'telecopie'], ';', '"', '');
    foreach ($ej as $k => $tel) {
        fputcsv($f, [$k, 'ZZ SOCIETE', $tel, '0140009999'], ';', '"', '');
    }
    fclose($f);

    return $chemin;
}

function lancerTel(string $espace, string $csv, bool $apply = true, ?string $ej = null): string
{
    Artisan::call('crm:pharmacies:telephones-finess', array_filter(['fichier' => $csv, 'entites' => $ej, '--workspace' => $espace, '--apply' => $apply ?: null]));

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

it('ajoute adresse, GPS et date d\'ouverture sur champs vides, sans jamais écraser', function () {
    $espace = F::espace();
    [$vide, $sv] = ficheTel($espace);
    [$plein, $sp] = ficheTel($espace, [
        'address' => '1 RUE ZZ EXISTANTE', 'postcode' => '99999', 'city' => 'ZZVILLE', 'city_name' => 'ZZVILLE',
        'lat' => 10.5, 'lon' => 20.5, 'metadata' => json_encode(['finess_date_ouverture' => '1999-01-01']),
    ]);
    $adr = ['numero_voie' => '12', 'type_voie' => 'RUE', 'libelle_voie' => 'DU ZZ', 'code_postal' => '99001', 'commune_acheminement' => 'ZZTOWN', 'latitude' => '48.85', 'longitude' => '2,35', 'date_ouverture' => '2001-03-04'];

    $sortie = lancerTel($espace, csvTel([['siret' => $sv] + $adr, ['siret' => $sp] + $adr]));

    $v = DB::table('companies')->where('id', $vide)->first();
    expect($v->address)->toBe('12 RUE DU ZZ')->and($v->postcode)->toBe('99001')->and($v->city)->toBe('ZZTOWN')->and($v->city_name)->toBe('ZZTOWN');
    expect((float) $v->lat)->toBe(48.85)->and((float) $v->lon)->toBe(2.35);
    expect(json_decode((string) $v->metadata, true)['finess_date_ouverture'])->toBe('2001-03-04');
    $p = DB::table('companies')->where('id', $plein)->first();
    expect($p->address)->toBe('1 RUE ZZ EXISTANTE')->and($p->postcode)->toBe('99999')->and($p->city)->toBe('ZZVILLE')
        ->and((float) $p->lat)->toBe(10.5)->and((float) $p->lon)->toBe(20.5);
    expect(json_decode((string) $p->metadata, true)['finess_date_ouverture'])->toBe('1999-01-01');
    expect($sortie)->toContain('adresses complétées ajoutés : 1')->toContain('coordonnées GPS ajoutés : 1')
        ->toContain('dates d\'ouverture ajoutés : 1')->not->toContain('RUE DU ZZ')->not->toContain('48.85');
});

it('complète seulement les champs d\'adresse vides (partiel)', function () {
    $espace = F::espace();
    [$id, $siret] = ficheTel($espace, ['address' => '5 AVENUE ZZ', 'postcode' => null, 'city' => '']);

    lancerTel($espace, csvTel([['siret' => $siret, 'numero_voie' => '9', 'libelle_voie' => 'AUTRE', 'code_postal' => '99002', 'commune_acheminement' => 'ZZBOURG']]));

    $c = DB::table('companies')->where('id', $id)->first();
    expect($c->address)->toBe('5 AVENUE ZZ')->and($c->postcode)->toBe('99002')->and($c->city)->toBe('ZZBOURG');
});

it('personne physique : ni adresse, ni GPS, ni date, ni téléphone', function () {
    $espace = F::espace();
    [$id, $siret] = ficheTel($espace, ['legal_form' => '1000']);

    lancerTel($espace, csvTel([['siret' => $siret, 'telephone' => '0140000001', 'numero_voie' => '3', 'code_postal' => '99003', 'latitude' => '48.1', 'longitude' => '2.1', 'date_ouverture' => '2001-01-01']]), true, csvEj(['990000002' => '0140000008']));

    $c = DB::table('companies')->where('id', $id)->first();
    expect($c->phone)->toBeNull()->and($c->address)->toBeNull()->and($c->postcode)->toBeNull()->and($c->lat)->toBeNull();
    expect(json_decode((string) $c->metadata, true) ?? [])->not->toHaveKey('finess_date_ouverture');
});

it('téléphone de la société exploitante : seulement sans téléphone propre et phone vide', function () {
    $espace = F::espace();
    [$a, $sa] = ficheTel($espace);                              // pas de tel propre → repli EJ
    [$b, $sb] = ficheTel($espace);                              // tel propre → pas de repli
    [$c, $sc] = ficheTel($espace, ['phone' => '0499999999']);   // phone déjà rempli → intact
    [$d, $sd] = ficheTel($espace);                              // EJ sans téléphone
    $ej = csvEj(['990000002' => '01 40 00 00 08']);

    $sortie = lancerTel($espace, csvTel([
        ['siret' => $sa], ['siret' => $sb, 'telephone' => '0140000001'], ['siret' => $sc], ['siret' => $sd, 'finess_ej' => '990000077'],
    ]), true, $ej);

    expect(DB::table('companies')->where('id', $a)->value('phone'))->toBe('0140000008');
    expect(json_decode((string) DB::table('companies')->where('id', $a)->value('metadata'), true)['telephone_source'])->toBe('finess_ej');
    expect(DB::table('companies')->where('id', $b)->value('phone'))->toBe('0140000001');
    expect(json_decode((string) DB::table('companies')->where('id', $b)->value('metadata'), true)['telephone_source'])->toBe('finess');
    expect(DB::table('companies')->where('id', $c)->value('phone'))->toBe('0499999999');
    expect(DB::table('companies')->where('id', $d)->value('phone'))->toBeNull();
    expect($sortie)->toContain('téléphones société exploitante ajoutés : 1')->toContain('téléphones ajoutés : 1')->not->toContain('0140000008');
});

it('écarte aussi un téléphone de société exploitante en opposition', function () {
    $espace = F::espace();
    [$id, $siret] = ficheTel($espace);
    DB::table('opt_out')->insert(['phone' => '0140000008', 'scope' => 'business', 'source' => 'test', 'created_at' => now()]);

    $sortie = lancerTel($espace, csvTel([['siret' => $siret]]), true, csvEj(['990000002' => '01 40 00 00 08']));

    expect(DB::table('companies')->where('id', $id)->value('phone'))->toBeNull();
    expect($sortie)->toContain('écartés pour opposition : 1');
});

it('n\'importe jamais le fax ni le courriel', function () {
    $espace = F::espace();
    [$id, $siret] = ficheTel($espace);

    lancerTel($espace, csvTel([['siret' => $siret, 'telecopie' => '0140001234', 'courriel' => 'zz@zz.example.invalid']]), true, csvEj(['990000002' => '']));

    $c = DB::table('companies')->where('id', $id)->first();
    expect($c->phone)->toBeNull();
    expect(json_encode($c))->not->toContain('0140001234')->not->toContain('0140009999')->not->toContain('zz@zz.example');
});

it('essai à blanc : aucun ajout étendu écrit, totaux seulement', function () {
    $espace = F::espace();
    [$id, $siret] = ficheTel($espace);

    $sortie = lancerTel($espace, csvTel([['siret' => $siret, 'code_postal' => '99004', 'latitude' => '48.1', 'longitude' => '2.1', 'date_ouverture' => '2001-01-01']]), false, csvEj(['990000002' => '0140000008']));

    $c = DB::table('companies')->where('id', $id)->first();
    expect($c->phone)->toBeNull()->and($c->postcode)->toBeNull()->and($c->lat)->toBeNull();
    expect($sortie)->toContain('téléphones société exploitante à ajouter : 1')->toContain('coordonnées GPS à ajouter : 1')->not->toContain('99004');
});
