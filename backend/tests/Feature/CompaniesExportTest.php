<?php

/**
 * L'EXPORT CSV N'AVAIT AUCUN TEST — et il exportait une colonne toujours vide.
 *
 * `$hasSante` était décidé en haut de `export()` (« la table
 * `health_practitioners` existe-t-elle ? ») puis relu tout au fond de deux
 * fermetures imbriquées, sans figurer dans le moindre `use`. La colonne
 * « Spécialité(s) santé » sortait donc VIDE à chaque export, quelle que soit la
 * donnée en base, et PHP lisait une variable indéfinie à chaque ligne.
 *
 * PHPStan le savait : deux entrées du baseline le disaient noir sur blanc
 * (`Undefined variable: $hasSante`). Une erreur mise au baseline reste une
 * erreur — elle est seulement devenue muette. Ces tests remplacent le silence.
 */

use App\Models\Company;
use App\Models\HealthPractitioner;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-export', 'name' => 'WS', 'settings' => [],
    ]);
    $this->user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'export@example.com', 'name' => 'U',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $this->workspace->id,
        'first_login_completed_at' => now(),
    ]);
    $this->actingAs($this->user);
});

/** Le corps d'une réponse streamée ne se lit qu'en la faisant tourner. */
function csvBody(TestResponse $response): string
{
    ob_start();
    $response->baseResponse->sendContent();

    return (string) ob_get_clean();
}

test('la colonne des spécialités santé porte la donnée, pas du vide', function () {
    $company = Company::create([
        'workspace_id' => $this->workspace->id,
        'siren' => '900000001',
        'denomination' => 'CABINET DU CENTRE',
        'signals' => [],
        'metadata' => [],
    ]);

    HealthPractitioner::create([
        'workspace_id' => $this->workspace->id,
        'company_id' => $company->id,
        'nom' => 'Martin',
        'prenom' => 'Claire',
        'specialite' => 'Kinésithérapie',
        'source' => 'test',
    ]);

    $response = $this->get('/api/v1/companies/export');
    $response->assertOk();

    $csv = csvBody($response);

    expect($csv)->toContain('CABINET DU CENTRE');
    // ⬇️ C'est ici que vivait le défaut : la spécialité existait en base et
    // n'atteignait jamais le fichier.
    expect($csv)->toContain('Kinésithérapie');
});

test('deux spécialités sur une même fiche sont jointes, sans doublon', function () {
    $company = Company::create([
        'workspace_id' => $this->workspace->id,
        'siren' => '900000002',
        'denomination' => 'MAISON DE SANTE',
        'signals' => [],
        'metadata' => [],
    ]);

    foreach (['Cardiologie', 'Dermatologie', 'Cardiologie'] as $index => $specialite) {
        HealthPractitioner::create([
            'workspace_id' => $this->workspace->id,
            'company_id' => $company->id,
            'nom' => 'Praticien' . $index,
            'specialite' => $specialite,
            'source' => 'test',
        ]);
    }

    $csv = csvBody($this->get('/api/v1/companies/export')->assertOk());

    expect($csv)->toContain('Cardiologie');
    expect($csv)->toContain('Dermatologie');
    // `unique()` : le même intitulé porté par deux praticiens ne doit pas
    // apparaître deux fois dans la cellule.
    expect(substr_count($csv, 'Cardiologie'))->toBe(1);
});

test('une fiche sans praticien exporte une cellule vide, pas une erreur', function () {
    Company::create([
        'workspace_id' => $this->workspace->id,
        'siren' => '900000003',
        'denomination' => 'ENTREPRISE ORDINAIRE',
        'signals' => [],
        'metadata' => [],
    ]);

    $csv = csvBody($this->get('/api/v1/companies/export')->assertOk());

    expect($csv)->toContain('ENTREPRISE ORDINAIRE');
    expect($csv)->toContain('Spécialité(s) santé');
});

test('l’export reste borné au workspace courant', function () {
    $autre = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-autre', 'name' => 'Autre', 'settings' => [],
    ]);

    Company::create([
        'workspace_id' => $this->workspace->id,
        'siren' => '900000004', 'denomination' => 'A MOI', 'signals' => [], 'metadata' => [],
    ]);
    Company::create([
        'workspace_id' => $autre->id,
        'siren' => '900000005', 'denomination' => 'PAS A MOI', 'signals' => [], 'metadata' => [],
    ]);

    $csv = csvBody($this->get('/api/v1/companies/export')->assertOk());

    expect($csv)->toContain('A MOI');
    expect($csv)->not->toContain('PAS A MOI');
});
