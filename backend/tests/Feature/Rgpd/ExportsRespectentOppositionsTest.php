<?php

use App\Models\Contact;
use App\Support\EligibiliteCampagne;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * 🔴 LES EXPORTS CSV IGNORAIENT LES OPPOSITIONS.
 *
 * Constaté le 2026-08-16, sur les trois exports :
 *   · `CompaniesController::export()` embarquait
 *     `nom prénom (rôle) email téléphone` de chaque contact, sans aucune garde
 *     — sur 4,29 M de fiches. La fuite la plus large du système ;
 *   · `JournalistsController::export()` filtrait `journalists.opt_out`, la
 *     colonne BOOLÉENNE de la fiche — pas les tables `opt_out` /
 *     `email_suppressions`, où atterrissent les oppositions venues du site et
 *     les effacements RGPD ;
 *   · `MediaController::export()` n'avait AUCUN filtre.
 *
 * Ces tests portent sur la garde partagée `exclureOpposes()`, appliquée
 * désormais aux trois. Ils couvrent les DEUX formes d'écriture d'une
 * opposition : adresse en clair, et empreinte seule (effacement).
 */
function opposer(string $email, bool $parEmpreinteSeulement = false, string $table = 'opt_out'): void
{
    $ligne = [
        'email' => $parEmpreinteSeulement ? null : $email,
        'email_hash' => hash('sha256', $email),
        'scope' => 'business',
        'source' => 'test',
        'created_at' => now(),
    ];

    // `email_suppressions` porte un `reason` NOT NULL, contraint à un
    // vocabulaire fermé (`hard_bounce | complaint | soft_bounce_threshold |
    // manual | invalid_syntax`). Les deux tables se ressemblent mais ne
    // s'écrivent pas pareil — c'est justement pour ça qu'on les teste
    // toutes les deux.
    // `email_suppressions` n'a pas non plus de `created_at` : elle porte
    // `suppressed_at`. Deux tables voisines, trois différences de schéma.
    if ($table === 'email_suppressions') {
        unset($ligne['created_at']);
        $ligne['reason'] = 'hard_bounce';
    }

    DB::table($table)->insert($ligne);
}

function contactAvec(?string $email, string $nom = 'Martin'): string
{
    $ws = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $ws, 'slug' => 'exp-' . Str::random(6), 'name' => 'WS',
        'settings' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);

    // `contacts.company_id` est NOT NULL (le rendre nullable est une évolution
    // encore reportée, cf. « étape 5 bis » du runbook) : un contact appartient
    // toujours à une entreprise.
    $companyId = DB::table('companies')->insertGetId([
        'workspace_id' => $ws,
        'siren' => str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
        'denomination' => 'Entreprise ' . $nom,
        'signals' => '{}', 'metadata' => '{}', 'quality_score' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('contacts')->insert([
        'workspace_id' => $ws, 'company_id' => $companyId,
        'last_name' => $nom, 'email' => $email,
        'sources' => '[]', 'metadata' => '{}',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $ws;
}

test('un contact opposé par ADRESSE EN CLAIR est exclu', function () {
    contactAvec('oppose@example.com');
    opposer('oppose@example.com');

    $restants = EligibiliteCampagne::exclureOpposes(
        Contact::query(),
        'contacts.email',
    )->count();

    expect($restants)->toBe(0);
});

test('un contact opposé par EMPREINTE SEULE (effacement) est exclu', function () {
    contactAvec('efface@example.com');
    opposer('efface@example.com', parEmpreinteSeulement: true);

    $restants = EligibiliteCampagne::exclureOpposes(
        Contact::query(),
        'contacts.email',
    )->count();

    expect($restants)->toBe(0);
});

test('un contact présent en liste de SUPPRESSION est exclu', function () {
    contactAvec('rebond@example.com');
    opposer('rebond@example.com', table: 'email_suppressions');

    $restants = EligibiliteCampagne::exclureOpposes(
        Contact::query(),
        'contacts.email',
    )->count();

    expect($restants)->toBe(0);
});

test('un contact SANS email traverse la garde — il ne s’est opposé à rien', function () {
    contactAvec(null, 'SansAdresse');
    opposer('quelquun@example.com');

    $restants = EligibiliteCampagne::exclureOpposes(
        Contact::query(),
        'contacts.email',
    )->count();

    // 🔑 C'est la raison d'être de `exclureOpposes` plutôt que
    // `appliquerContacts` : cette dernière impose `email IS NOT NULL` et aurait
    // retiré cette fiche de l'export. Une garde ne doit pas emporter plus que
    // ce qu'elle protège.
    expect($restants)->toBe(1);
});

test('un contact sans rapport reste exportable', function () {
    contactAvec('libre@example.com');
    opposer('quelquundautre@example.com');

    $restants = EligibiliteCampagne::exclureOpposes(
        Contact::query(),
        'contacts.email',
    )->count();

    expect($restants)->toBe(1);
});

test('la casse et les espaces ne permettent pas de contourner la garde', function () {
    contactAvec('  MiXtE@Example.COM  ');
    opposer('mixte@example.com', parEmpreinteSeulement: true);

    $restants = EligibiliteCampagne::exclureOpposes(
        Contact::query(),
        'contacts.email',
    )->count();

    expect($restants)->toBe(0);
});
