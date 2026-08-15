<?php

/**
 * LA LISTE DES CONTACTS ÉTAIT UN BOUCHON VIDE — ET DEUX TESTS LA COUVRAIENT.
 *
 * `ContactsController::index()` renvoyait `['data' => [], 'meta' => ['total' => 0]]`
 * en dur. La page « Contacts » de la console était donc vide pour tout le
 * monde, alors que la base en compte 1,3 M.
 *
 * Ce n'est pas passé entre les mailles : les deux seuls tests qui touchaient
 * cette route vérifiaient qu'elle répond **200** et qu'elle ne renvoie pas
 * **500** (`NotificationsControllerTest`, `Sprint189NoFiveHundredTest`). Un
 * bouchon vide satisfait les deux à la perfection.
 *
 * 🔴 Un test qui vérifie qu'une route ne PLANTE pas ne vérifie pas qu'elle
 * RÉPOND. Les tests ci-dessous portent tous sur le CONTENU — la seule chose
 * qu'un bouchon ne sait pas imiter.
 */

use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-contacts', 'name' => 'WS', 'settings' => [],
    ]);
    $this->user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'contacts@example.com', 'name' => 'U',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $this->workspace->id,
        'first_login_completed_at' => now(),
    ]);
    $this->actingAs($this->user);
});

function creerContact(string $workspaceId, array $attrs = []): Contact
{
    // Deux contraintes RÉELLES du schéma, que ce helper doit respecter :
    //  1. `normalized_hash` est une colonne GÉNÉRÉE par PostgreSQL — la
    //     renseigner fait échouer l'insertion (« cannot insert a non-DEFAULT
    //     value »). C'est elle qui porte l'unicité anti-doublon.
    //  2. `company_id` est NOT NULL : un contact appartient toujours à une
    //     entreprise (la migration qui la rendrait nullable est reportée —
    //     elle prend un verrou de 2,5 à 5 min sur la table de production).
    if (! array_key_exists('company_id', $attrs)) {
        $attrs['company_id'] = Company::create([
            'workspace_id' => $workspaceId,
            'siren' => (string) random_int(100000000, 999999999),
            'denomination' => 'Société hôte',
            'signals' => [],
            'metadata' => [],
        ])->id;
    }

    return Contact::create(array_merge([
        'workspace_id' => $workspaceId,
        'last_name' => 'Dupont',
        'sources' => [],
        'metadata' => [],
    ], $attrs));
}

test('la liste renvoie les contacts existants — pas un tableau vide', function () {
    creerContact($this->workspace->id, ['first_name' => 'Marie', 'last_name' => 'Durand']);
    creerContact($this->workspace->id, ['first_name' => 'Jean', 'last_name' => 'Martin']);

    $r = $this->getJson('/api/v1/contacts?per_page=10');

    $r->assertOk();
    // ⬇️ C'est ici que vivait le défaut : le bouchon passait le `assertOk()`.
    expect($r->json('meta.total'))->toBe(2);
    expect($r->json('data'))->toHaveCount(2);

    $noms = array_column($r->json('data'), 'last_name');
    sort($noms);
    expect($noms)->toBe(['Durand', 'Martin']);
});

test('chaque ligne porte les champs que l’écran affiche', function () {
    $company = Company::create([
        'workspace_id' => $this->workspace->id, 'siren' => '123456789',
        'denomination' => 'ACME SAS', 'signals' => [], 'metadata' => [],
    ]);
    creerContact($this->workspace->id, [
        'company_id' => $company->id,
        'first_name' => 'Claire', 'last_name' => 'Bernard', 'role' => 'DAF',
        'email' => 'claire@acme.fr', 'email_status' => 'valid', 'email_score' => 87,
        'phone' => '+33600000000', 'linkedin_url' => 'https://linkedin.com/in/cb',
        'discovery_source' => 'waterfall',
    ]);

    $ligne = $this->getJson('/api/v1/contacts')->assertOk()->json('data.0');

    // L'écran lit exactement ces clés : une projection incomplète produirait
    // des colonnes vides sans qu'aucune erreur ne soit levée.
    expect($ligne)->toHaveKeys([
        'id', 'first_name', 'last_name', 'role', 'email', 'email_status',
        'email_score', 'phone', 'linkedin_url', 'discovery_source', 'company_id', 'company',
    ]);
    expect($ligne['company']['denomination'])->toBe('ACME SAS');
    expect($ligne['email_status'])->toBe('valid');
});

test('la projection n’expose PAS les colonnes internes', function () {
    creerContact($this->workspace->id, ['metadata' => ['secret' => 'x']]);

    $ligne = $this->getJson('/api/v1/contacts')->assertOk()->json('data.0');

    // Renvoyer le modèle brut exposerait au fil de l'eau toute colonne ajoutée
    // par une migration future.
    expect($ligne)->not->toHaveKey('normalized_hash')
        ->and($ligne)->not->toHaveKey('metadata')
        ->and($ligne)->not->toHaveKey('workspace_id');
});

test('un contact d’un AUTRE workspace n’apparaît jamais', function () {
    $autre = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-autre-c', 'name' => 'Autre', 'settings' => [],
    ]);
    creerContact($this->workspace->id, ['last_name' => 'AMoi']);
    creerContact($autre->id, ['last_name' => 'PasAMoi']);

    $r = $this->getJson('/api/v1/contacts')->assertOk();

    expect($r->json('meta.total'))->toBe(1);
    expect(array_column($r->json('data'), 'last_name'))->toBe(['AMoi']);
});

test('filtre par statut e-mail', function () {
    creerContact($this->workspace->id, ['last_name' => 'Valide', 'email_status' => 'valid']);
    creerContact($this->workspace->id, ['last_name' => 'Inconnu', 'email_status' => 'unknown']);

    $r = $this->getJson('/api/v1/contacts?filter[email_status]=valid')->assertOk();

    expect($r->json('meta.total'))->toBe(1);
    expect($r->json('data.0.last_name'))->toBe('Valide');
});

test('recherche par nom : préfixe, insensible à la casse, et JAMAIS « contient »', function () {
    creerContact($this->workspace->id, ['last_name' => 'Duponchel']);
    creerContact($this->workspace->id, ['last_name' => 'Martin']);
    // Piège : « Bidupont » CONTIENT « dupont » mais ne COMMENCE pas par lui.
    // Un `%x%` le remonterait — et interdirait tout index sur 1,3 M de lignes.
    creerContact($this->workspace->id, ['last_name' => 'Bidupont']);

    $r = $this->getJson('/api/v1/contacts?filter[last_name]=DUPON')->assertOk();

    expect($r->json('meta.total'))->toBe(1);
    expect($r->json('data.0.last_name'))->toBe('Duponchel');
});

test('la pagination est bornée : per_page=1000 ne ramène pas 1000 lignes', function () {
    foreach (range(1, 5) as $i) {
        creerContact($this->workspace->id, ['last_name' => 'Nom' . $i]);
    }

    $r = $this->getJson('/api/v1/contacts?per_page=1000')->assertOk();

    // Plafond à 100 : sans lui, un paramètre d'URL suffit à demander 1,3 M de
    // lignes en une réponse.
    expect($r->json('meta.per_page'))->toBe(100);
    expect($r->json('meta.total'))->toBe(5);
});
