<?php

/**
 * LA LISTE DE SUPPRESSION — ce qui manquait avant tout premier envoi.
 *
 * Le CRM savait enregistrer une opposition volontaire (`opt_out`) mais RIEN
 * d'un rebond ni d'une plainte : les tables que le plan supposait présentes
 * (`dnc_entries`, `unsubscribes`, `email_events`) n'ont jamais existé.
 *
 * Sans elle, la deuxième campagne réécrit aux adresses mortes de la première.
 * Les fournisseurs mesurent ce taux : c'est le mécanisme exact par lequel un
 * domaine d'envoi se fait blacklister. Ces tests portent donc surtout sur ce
 * qui doit rester VRAI quand les signaux arrivent en désordre — deux fois, en
 * clair puis hachés, ou dans le mauvais ordre de gravité.
 */

use App\Models\Company;
use App\Models\Contact;
use App\Models\Workspace;
use App\Support\EligibiliteCampagne;
use App\Support\ListeSuppression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-supp', 'name' => 'WS', 'settings' => [],
    ]);
    cache()->flush();
});

function ficheAvecEmail(string $workspaceId, string $email, string $nom = 'Fiche'): Company
{
    return Company::create([
        'workspace_id' => $workspaceId,
        'siren' => (string) random_int(100000000, 999999999),
        'denomination' => $nom,
        'email_generic' => $email,
        'signals' => [],
        'metadata' => [],
    ]);
}

function noms(string $workspaceId): array
{
    return EligibiliteCampagne::appliquer(
        Company::query()->where('workspace_id', $workspaceId),
    )->pluck('denomination')->all();
}

test('un rebond dur retire la fiche des éligibles', function () {
    ficheAvecEmail($this->workspace->id, 'morte@acme.fr', 'MORTE');
    ficheAvecEmail($this->workspace->id, 'vivante@acme.fr', 'VIVANTE');

    expect(noms($this->workspace->id))->toHaveCount(2);

    ListeSuppression::inscrire('morte@acme.fr', ListeSuppression::REBOND_DUR, 'esp');

    expect(noms($this->workspace->id))->toBe(['VIVANTE']);
});

test('une plainte retire la fiche — c’est la réputation du domaine qui est en jeu', function () {
    ficheAvecEmail($this->workspace->id, 'plainte@acme.fr', 'PLAIGNANT');

    ListeSuppression::inscrire('plainte@acme.fr', ListeSuppression::PLAINTE, 'esp');

    expect(noms($this->workspace->id))->toBe([]);
});

test('la casse n’ouvre aucune brèche', function () {
    ficheAvecEmail($this->workspace->id, 'Contact@Acme.FR', 'FICHE');

    // Le signal arrive en minuscules, la fiche porte des majuscules : sans
    // `citext` et sans normalisation, la garde laisserait passer.
    ListeSuppression::inscrire('contact@acme.fr', ListeSuppression::REBOND_DUR, 'esp');

    expect(noms($this->workspace->id))->toBe([]);
});

test('un signal HACHÉ retire aussi la fiche', function () {
    ficheAvecEmail($this->workspace->id, 'site@acme.fr', 'FICHE');

    // Les signaux venus du site arrivent en empreinte, jamais en clair.
    DB::table('email_suppressions')->insert([
        'scope' => 'business',
        'email_hash' => hash('sha256', 'site@acme.fr'),
        'reason' => ListeSuppression::REBOND_DUR,
        'source' => 'site',
        'occurrences' => 1,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    expect(noms($this->workspace->id))->toBe([]);
});

test('une suppression du VIVIER ne retire pas une fiche business', function () {
    ficheAvecEmail($this->workspace->id, 'x@acme.fr', 'BUSINESS');

    ListeSuppression::inscrire('x@acme.fr', ListeSuppression::REBOND_DUR, 'esp', 'vivier');

    // Les deux univers restent étanches, y compris ici.
    expect(noms($this->workspace->id))->toBe(['BUSINESS']);
});

test('deux signaux ne créent pas deux lignes — ils incrémentent', function () {
    ListeSuppression::inscrire('rep@acme.fr', ListeSuppression::REBOND_DUR, 'esp');
    ListeSuppression::inscrire('rep@acme.fr', ListeSuppression::REBOND_DUR, 'esp');

    $lignes = DB::table('email_suppressions')->where('email', 'rep@acme.fr')->get();

    expect($lignes)->toHaveCount(1);
    expect((int) $lignes->first()->occurrences)->toBe(2);
});

test('🔴 une plainte ne se fait JAMAIS rétrograder par un rebond', function () {
    ListeSuppression::inscrire('grave@acme.fr', ListeSuppression::PLAINTE, 'esp');
    ListeSuppression::inscrire('grave@acme.fr', ListeSuppression::REBOND_TEMPORAIRE, 'esp');

    // Une plainte engage la réputation du domaine. Si un rebond mou arrivé
    // plus tard écrasait la raison, on perdrait la seule information qui
    // justifie de ne PLUS JAMAIS écrire à cette adresse.
    $ligne = DB::table('email_suppressions')->where('email', 'grave@acme.fr')->first();

    expect($ligne->reason)->toBe(ListeSuppression::PLAINTE);
});

test('un rebond TEMPORAIRE ne supprime pas au premier coup', function () {
    ficheAvecEmail($this->workspace->id, 'pleine@acme.fr', 'BOITE PLEINE');

    // Une boîte pleine n'est pas une adresse morte : supprimer au premier
    // rebond mou jetterait des contacts valides, sans bruit.
    expect(ListeSuppression::rebondTemporaire('pleine@acme.fr', 'esp'))->toBeFalse();
    expect(noms($this->workspace->id))->toBe(['BOITE PLEINE']);

    expect(ListeSuppression::rebondTemporaire('pleine@acme.fr', 'esp'))->toBeFalse();
    expect(noms($this->workspace->id))->toBe(['BOITE PLEINE']);

    // Au SEUIL, on supprime.
    expect(ListeSuppression::rebondTemporaire('pleine@acme.fr', 'esp'))->toBeTrue();
    expect(noms($this->workspace->id))->toBe([]);
});

test('estSupprimee répond sur l’adresse quelle que soit sa casse', function () {
    ListeSuppression::inscrire('Test@Acme.fr', ListeSuppression::MANUEL, 'console');

    expect(ListeSuppression::estSupprimee('test@acme.fr'))->toBeTrue();
    expect(ListeSuppression::estSupprimee('  TEST@ACME.FR  '))->toBeTrue();
    expect(ListeSuppression::estSupprimee('autre@acme.fr'))->toBeFalse();
    // Étanchéité des univers, encore.
    expect(ListeSuppression::estSupprimee('test@acme.fr', 'vivier'))->toBeFalse();
});

test('opposition ET suppression ferment chacune la porte, indépendamment', function () {
    ficheAvecEmail($this->workspace->id, 'oppose@acme.fr', 'OPPOSE');
    ficheAvecEmail($this->workspace->id, 'rebond@acme.fr', 'REBOND');
    ficheAvecEmail($this->workspace->id, 'ok@acme.fr', 'OK');

    DB::table('opt_out')->insert([
        'email' => 'oppose@acme.fr', 'scope' => 'business', 'source' => 'site', 'created_at' => now(),
    ]);
    ListeSuppression::inscrire('rebond@acme.fr', ListeSuppression::REBOND_DUR, 'esp');

    // Les deux listes restent SÉPARÉES — l'une dit une volonté, l'autre un
    // fait technique — mais pour l'envoi, l'une comme l'autre interdit.
    expect(noms($this->workspace->id))->toBe(['OK']);
});

/**
 * ── LA COUVERTURE : « ça marche pour TOUS les contacts ? » (question de Will)
 *
 * Non, pas au départ. La garde ne couvrait que `companies.email_generic`
 * (255 290 adresses) et laissait de côté `contacts.email` — 410 481 adresses,
 * soit 1,6 fois plus. La MAJORITÉ des envois possibles échappait donc à toute
 * vérification d'opposition et de rebond.
 */
test('les adresses de PERSONNES sont protégées comme celles des entreprises', function () {
    $entreprise = Company::create([
        'workspace_id' => $this->workspace->id,
        'siren' => (string) random_int(100000000, 999999999),
        'denomination' => 'HOTE', 'signals' => [], 'metadata' => [],
    ]);

    $joignable = Contact::create([
        'workspace_id' => $this->workspace->id, 'company_id' => $entreprise->id,
        'last_name' => 'JOIGNABLE', 'email' => 'ok@acme.fr', 'sources' => [], 'metadata' => [],
    ]);
    Contact::create([
        'workspace_id' => $this->workspace->id, 'company_id' => $entreprise->id,
        'last_name' => 'REBONDIE', 'email' => 'morte@acme.fr', 'sources' => [], 'metadata' => [],
    ]);

    ListeSuppression::inscrire('morte@acme.fr', ListeSuppression::REBOND_DUR, 'esp');

    $restants = EligibiliteCampagne::appliquerContacts(
        Contact::query()->where('workspace_id', $this->workspace->id),
    )->pluck('last_name')->all();

    expect($restants)->toBe(['JOIGNABLE']);
    expect($joignable->email)->toBe('ok@acme.fr');
});

test('une opposition retire aussi une PERSONNE', function () {
    $entreprise = Company::create([
        'workspace_id' => $this->workspace->id,
        'siren' => (string) random_int(100000000, 999999999),
        'denomination' => 'HOTE', 'signals' => [], 'metadata' => [],
    ]);
    Contact::create([
        'workspace_id' => $this->workspace->id, 'company_id' => $entreprise->id,
        'last_name' => 'OPPOSEE', 'email' => 'non@acme.fr', 'sources' => [], 'metadata' => [],
    ]);

    DB::table('opt_out')->insert([
        'email' => 'non@acme.fr', 'scope' => 'business', 'source' => 'site', 'created_at' => now(),
    ]);

    expect(EligibiliteCampagne::appliquerContacts(
        Contact::query()->where('workspace_id', $this->workspace->id),
    )->count())->toBe(0);
});

/**
 * 🔴 LE POINT DE PASSAGE OBLIGÉ du futur moteur d'envoi.
 *
 * Une audience est une PHOTO : entre sa constitution et l'envoi, une
 * opposition peut arriver. Filtrer la liste ne suffit donc pas — il faut
 * re-poser la question juste avant d'écrire, adresse par adresse. Cette
 * méthode vaut pour TOUTE source : entreprise, personne, journaliste, import
 * ponctuel, ligne collée à la main.
 */
test('peutRecevoir répond sur une adresse seule, quelle que soit sa provenance', function () {
    ListeSuppression::inscrire('rebond@acme.fr', ListeSuppression::REBOND_DUR, 'esp');
    DB::table('opt_out')->insert([
        'email' => 'oppose@acme.fr', 'scope' => 'business', 'source' => 'site', 'created_at' => now(),
    ]);

    expect(EligibiliteCampagne::peutRecevoir('libre@acme.fr'))->toBeTrue();
    expect(EligibiliteCampagne::peutRecevoir('rebond@acme.fr'))->toBeFalse();
    expect(EligibiliteCampagne::peutRecevoir('oppose@acme.fr'))->toBeFalse();

    // Casse et espaces ne doivent ouvrir aucune brèche : une adresse collée
    // depuis un tableur arrive rarement propre.
    expect(EligibiliteCampagne::peutRecevoir('  REBOND@Acme.FR '))->toBeFalse();

    // Univers étanches jusqu'ici aussi.
    expect(EligibiliteCampagne::peutRecevoir('rebond@acme.fr', 'vivier'))->toBeTrue();

    // Une adresse vide n'est jamais joignable — un envoi « à personne » doit
    // échouer franchement, pas partir dans le vide.
    expect(EligibiliteCampagne::peutRecevoir(''))->toBeFalse();
});
