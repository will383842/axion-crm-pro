<?php

use App\Models\User;
use App\Models\Workspace;
use App\Support\DelaiRequeteSql;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route as RoutageFacade;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * LOT 13 — choisir l'entreprise d'un rattachement en la CHERCHANT
 * (`GET /crm/entreprises/choix`), au lieu de taper son identifiant interne.
 *
 * Ce que l'opérateur tape : un nom (accents et articles indifférents), un nom
 * suivi de la ville ou du code postal, un SIREN, un SIRET. Ce qu'on garde :
 * l'univers de l'opérateur, et l'absence de balayage (mot trop court, joker).
 *
 * Adresses et noms fictifs (dépôt PUBLIC).
 */
beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    config(['crm.console_v2' => true, 'crm.ingest.business_workspace' => 'axion-ia']);

    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'axion-ia',
        'name' => 'Axion-IA',
        'settings' => [],
    ]);

    $user = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'lot13.choix@example.invalid',
        'name' => 'Opérateur lot 13',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $this->workspace->id,
        'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($this->workspace->id);
    $user->assignRole('admin');
    DB::table('user_workspaces')->insertOrIgnore([
        'user_id' => $user->id,
        'workspace_id' => $this->workspace->id,
        'role_slug' => 'owner',
        'invited_at' => now(),
        'joined_at' => now(),
    ]);
    $this->actingAs($user);
});

/** @param  array<string, mixed>  $champs */
function lot13Entreprise(string $workspaceId, string $siren, string $denomination, array $champs = []): int
{
    return (int) DB::table('companies')->insertGetId(array_merge([
        'workspace_id' => $workspaceId,
        'siren' => $siren,
        'denomination' => $denomination,
        'discovery_source' => 'site',
        'quality_score' => 0,
        'signals' => '{}',
        'metadata' => '{}',
        'relation_type' => 'prospect',
        'lifecycle_stage' => 'nouveau',
        'legal_basis' => 'legitimate_interest_b2b',
        'field_origins' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ], $champs));
}

/** @return list<int> */
function lot13Ids(TestResponse $reponse): array
{
    /** @var list<array{id: int}> $data */
    $data = $reponse->json('data');

    return array_map(static fn (array $l): int => (int) $l['id'], $data);
}

test('par le nom : accents, casse et articles indifférents, et la ligne porte de quoi choisir', function () {
    $id = lot13Entreprise($this->workspace->id, '900000001', 'La Boulangerie Crème Brûlée', [
        'postcode' => '69003', 'city_name' => 'Lyon', 'siret' => '90000000100012',
    ]);
    lot13Entreprise($this->workspace->id, '900000002', 'Garage du Centre');

    $reponse = $this->getJson('/api/v1/crm/entreprises/choix?q=' . urlencode('boulangerie creme'))->assertOk();

    expect(lot13Ids($reponse))->toBe([$id]);
    $reponse->assertJsonPath('data.0.denomination', 'La Boulangerie Crème Brûlée')
        ->assertJsonPath('data.0.siren', '900000001')
        ->assertJsonPath('data.0.siret', '90000000100012')
        ->assertJsonPath('data.0.code_postal', '69003')
        ->assertJsonPath('data.0.ville', 'Lyon')
        ->assertJsonPath('indice', null);

    // TÉMOIN : la même recherche, tapée avec accents et majuscules.
    expect(lot13Ids($this->getJson('/api/v1/crm/entreprises/choix?q=' . urlencode('CRÈME'))->assertOk()))->toBe([$id]);
});

test('le nom suivi de la ville, ou du code postal, départage deux homonymes', function () {
    $lyon = lot13Entreprise($this->workspace->id, '900000011', 'Martin Conseil', ['postcode' => '69003', 'city_name' => 'Lyon']);
    $paris = lot13Entreprise($this->workspace->id, '900000012', 'Martin Conseil', ['postcode' => '75008', 'city' => 'Paris']);

    // TÉMOIN : le nom seul rend les deux.
    expect(lot13Ids($this->getJson('/api/v1/crm/entreprises/choix?q=martin')->assertOk()))
        ->toEqualCanonicalizing([$lyon, $paris]);

    expect(lot13Ids($this->getJson('/api/v1/crm/entreprises/choix?q=' . urlencode('martin lyon'))->assertOk()))->toBe([$lyon]);
    expect(lot13Ids($this->getJson('/api/v1/crm/entreprises/choix?q=' . urlencode('martin paris'))->assertOk()))->toBe([$paris]);
    expect(lot13Ids($this->getJson('/api/v1/crm/entreprises/choix?q=' . urlencode('martin 75008'))->assertOk()))->toBe([$paris]);
    expect(lot13Ids($this->getJson('/api/v1/crm/entreprises/choix?q=martin&code_postal=69003')->assertOk()))->toBe([$lyon]);
});

test('par le SIREN ou le SIRET, espaces tolérés ; un numéro incomplet ne cherche rien', function () {
    $id = lot13Entreprise($this->workspace->id, '552100554', 'Entreprise Numérotée');
    lot13Entreprise($this->workspace->id, '552100555', 'Voisine de numéro');

    expect(lot13Ids($this->getJson('/api/v1/crm/entreprises/choix?q=' . urlencode('552 100 554'))->assertOk()))->toBe([$id]);
    // Les 9 premiers chiffres d'un SIRET sont le SIREN.
    expect(lot13Ids($this->getJson('/api/v1/crm/entreprises/choix?q=55210055400017')->assertOk()))->toBe([$id]);

    // Pas de recherche partielle sur un numéro : aucun index ne la servirait.
    $this->getJson('/api/v1/crm/entreprises/choix?q=5521005')->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('indice', 'numero_incomplet');
});

test('cloisonnement : une entreprise d un autre univers n est jamais proposée', function () {
    $autre = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'autre-univers',
        'name' => 'Autre',
        'settings' => [],
    ]);
    lot13Entreprise($autre->id, '900000021', 'Zéphyr Étrangère');
    lot13Entreprise($autre->id, '900000022', 'Autre nom');
    $chezMoi = lot13Entreprise($this->workspace->id, '900000023', 'Zéphyr Maison');

    expect(lot13Ids($this->getJson('/api/v1/crm/entreprises/choix?q=zephyr')->assertOk()))->toBe([$chezMoi]);
    $this->getJson('/api/v1/crm/entreprises/choix?q=900000022')->assertOk()->assertJsonCount(0, 'data');
});

test('pas de balayage : mot trop court, article seul ou joker ne cherchent rien', function () {
    lot13Entreprise($this->workspace->id, '900000031', 'Alpha');
    lot13Entreprise($this->workspace->id, '900000032', 'Bêta');

    foreach (['', 'b', 'al', '%', '%%%', '__'] as $saisie) {
        $this->getJson('/api/v1/crm/entreprises/choix?q=' . urlencode($saisie))->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('indice', 'trop_court');
    }

    // Que des mots vides : aucune requête, et l'écran dira « ajoutez un mot du nom ».
    foreach (['la', 'le de', 'les', 'SARL', 'sas les', 'Société'] as $saisie) {
        $this->getJson('/api/v1/crm/entreprises/choix?q=' . urlencode($saisie))->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('indice', 'mots_vides');
    }

    // TÉMOIN : trois lettres suffisent.
    $this->getJson('/api/v1/crm/entreprises/choix?q=alp')->assertOk()->assertJsonCount(1, 'data');
});

test('MOTS VIDES : « les jardins du lac » et « SARL Martin » trouvent la fiche (relecture A09 de la #287)', function () {
    // `normalize_name` stocke « Les Jardins du Lac » en `jardins lac` : le mot
    // « les », normalisé seul, restait `les` et l'affinage l'exigeait dans le
    // nom — la fiche exacte était introuvable.
    $jardins = lot13Entreprise($this->workspace->id, '900000051', 'Les Jardins du Lac', ['postcode' => '74000', 'city_name' => 'Annecy']);
    $martin = lot13Entreprise($this->workspace->id, '900000052', 'SARL Martin');
    lot13Entreprise($this->workspace->id, '900000053', 'SARL Durand');

    expect(lot13Ids($this->getJson('/api/v1/crm/entreprises/choix?q=' . urlencode('les jardins du lac'))->assertOk()))
        ->toBe([$jardins]);
    expect(lot13Ids($this->getJson('/api/v1/crm/entreprises/choix?q=' . urlencode('Les Jardins'))->assertOk()))
        ->toBe([$jardins]);
    expect(lot13Ids($this->getJson('/api/v1/crm/entreprises/choix?q=' . urlencode('SARL Martin'))->assertOk()))
        ->toBe([$martin]);
    // « d'annecy » : l'apostrophe sépare, « d » est un mot vide, « annecy » la ville.
    expect(lot13Ids($this->getJson('/api/v1/crm/entreprises/choix?q=' . urlencode("les jardins d'annecy"))->assertOk()))
        ->toBe([$jardins]);

    // Les SUGGESTIONS de la file d'arbitrage passent le nom reçu tel quel,
    // avec son code postal : un nom qui commence par « Les » doit sortir.
    expect(lot13Ids($this->getJson('/api/v1/crm/entreprises/choix?q=' . urlencode('Les Jardins du Lac') . '&code_postal=74000')->assertOk()))
        ->toBe([$jardins]);
});

test('saisie mal formée : 422 propre, jamais 500', function () {
    $this->getJson('/api/v1/crm/entreprises/choix?q[]=x')->assertStatus(422);
    $this->getJson('/api/v1/crm/entreprises/choix?q=' . str_repeat('a', 201))->assertStatus(422);
    $this->getJson('/api/v1/crm/entreprises/choix?q=martin&code_postal[]=1')->assertStatus(422);
    $this->getJson('/api/v1/crm/entreprises/choix?q=martin&code_postal=' . str_repeat('1', 11))->assertStatus(422);

    // TÉMOIN : une saisie normale passe.
    $this->getJson('/api/v1/crm/entreprises/choix?q=martin&code_postal=69003')->assertOk();
});

test('limitation de débit : au-delà de 60 recherches par minute, 429', function () {
    for ($i = 0; $i < 60; $i++) {
        $this->getJson('/api/v1/crm/entreprises/choix?q=a')->assertOk();
    }

    $this->getJson('/api/v1/crm/entreprises/choix?q=a')->assertStatus(429);
});

test('le délai de 8 s est RÉELLEMENT en vigueur pendant la recherche, pas seulement déclaré', function () {
    lot13Entreprise($this->workspace->id, '900000061', 'Délai Mesuré');

    // On lit le délai sur la connexion AU MOMENT où la requête des fiches
    // s'exécute : un ordre de middlewares inversé (le filet global de 15 s
    // posé après) ferait lire 15000 ici.
    $mesures = [];
    $enLecture = false;
    DB::listen(function ($requete) use (&$mesures, &$enLecture): void {
        if ($enLecture || ! str_contains($requete->sql, 'from "companies"')) {
            return;
        }
        $enLecture = true;
        $mesures[] = DelaiRequeteSql::courantMs();
        $enLecture = false;
    });

    $this->getJson('/api/v1/crm/entreprises/choix?q=' . urlencode('delai mesure'))->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/crm/entreprises/choix?q=552100554')->assertOk();

    expect($mesures)->toHaveCount(2);
    expect($mesures)->each->toBe(8000);
});

test('une fiche supprimée n est pas proposée', function () {
    lot13Entreprise($this->workspace->id, '900000041', 'Fantôme Disparu', ['deleted_at' => now()]);

    $this->getJson('/api/v1/crm/entreprises/choix?q=fantome')->assertOk()->assertJsonCount(0, 'data');
});

test('la route porte un délai court : une recherche trop large est annulée, pas laissée courir', function () {
    $route = collect(RoutageFacade::getRoutes()->getRoutes())
        ->first(fn ($r) => $r->uri() === 'api/v1/crm/entreprises/choix');

    expect($route)->not->toBeNull();
    expect($route->gatherMiddleware())->toContain('delai-sql:8');
});
