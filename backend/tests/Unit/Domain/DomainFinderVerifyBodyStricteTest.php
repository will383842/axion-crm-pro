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

// ── Relecture de #305 ──────────────────────────────────────────────────────

test('les formes juridiques et mots de structure ne sont pas exigés dans le titre', function (string $denomination) {
    $entreprise = vbsEntreprise(['denomination' => $denomination]);
    $page = vbsPage('<title>ZZ Cabinet Martin</title>', '<p>12 rue des Fleurs, 69100.</p>');

    expect(vbsVerifier($page, $entreprise))->toBeTrue();
})->with([
    'SELARL' => ['SELARL ZZ CABINET MARTIN'],
    'société + ets' => ['SOCIETE ETS ZZ CABINET MARTIN'],
    'groupe holding' => ['GROUPE ZZ CABINET MARTIN HOLDING'],
    'SCEA + article' => ['SCEA DU ZZ CABINET MARTIN'],
    'association aux' => ['ASSOCIATION AUX ZZ CABINET MARTIN'],
]);

test('la liste de la devinette (nameTokens, donc candidateDomains) est inchangée', function () {
    $finder = new DomainFinderService;

    expect($finder->nameTokens('SELARL ZZ Martin'))->toBe(['selarl', 'zz', 'martin'])
        ->and($finder->motsPourVerification('SELARL ZZ Martin'))->toBe(['zz', 'martin']);
});

test('le SIREN séparé par des points ou des tirets : ACCEPTÉE', function (string $mention) {
    expect(vbsVerifier(vbsPage('<title>Accueil</title>', "<footer>{$mention}</footer>")))->toBeTrue();
})->with([
    'points' => ['SIREN 941.234.567'],
    'tirets' => ['SIREN 941-234-567'],
    'SIRET à points' => ['SIRET 941.234.567.00012'],
]);

test('un SIREN à points ou tirets collé à d autres chiffres ne compte pas', function (string $mention) {
    expect(vbsVerifier(vbsPage('<title>Accueil</title>', "<p>{$mention}</p>")))->toBeFalse();
})->with([
    'téléphone à tirets' => ['Tél. 0-941-234-567'],
    'téléphone à points' => ['Tél. 09.41.23.45.67'],
    'chiffres devant' => ['Réf. 12 941.234.567'],
]);

test('une seule normalisation des deux côtés : « CŒUR » et « coeur » se rencontrent', function () {
    $page = vbsPage('<title>ZZ Coeur de Pain</title>', '<p>69100</p>');
    expect(vbsVerifier($page, vbsEntreprise(['denomination' => 'ZZ CŒUR DE PAIN'])))->toBeTrue();

    $page = vbsPage('<title>ZZ CŒUR DE PAIN</title>', '<p>69100</p>');
    expect(vbsVerifier($page, vbsEntreprise(['denomination' => 'zz coeur de pain'])))->toBeTrue();
});

test('une page en Windows-1252 est lue', function () {
    $page = (string) mb_convert_encoding(vbsPage('<title>ZZ Boulangerie Martin</title>', '<p>Située à Villeurbanne.</p>'), 'Windows-1252', 'UTF-8');

    expect(vbsVerifier($page))->toBeTrue();
});

test('le corps est tronqué à 1,5 Mo, sur une frontière de caractère', function () {
    $corps = str_repeat('é', DomainFinderService::CORPS_MAX_OCTETS); // 2 octets par caractère

    $tronque = DomainFinderService::tronquer($corps);

    expect(strlen($tronque))->toBeLessThanOrEqual(DomainFinderService::CORPS_MAX_OCTETS)
        ->and(strlen($tronque))->toBeGreaterThan(DomainFinderService::CORPS_MAX_OCTETS - 2)
        ->and(mb_check_encoding($tronque, 'UTF-8'))->toBeTrue()
        ->and(DomainFinderService::tronquer('court'))->toBe('court');
});

test('la devinette en lot ne lit que les 1,5 premiers Mo du corps', function () {
    // Le SIREN n'apparaît qu'après 1,5 Mo : il n'est pas lu.
    $page = vbsPage('<title>Accueil</title>', str_repeat('x', DomainFinderService::CORPS_MAX_OCTETS) . ' SIREN 941234567');
    Http::fake(['*' => Http::response($page, 200)]);

    expect((new DomainFinderService)->guessDomainsBatch([vbsEntreprise()]))->toBe([4242 => null]);
});
