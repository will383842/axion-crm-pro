<?php

/**
 * `crm:pharmacies:contacts-osm` — fixtures FICTIVES (ZZ). Verrouillé : rapprochement par téléphone ou par
 * GPS (80 m + même code postal), ambiguïté = rien d'écrit, jamais d'écrasement, fiche protégée intacte,
 * opposition respectée, essai à blanc par défaut.
 */

use App\Support\ListeSuppression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** @param list<array<string, string>> $lignes */
function csvOsm(array $lignes): string
{
    $chemin = tempnam(sys_get_temp_dir(), 'zzosm');
    $f = fopen($chemin, 'w');
    $defauts = ['lat' => '', 'lon' => '', 'cp' => '', 'telephone' => '', 'email' => '', 'site' => ''];
    fputcsv($f, array_keys($defauts), ',', '"', '');
    foreach ($lignes as $l) {
        fputcsv($f, array_values(array_merge($defauts, $l)), ',', '"', '');
    }
    fclose($f);

    return $chemin;
}

/** @param array<string, mixed> $attrs */
function ficheOsm(string $espace, array $attrs = []): int
{
    return F::fiche($espace, 'ZZ OFFICINE', array_merge(['naf' => '47.73Z', 'legal_form' => '5499'], $attrs));
}

function lancerOsm(string $espace, string $csv, bool $apply = true): string
{
    Artisan::call('crm:pharmacies:contacts-osm', array_filter(['--osm' => $csv, '--workspace' => $espace, '--apply' => $apply ?: null]));

    return Artisan::output();
}

it('rapproche par téléphone normalisé et ajoute e-mail et site sur champs vides', function () {
    $espace = F::espace();
    $id = ficheOsm($espace, ['phone' => '01 40 00 00 01']);

    $sortie = lancerOsm($espace, csvOsm([['telephone' => '+33 1 40 00 00 01', 'email' => 'Contact@Zz-Pharma.test', 'site' => 'www.zz-pharma.test']]));

    $c = DB::table('companies')->where('id', $id)->first();
    expect($c->email_generic)->toBe('contact@zz-pharma.test')->and($c->website)->toBe('http://www.zz-pharma.test')->and($c->website_method)->toBe('osm');
    expect(json_decode((string) $c->field_origins, true))->toMatchArray(['email_generic' => 'osm', 'website' => 'osm']);
    expect(json_decode((string) $c->metadata, true))->toMatchArray(['email_source' => 'osm', 'email_statut' => 'non_verifie']);
    expect($sortie)->toContain('points OSM lus : 1')->toContain('e-mails ajoutés : 1')->toContain('sites ajoutés : 1')->not->toContain('zz-pharma');
});

it('rapproche par GPS (moins de 80 m, même code postal) et ajoute le téléphone vide', function () {
    $espace = F::espace();
    $id = ficheOsm($espace, ['lat' => 48.1000, 'lon' => 2.1000, 'postcode' => '99003']);

    $sortie = lancerOsm($espace, csvOsm([['lat' => '48.10040', 'lon' => '2.10000', 'cp' => '99003', 'telephone' => '04 00 00 00 02', 'email' => 'a@zz.test']]));

    $c = DB::table('companies')->where('id', $id)->first();
    expect($c->phone)->toBe('0400000002')->and($c->email_generic)->toBe('a@zz.test');
    expect($sortie)->toContain('rapprochés : 1')->toContain('téléphones ajoutés : 1');
});

it('GPS trop loin ou code postal différent : aucun rapprochement', function () {
    $espace = F::espace();
    $a = ficheOsm($espace, ['lat' => 48.1, 'lon' => 2.1, 'postcode' => '99003']);
    $b = ficheOsm($espace, ['lat' => 48.2, 'lon' => 2.2, 'postcode' => '99004']);

    $sortie = lancerOsm($espace, csvOsm([
        ['lat' => '48.1020', 'lon' => '2.1', 'cp' => '99003', 'email' => 'loin@zz.test'],
        ['lat' => '48.2', 'lon' => '2.2', 'cp' => '99999', 'email' => 'autre@zz.test'],
    ]));

    expect(DB::table('companies')->whereIn('id', [$a, $b])->whereNotNull('email_generic')->count())->toBe(0);
    expect($sortie)->toContain('rapprochés : 0');
});

it('ambiguïté : deux fiches candidates, ou deux points pour une fiche, n\'écrivent rien', function () {
    $espace = F::espace();
    $a = ficheOsm($espace, ['lat' => 48.1, 'lon' => 2.1, 'postcode' => '99003']);
    $b = ficheOsm($espace, ['lat' => 48.1001, 'lon' => 2.1001, 'postcode' => '99003']);
    $c = ficheOsm($espace, ['phone' => '0140000009']);

    $sortie = lancerOsm($espace, csvOsm([
        ['lat' => '48.1', 'lon' => '2.1', 'cp' => '99003', 'email' => 'x@zz.test'],
        ['telephone' => '0140000009', 'email' => 'y@zz.test'],
        ['telephone' => '0140000009', 'email' => 'z@zz.test'],
    ]));

    expect(DB::table('companies')->whereIn('id', [$a, $b, $c])->whereNotNull('email_generic')->count())->toBe(0);
    expect($sortie)->toContain('ambigus (rien écrit) : 3')->toContain('e-mails ajoutés : 0');
});

it('n\'écrase jamais un e-mail, un site ou un téléphone existant', function () {
    $espace = F::espace();
    $id = ficheOsm($espace, ['phone' => '0140000001', 'email_generic' => 'garde@zz.test', 'website' => 'https://garde.zz.test']);

    $sortie = lancerOsm($espace, csvOsm([['telephone' => '0140000001', 'email' => 'nouveau@zz.test', 'site' => 'nouveau.zz.test']]));

    $c = DB::table('companies')->where('id', $id)->first();
    expect($c->email_generic)->toBe('garde@zz.test')->and($c->website)->toBe('https://garde.zz.test')->and($c->phone)->toBe('0140000001');
    expect($sortie)->toContain('e-mails ajoutés : 0')->toContain('sites ajoutés : 0');
});

it('une origine protégée (déclarée) bloque l\'écriture du champ vide', function () {
    $espace = F::espace();
    $id = ficheOsm($espace, ['phone' => '0140000001', 'field_origins' => json_encode(['email_generic' => 'declared'])]);

    lancerOsm($espace, csvOsm([['telephone' => '0140000001', 'email' => 'x@zz.test']]));

    expect(DB::table('companies')->where('id', $id)->value('email_generic'))->toBeNull();
});

it('fiche protégée, fermée, non officine, écartée ou personne physique : intacte', function () {
    $espace = F::espace();
    $ids = [];
    $ids[] = $p = ficheOsm($espace, ['phone' => '0140000001']);
    F::proteger($espace, $p, 'src:scraping-federations-2026');
    $ids[] = ficheOsm($espace, ['phone' => '0140000002', 'insee_ferme_le' => '2020-01-01']);
    $ids[] = ficheOsm($espace, ['phone' => '0140000003', 'metadata' => json_encode(['type_etablissement' => 'non_officine'])]);
    $ids[] = ficheOsm($espace, ['phone' => '0140000004', 'metadata' => json_encode(['ecartee_motif' => 'test'])]);
    $ids[] = ficheOsm($espace, ['phone' => '0140000005', 'legal_form' => '1000']);
    $ids[] = ficheOsm($espace, ['phone' => '0140000006', 'naf' => '56.10A']);

    $csv = [];
    foreach (range(1, 6) as $n) {
        $csv[] = ['telephone' => '014000000' . $n, 'email' => "p{$n}@zz.test", 'site' => "p{$n}.zz.test"];
    }
    $sortie = lancerOsm($espace, csvOsm($csv));

    expect(DB::table('companies')->whereIn('id', $ids)->where(fn ($q) => $q->whereNotNull('email_generic')->orWhereNotNull('website'))->count())->toBe(0);
    expect($sortie)->toContain('rapprochés : 0');
});

it('opposition sur le numéro ou l\'e-mail : fiche écartée', function () {
    $espace = F::espace();
    $a = ficheOsm($espace, ['phone' => '0140000001']);
    $b = ficheOsm($espace, ['phone' => '0140000002']);
    DB::table('opt_out')->insert(['phone' => '+33 1 40 00 00 01', 'scope' => 'business', 'source' => 'test', 'created_at' => now()]);
    ListeSuppression::inscrire('oppose@zz.test', ListeSuppression::MANUEL, 'test');

    $sortie = lancerOsm($espace, csvOsm([
        ['telephone' => '0140000001', 'email' => 'libre@zz.test'],
        ['telephone' => '0140000002', 'email' => 'oppose@zz.test'],
    ]));

    expect(DB::table('companies')->whereIn('id', [$a, $b])->whereNotNull('email_generic')->count())->toBe(0);
    expect($sortie)->toContain('écartés pour opposition : 2');
});

it('essai à blanc par défaut : totaux seulement, rien d\'écrit', function () {
    $espace = F::espace();
    $id = ficheOsm($espace, ['phone' => '0140000001']);

    $sortie = lancerOsm($espace, csvOsm([['telephone' => '0140000001', 'email' => 'x@zz.test']]), false);

    expect(DB::table('companies')->where('id', $id)->value('email_generic'))->toBeNull();
    expect($sortie)->toContain('ESSAI À BLANC')->toContain('e-mails à ajouter : 1');
});

it('ignore les e-mails invalides et ne touche pas un autre espace', function () {
    $autre = F::espace();
    $espace = F::espace();
    $id = ficheOsm($espace, ['phone' => '0140000001']);
    $ailleurs = ficheOsm($autre, ['phone' => '0140000001']);

    lancerOsm($espace, csvOsm([['telephone' => '0140000001', 'email' => 'pas-un-email']]));
    expect(DB::table('companies')->where('id', $id)->value('email_generic'))->toBeNull();

    lancerOsm($espace, csvOsm([['telephone' => '0140000001', 'email' => 'ok@zz.test']]));
    expect(DB::table('companies')->where('id', $id)->value('email_generic'))->toBe('ok@zz.test');
    expect(DB::table('companies')->where('id', $ailleurs)->value('email_generic'))->toBeNull();
});
