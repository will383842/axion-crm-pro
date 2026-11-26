<?php

/**
 * `crm:entreprises:contacts-osm` — fixtures FICTIVES (ZZ). Verrouillé : égalité exacte du SIRET, e-mail
 * générique seulement, téléphone sur champ vide, site JAMAIS écrit dans `website` (candidat dans
 * `metadata`), licence ODbL et attribution, essai à blanc par défaut, fiche protégée et opposition respectées.
 */

use App\Crm\FichesProtegees;
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
    $csv = F::csvSiret([['siret' => '94000000000012', 'email' => 'contact@zz-entreprise.test', 'telephone' => '01 40 00 00 01', 'site' => 'www.zz-entreprise.test']]);

    $sortie = lancerSiret($espace, $csv);

    $c = DB::table('companies')->where('id', $id)->first();
    expect($c->email_generic)->toBe('contact@zz-entreprise.test')->and($c->phone)->toBe('0140000001')->and($c->website)->toBeNull();
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

    $sortie = lancerSiret($espace, F::csvSiret([['siret' => '94000000000012', 'email' => 'contact@zz.test']]), false);

    expect(DB::table('companies')->where('id', $id)->value('email_generic'))->toBeNull();
    expect($sortie)->toContain('ESSAI À BLANC')->toContain('e-mails à ajouter : 1');
});

it('ne rapproche jamais sur un SIRET voisin (égalité exacte)', function () {
    $espace = F::espace();
    $id = F::fiche($espace, 'ZZ ENTREPRISE', ['siret' => '94000000000012', 'naf' => '62.01Z', 'legal_form' => '5499']);

    lancerSiret($espace, F::csvSiret([['siret' => '94000000000013', 'email' => 'contact@zz.test']]));

    expect(DB::table('companies')->where('id', $id)->value('email_generic'))->toBeNull();
});

it('n\'écrase jamais un champ rempli', function () {
    $espace = F::espace();
    $id = F::fiche($espace, 'ZZ ENTREPRISE', ['siret' => '94000000000012', 'naf' => '62.01Z', 'legal_form' => '5499', 'phone' => '0140000009', 'email_generic' => 'info@zz-ancien.test']);

    lancerSiret($espace, F::csvSiret([['siret' => '94000000000012', 'email' => 'contact@zz-nouveau.test', 'telephone' => '01 40 00 00 01']]));

    $c = DB::table('companies')->where('id', $id)->first();
    expect($c->email_generic)->toBe('info@zz-ancien.test')->and($c->phone)->toBe('0140000009');
});

it('écarte la fiche dont le numéro est sur la liste d\'opposition', function () {
    $espace = F::espace();
    $id = F::fiche($espace, 'ZZ ENTREPRISE', ['siret' => '94000000000012', 'naf' => '62.01Z', 'legal_form' => '5499']);
    DB::table('opt_out')->insert(['phone' => '+33 1 40 00 00 01', 'scope' => 'business', 'source' => 'test', 'created_at' => now()]);

    $sortie = lancerSiret($espace, F::csvSiret([['siret' => '94000000000012', 'telephone' => '01 40 00 00 01', 'email' => 'contact@zz.test']]));

    expect(DB::table('companies')->where('id', $id)->value('email_generic'))->toBeNull();
    expect($sortie)->toContain('écartés pour opposition : 1');
});

it('ne touche pas une fiche protégée', function () {
    $espace = F::espace();
    $id = F::fiche($espace, 'ZZ ENTREPRISE', ['siret' => '94000000000012', 'naf' => '62.01Z', 'legal_form' => '5499']);
    F::lier($espace, $id, F::tag($espace, FichesProtegees::TAG_FEDERATIONS));

    $sortie = lancerSiret($espace, F::csvSiret([['siret' => '94000000000012', 'email' => 'contact@zz.test', 'telephone' => '01 40 00 00 01']]));

    expect(DB::table('companies')->where('id', $id)->value('email_generic'))->toBeNull();
    expect(DB::table('companies')->where('id', $id)->value('phone'))->toBeNull();
    expect($sortie)->toContain('exclues (fiche inéligible, rien écrit) : 1');
});

it('ne touche pas une fiche fermée', function () {
    $espace = F::espace();
    $id = F::fiche($espace, 'ZZ ENTREPRISE', ['siret' => '94000000000012', 'naf' => '62.01Z', 'legal_form' => '5499', 'insee_ferme_le' => '2020-01-01']);

    $sortie = lancerSiret($espace, F::csvSiret([['siret' => '94000000000012', 'email' => 'contact@zz.test', 'telephone' => '01 40 00 00 01']]));

    expect(DB::table('companies')->where('id', $id)->value('email_generic'))->toBeNull();
    expect(DB::table('companies')->where('id', $id)->value('phone'))->toBeNull();
    expect($sortie)->toContain('exclues (fiche inéligible, rien écrit) : 1');
});

it('ne touche pas une personne physique', function (string $forme) {
    $espace = F::espace();
    $id = F::fiche($espace, 'ZZ PERSONNE', ['siret' => '94000000000012', 'naf' => '62.01Z', 'legal_form' => $forme]);

    $sortie = lancerSiret($espace, F::csvSiret([['siret' => '94000000000012', 'email' => 'contact@zz.test', 'telephone' => '01 40 00 00 01']]));

    expect(DB::table('companies')->where('id', $id)->value('email_generic'))->toBeNull();
    expect(DB::table('companies')->where('id', $id)->value('phone'))->toBeNull();
    expect($sortie)->toContain('exclues (fiche inéligible, rien écrit) : 1');
})->with([[''], ['1000']]);

it('n\'écrit rien quand deux fiches de l\'espace portent le même SIRET', function () {
    $espace = F::espace();
    $a = F::fiche($espace, 'ZZ UN', ['siret' => '94000000000012', 'naf' => '62.01Z', 'legal_form' => '5499']);
    $b = F::fiche($espace, 'ZZ DEUX', ['siret' => '94000000000012', 'naf' => '62.01Z', 'legal_form' => '5499']);

    $sortie = lancerSiret($espace, F::csvSiret([['siret' => '94000000000012', 'email' => 'contact@zz.test', 'telephone' => '01 40 00 00 01']]));

    expect(DB::table('companies')->whereIn('id', [$a, $b])->whereNotNull('email_generic')->count())->toBe(0);
    expect($sortie)->toContain('ambigus (rien écrit) : 1');
});

it('ne compte pas de site à noter quand le site de la fiche est déjà renseigné', function () {
    $espace = F::espace();
    F::fiche($espace, 'ZZ ENTREPRISE', ['siret' => '94000000000012', 'naf' => '62.01Z', 'legal_form' => '5499', 'website' => 'http://www.zz-deja.test']);

    $sortie = lancerSiret($espace, F::csvSiret([['siret' => '94000000000012', 'site' => 'www.zz-entreprise.test']]), false);

    expect($sortie)->toContain('sites candidats à noter : 0');
});
