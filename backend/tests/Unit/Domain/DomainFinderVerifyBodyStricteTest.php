<?php

/**
 * `DomainFinderService::verifyBody()` — LA RÈGLE STRICTE (lot N4 « fermer le
 * robinet », 03/10/2026).
 *
 * Constat en production : 824 306 fiches au site DEVINÉ, dont france.fr
 * (3 878 fiches), maison.fr (2 974), paris.fr (2 232)… L'ancienne règle
 * acceptait une page dès que deux mots du nom y apparaissaient n'importe où,
 * ou un mot et la ville. Désormais, une page n'est acceptée que si elle porte
 * le SIREN (ou le SIRET) de l'entreprise, OU son nom dans l'identité de la
 * page (<title>, og:site_name, <h1>) ET son code postal ou sa ville.
 *
 * Fixtures FICTIVES (dépôt public) : noms « ZZ », SIREN 94xxxxxxx, domaines
 * `.example.invalid`.
 */

use App\Models\Company;
use App\Services\Domain\DomainFinderService;
use Illuminate\Support\Facades\Http;

/** @param  array<string, mixed>  $attrs */
function vbsEntreprise(array $attrs = []): Company
{
    return (new Company)->forceFill(array_merge([
        'id' => 4242,
        'siren' => '941234567',
        'denomination' => 'ZZ Boulangerie Martin',
        'city_name' => 'Villeurbanne',
        'postcode' => '69100',
    ], $attrs));
}

/** Une page HTML assez longue pour être jugée (200 caractères visibles au moins). */
function vbsPage(string $tete, string $corps): string
{
    $remplissage = str_repeat(' Nos produits sont faits chaque matin avec soin, venez nous rendre visite.', 4);

    return "<!doctype html><html><head>{$tete}</head><body>{$corps}<p>{$remplissage}</p></body></html>";
}

function vbsVerifier(string $page, ?Company $entreprise = null, ?string $domaine = null): bool
{
    $finder = new DomainFinderService;
    $entreprise ??= vbsEntreprise();

    return $finder->verifyBody($page, $entreprise, $finder->nameTokens((string) $entreprise->denomination), $domaine);
}

test('deux mots du nom n importe où, sans SIREN ni code postal : REFUSÉE (l ancienne règle l acceptait)', function () {
    $page = vbsPage('<title>Accueil</title>', '<p>La boulangerie de M. Martin, au coin de la rue. ZZ.</p>');

    expect(vbsVerifier($page))->toBeFalse();

    // Témoin : la règle d'avant disait oui. C'est elle qui a posé 824 000 sites.
    $finder = new DomainFinderService;
    expect($finder->correspondanceLache($page, vbsEntreprise(), $finder->nameTokens('ZZ Boulangerie Martin')))->toBeTrue();
});

test('le SIREN dans la page : ACCEPTÉE, d un bloc, espacé, en SIRET ou dans le numéro de TVA', function (string $mention) {
    $page = vbsPage('<title>Accueil</title>', "<footer>Mentions légales — {$mention}</footer>");

    expect(vbsVerifier($page))->toBeTrue();
})->with([
    'd un bloc' => ['SIREN 941234567'],
    'espacé' => ['RCS Lyon 941 234 567'],
    'insécables' => ["SIREN 941\u{00A0}234\u{00A0}567"],
    'SIRET' => ['SIRET 941 234 567 00012'],
    'TVA intracommunautaire' => ['TVA FR 12 941234567'],
]);

test('un SIREN collé à un autre chiffre (un numéro de téléphone) ne compte pas', function () {
    // « 0941234567 » contient le SIREN fictif : ce n'est pas lui.
    $page = vbsPage('<title>Accueil</title>', '<p>Appelez le 0941234567.</p>');

    expect(vbsVerifier($page))->toBeFalse();
});

test('le nom dans le <h1> et le code postal dans la page : ACCEPTÉE', function () {
    $page = vbsPage('<title>Accueil</title>', '<h1>ZZ <em>Boulangerie</em> Martin</h1><address>12 rue des Fleurs, 69100</address>');

    expect(vbsVerifier($page))->toBeTrue();
});

test('le nom dans le <title> et la ville : ACCEPTÉE ; dans og:site_name et le code postal : ACCEPTÉE', function () {
    expect(vbsVerifier(vbsPage('<title>ZZ Boulangerie Martin — pains</title>', '<p>À Villeurbanne depuis 1990.</p>')))->toBeTrue()
        ->and(vbsVerifier(vbsPage('<meta content="ZZ Boulangerie Martin" property="og:site_name">', '<p>69100</p>')))->toBeTrue();
});

test('le nom dans le titre SANS code postal ni ville : REFUSÉE', function () {
    expect(vbsVerifier(vbsPage('<title>ZZ Boulangerie Martin</title>', '<p>Bienvenue.</p>')))->toBeFalse();
});

test('le code postal présent mais le nom seulement dans le corps : REFUSÉE', function () {
    $page = vbsPage('<title>Accueil</title>', '<p>ZZ Boulangerie Martin, 69100 Villeurbanne.</p>');

    expect(vbsVerifier($page))->toBeFalse();
});

test('il faut TOUS les mots du nom dans le titre : « PARIS LIVE » n est pas paris.fr', function () {
    $entreprise = vbsEntreprise(['denomination' => 'PARIS LIVE', 'city_name' => 'Paris', 'postcode' => '75001']);
    $page = vbsPage('<title>Paris.fr — Site officiel de la Ville de Paris</title><meta property="og:site_name" content="Paris">', '<p>Mairie de Paris, 75001 Paris.</p>');

    expect(vbsVerifier($page, $entreprise, 'paris.fr'))->toBeFalse();
});

test('un titre qui ne fait que recopier le domaine essayé ne prouve rien', function () {
    $page = vbsPage('<title>zz-boulangerie-martin.fr</title>', '<p>Ce domaine est enregistré. 69100.</p>');

    expect(vbsVerifier($page, null, 'zz-boulangerie-martin.fr'))->toBeFalse()
        // Sans le domaine (appel externe), le titre compterait : le domaine
        // est donc transmis par toute la chaîne de devinette.
        ->and(vbsVerifier($page))->toBeTrue();
});

test('une page vide ou presque est refusée, même avec le SIREN', function () {
    expect(vbsVerifier('<html><title>ZZ Boulangerie Martin</title><p>941234567 69100</p></html>'))->toBeFalse();
});

test('la devinette en lot (guessDomainsBatch) applique la règle stricte : deux mots du nom ne suffisent plus', function () {
    Http::fake(['*' => Http::response(vbsPage('<title>Accueil</title>', '<p>Boulangerie Martin, ZZ.</p>'), 200)]);

    $urls = (new DomainFinderService)->guessDomainsBatch([vbsEntreprise()]);

    expect($urls)->toBe([4242 => null]);
});

test('la devinette en lot accepte une page qui porte le SIREN', function () {
    Http::fake(['*' => Http::response(vbsPage('<title>Accueil</title>', '<p>SIREN 941 234 567</p>'), 200)]);

    $urls = (new DomainFinderService)->guessDomainsBatch([vbsEntreprise()]);

    expect($urls[4242])->toStartWith('https://')->toEndWith('/');
});

test('findAvecMethode dit par quelle stratégie le site a été trouvé', function () {
    Http::preventStrayRequests();
    $entreprise = vbsEntreprise(['signals' => ['legal' => ['siteweb' => 'https://www.zz-annuaire.example.invalid/a-propos']]]);

    expect((new DomainFinderService)->findAvecMethode($entreprise))
        ->toBe(['url' => 'https://zz-annuaire.example.invalid/', 'methode' => DomainFinderService::METHODE_ANNUAIRE]);
});
