<?php

/**
 * `crm:entreprises:contacts-osm` — fixtures FICTIVES (ZZ). Verrouillé : égalité exacte du SIRET, e-mail
 * générique seulement, téléphone sur champ vide, site JAMAIS écrit dans `website` (candidat dans
 * `metadata`), licence ODbL et attribution, essai à blanc par défaut, fiche protégée et opposition respectées.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function lancerSiret(string $espace, string $csv, bool $apply = true): string
{
    Artisan::call('crm:entreprises:contacts-osm', array_filter(['--osm' => $csv, '--workspace' => $espace, '--apply' => $apply ?: null]));

    return Artisan::output();
}

it('ajoute e-mail générique et téléphone sur champs vides, et garde le site comme candidat', function () {
    $espace = F::espace();
    $id = F::fiche($espace, 'ZZ ENTREPRISE', ['siret' => '94000000000012', 'naf' => '62.01Z', 'legal_form' => '5499']);
    $csv = csvSiret([['siret' => '94000000000012', 'email' => 'contact@zz-entreprise.test', 'telephone' => '01 40 00 00 01', 'site' => 'www.zz-entreprise.test']]);

    $sortie = lancerSiret($espace, $csv);

    $c = DB::table('companies')->where('id', $id)->first();
    expect($c->email_generic)->toBe('contact@zz-entreprise.test')->and($c->phone)->not->toBe('')->and($c->website)->toBeNull();
    $meta = json_decode((string) $c->metadata, true);
    expect($meta['contacts_osm_siret'])->toMatchArray(['licence' => 'ODbL']);
    expect($meta['contacts_osm_siret']['attribution'])->toContain('OpenStreetMap');
    expect($meta['contacts_osm_siret']['site_candidat'])->toBe('http://www.zz-entreprise.test');
    expect(json_decode((string) $c->field_origins, true))->toMatchArray(['email_generic' => 'osm', 'phone' => 'osm'])->not->toHaveKey('website');
    expect($sortie)->toContain('e-mails ajoutés : 1')->toContain('sites candidats notés : 1')->not->toContain('zz-entreprise');
});

it('n\'écrit rien en essai à blanc', function () {
    $espace = F::espace();
    $id = F::fiche($espace, 'ZZ ENTREPRISE', ['siret' => '94000000000012', 'naf' => '62.01Z', 'legal_form' => '5499']);

    $sortie = lancerSiret($espace, csvSiret([['siret' => '94000000000012', 'email' => 'contact@zz.test']]), false);

    expect(DB::table('companies')->where('id', $id)->value('email_generic'))->toBeNull();
    expect($sortie)->toContain('ESSAI À BLANC')->toContain('e-mails à ajouter : 1');
});

it('ne rapproche jamais sur un SIRET voisin (égalité exacte)', function () {
    $espace = F::espace();
    $id = F::fiche($espace, 'ZZ ENTREPRISE', ['siret' => '94000000000012', 'naf' => '62.01Z', 'legal_form' => '5499']);

    lancerSiret($espace, csvSiret([['siret' => '94000000000013', 'email' => 'contact@zz.test']]));

    expect(DB::table('companies')->where('id', $id)->value('email_generic'))->toBeNull();
});

it('n\'écrase jamais un champ rempli', function () {
    $espace = F::espace();
    $id = F::fiche($espace, 'ZZ ENTREPRISE', ['siret' => '94000000000012', 'naf' => '62.01Z', 'legal_form' => '5499', 'phone' => '0140000009', 'email_generic' => 'info@zz-ancien.test']);

    lancerSiret($espace, csvSiret([['siret' => '94000000000012', 'email' => 'contact@zz-nouveau.test', 'telephone' => '01 40 00 00 01']]));

    $c = DB::table('companies')->where('id', $id)->first();
    expect($c->email_generic)->toBe('info@zz-ancien.test')->and($c->phone)->toBe('0140000009');
});

it('écarte la fiche dont le numéro est sur la liste d\'opposition', function () {
    $espace = F::espace();
    $id = F::fiche($espace, 'ZZ ENTREPRISE', ['siret' => '94000000000012', 'naf' => '62.01Z', 'legal_form' => '5499']);
    DB::table('opt_out')->insert(['phone' => '+33 1 40 00 00 01', 'scope' => 'business', 'source' => 'test', 'created_at' => now()]);

    $sortie = lancerSiret($espace, csvSiret([['siret' => '94000000000012', 'telephone' => '01 40 00 00 01', 'email' => 'contact@zz.test']]));

    expect(DB::table('companies')->where('id', $id)->value('email_generic'))->toBeNull();
    expect($sortie)->toContain('écartés pour opposition : 1');
});
