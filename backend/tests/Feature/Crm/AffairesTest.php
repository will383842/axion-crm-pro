<?php

/**
 * Lot G2-D — Affaires côté serveur (cahier G1-G2 § 11, PR #619).
 *
 * Le cloisonnement par espace est rejoué SOUS LE RÔLE APPLICATIF (pgsql_app, RLS) ET SOUS LE
 * PROPRIÉTAIRE (qui ignore la RLS : c'est lui qui prouve le filtre explicite).
 * Données fictives seulement (`@example.test`).
 */

use App\Crm\Affaires\Affaires;
use App\Crm\Doublons\FusionFiches;
use App\Crm\Doublons\Rapprochement;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config(['crm.console_v2' => true]);
    $this->seed(PermissionsAndRolesSeeder::class);
    Carbon::setTestNow(CarbonImmutable::parse('2026-12-10 10:00:00', 'UTC'));
    $this->espaceA = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'ws-aff-a', 'name' => 'Espace A', 'settings' => []]);
    $this->espaceB = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'ws-aff-b', 'name' => 'Espace B', 'settings' => []]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function affActeur(string $espaceId, string $role): User
{
    $u = User::create([
        'id' => (string) Str::uuid(),
        'email' => Str::uuid() . '@example.test',
        'name' => 'Personne factice',
        'password_hash' => Hash::make('MotDePasseDeTest12345!'),
        'current_workspace_id' => $espaceId,
        'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($espaceId);
    $u->assignRole($role);
    DB::table('user_workspaces')->insertOrIgnore([
        'user_id' => $u->id, 'workspace_id' => $espaceId, 'role_slug' => $role,
        'invited_at' => now(), 'joined_at' => now(),
    ]);

    return $u;
}

function affEntreprise(string $ws, string $siren, string $nom, bool $corbeille = false): int
{
    return (int) DB::table('companies')->insertGetId([
        'workspace_id' => $ws, 'siren' => $siren, 'denomination' => $nom,
        'discovery_source' => 'site', 'quality_score' => 0, 'signals' => '{}', 'metadata' => '{}',
        'relation_type' => 'prospect', 'lifecycle_stage' => 'nouveau',
        'deleted_at' => $corbeille ? now() : null,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** @param array<string,mixed> $plus */
function affAffaire(string $ws, int $entreprise, array $plus = []): int
{
    return (int) DB::table('deals')->insertGetId($plus + ['workspace_id' => $ws, 'company_id' => $entreprise]);
}

function affSous(string $mode, string $ws, Closure $f): mixed
{
    if ($mode === 'proprio') {
        return $f();
    }
    WorkspaceContext::set($ws);
    DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    try {
        return $f();
    } finally {
        DB::statement('RESET ROLE');
    }
}

test('1. un lecteur : GET 200, mais 403 sur POST et PATCH, rien ne bouge ; un opérateur : 201 et 200', function () {
    $lecteur = affActeur($this->espaceA->id, 'viewer');
    $operateur = affActeur($this->espaceA->id, 'operator');
    $e = affEntreprise($this->espaceA->id, '111111111', 'Société fictive A');
    $id = affAffaire($this->espaceA->id, $e);

    $this->actingAs($lecteur);
    $this->getJson('/api/v1/crm/affaires')->assertOk();
    $this->postJson('/api/v1/crm/affaires', ['entreprise_id' => $e])->assertForbidden();
    $this->patchJson("/api/v1/crm/affaires/{$id}", ['etape' => 'contacte'])->assertForbidden();
    expect(DB::table('deals')->count())->toBe(1)
        ->and(DB::table('deals')->where('id', $id)->value('etape'))->toBe('a_contacter')
        ->and(DB::table('activities')->count())->toBe(0);

    $this->actingAs($operateur);
    $this->postJson('/api/v1/crm/affaires', ['entreprise_id' => $e, 'titre' => 'Devis fictif', 'montant' => 1200, 'chance' => 40, 'signature_prevue' => '2026-12-31'])
        ->assertCreated()->assertJsonPath('etape', 'a_contacter')->assertJsonPath('montant', 1200);
    $this->patchJson("/api/v1/crm/affaires/{$id}", ['etape' => 'contacte'])->assertOk()->assertJsonPath('etape', 'contacte');
    expect(DB::table('deals')->count())->toBe(2)
        ->and(DB::table('deals')->where('company_id', $e)->where('titre', 'Devis fictif')->value('status'))->toBe('open');
});

test('2. affaire d’un autre espace : absente de la liste, PATCH 404, intacte (rôle applicatif ET propriétaire)', function (string $mode) {
    $moi = affActeur($this->espaceA->id, 'operator');
    $eA = affEntreprise($this->espaceA->id, '111111111', 'Société fictive A');
    $eB = affEntreprise($this->espaceB->id, '222222222', 'Société fictive B');
    $idA = affAffaire($this->espaceA->id, $eA, ['value_eur' => 500, 'probability' => 20]);
    $idB = affAffaire($this->espaceB->id, $eB);

    $liste = affSous($mode, $this->espaceA->id, fn () => Affaires::liste($this->espaceA->id));
    expect(collect($liste)->pluck('id')->all())->toBe([(string) $idA], $mode);

    $this->actingAs($moi);
    $this->patchJson("/api/v1/crm/affaires/{$idB}", ['etape' => 'gagne'])->assertNotFound();
    $this->postJson('/api/v1/crm/affaires', ['entreprise_id' => $eB])->assertNotFound();
    expect(DB::table('deals')->where('id', $idB)->value('etape'))->toBe('a_contacter')
        ->and(DB::table('deals')->where('id', $idB)->value('status'))->toBe('open')
        ->and(DB::table('deals')->count())->toBe(2)
        ->and(DB::table('activities')->count())->toBe(0);
})->with(['app', 'proprio']);

test('3. perdu sans raison : 422 ; avec raison : lost ; gagne : won et une activité stage_changed ; raison interdite hors perdu', function () {
    $moi = affActeur($this->espaceA->id, 'operator');
    $e = affEntreprise($this->espaceA->id, '111111111', 'Société fictive A');
    $id = affAffaire($this->espaceA->id, $e);
    $this->actingAs($moi);

    $this->patchJson("/api/v1/crm/affaires/{$id}", ['etape' => 'perdu'])->assertUnprocessable();
    $this->patchJson("/api/v1/crm/affaires/{$id}", ['etape' => 'contacte', 'raison_perte' => 'Pas de budget'])->assertUnprocessable();
    $this->patchJson("/api/v1/crm/affaires/{$id}", ['etape' => 'inconnue'])->assertUnprocessable();
    expect(DB::table('deals')->where('id', $id)->value('etape'))->toBe('a_contacter')
        ->and(DB::table('activities')->count())->toBe(0);

    $this->patchJson("/api/v1/crm/affaires/{$id}", ['etape' => 'gagne'])->assertOk()->assertJsonPath('etape', 'gagne');
    $deal = DB::table('deals')->where('id', $id)->first();
    $act = DB::table('activities')->where('kind', 'stage_changed')->get();
    expect($deal->status)->toBe('won')
        ->and($act)->toHaveCount(1)
        ->and($act[0]->subject_type)->toBe('deal')
        ->and((int) $act[0]->subject_id)->toBe($id)
        ->and((int) $act[0]->deal_id)->toBe($id)
        ->and(json_decode($act[0]->payload, true))->toMatchArray(['de' => 'a_contacter', 'vers' => 'gagne']);

    $this->patchJson("/api/v1/crm/affaires/{$id}", ['etape' => 'perdu', 'raison_perte' => 'Pas de budget'])->assertOk();
    $deal = DB::table('deals')->where('id', $id)->first();
    expect($deal->status)->toBe('lost')->and($deal->raison_perte)->toBe('Pas de budget');

    $this->patchJson("/api/v1/crm/affaires/{$id}", ['etape' => 'devis'])->assertOk();
    $deal = DB::table('deals')->where('id', $id)->first();
    expect($deal->status)->toBe('open')->and($deal->raison_perte)->toBeNull()
        ->and(DB::table('deal_history')->count())->toBe(0);
});

test('4. entreprise en corbeille : affaire absente de la liste ; POST sur elle : 404', function () {
    $moi = affActeur($this->espaceA->id, 'operator');
    $vivante = affEntreprise($this->espaceA->id, '111111111', 'Société vivante');
    $retiree = affEntreprise($this->espaceA->id, '333333333', 'Société retirée', true);
    $idVivante = affAffaire($this->espaceA->id, $vivante);
    affAffaire($this->espaceA->id, $retiree);
    $this->actingAs($moi);

    $r = $this->getJson('/api/v1/crm/affaires')->assertOk();
    expect(collect($r->json('data'))->pluck('id')->all())->toBe([(string) $idVivante])
        ->and($r->json('aujourdhui'))->toBe('2026-12-10')
        ->and($r->json('data.0.entreprise'))->toBe('Société vivante');
    $this->postJson('/api/v1/crm/affaires', ['entreprise_id' => $retiree])->assertNotFound();
    expect(DB::table('deals')->count())->toBe(2);
});

test('4 bis. POST : validations et projection (signature à Paris)', function () {
    $moi = affActeur($this->espaceA->id, 'operator');
    $e = affEntreprise($this->espaceA->id, '111111111', 'Société fictive A');
    $this->actingAs($moi);

    $this->postJson('/api/v1/crm/affaires', [])->assertUnprocessable();
    $this->postJson('/api/v1/crm/affaires', ['entreprise_id' => $e, 'montant' => -1])->assertUnprocessable();
    $this->postJson('/api/v1/crm/affaires', ['entreprise_id' => $e, 'chance' => 101])->assertUnprocessable();
    $this->postJson('/api/v1/crm/affaires', ['entreprise_id' => $e, 'signature_prevue' => 'demain'])->assertUnprocessable();
    expect(DB::table('deals')->count())->toBe(0);

    $this->postJson('/api/v1/crm/affaires', ['entreprise_id' => $e, 'signature_prevue' => '2026-12-31', 'chance' => 0])->assertCreated();
    $liste = $this->getJson('/api/v1/crm/affaires')->json('data');
    expect($liste[0]['signature_prevue'])->toBe('2026-12-31')->and($liste[0]['chance'])->toBe(0)->and($liste[0]['entreprise_id'])->toBe($e);
});

test('5. fusion de fiches : l’affaire suit l’entreprise conservée', function () {
    $garde = affEntreprise($this->espaceA->id, '111111111', 'Société gardée');
    $absorbee = affEntreprise($this->espaceA->id, '444444444', 'Société absorbée');
    $id = affAffaire($this->espaceA->id, $absorbee);

    $fusion = WorkspaceContext::run($this->espaceA->id, fn () => app(FusionFiches::class)
        ->fusionner($this->espaceA->id, $garde, $absorbee, Rapprochement::NOM_CP_SITE, FusionFiches::MODE_MANUEL, null, null, 'test'));

    expect($fusion)->toBeGreaterThan(0)
        ->and((int) DB::table('deals')->where('id', $id)->value('company_id'))->toBe($garde)
        ->and(collect(Affaires::liste($this->espaceA->id))->pluck('entreprise_id')->all())->toBe([$garde]);
});

test('6. aucune suppression : ni ->delete( ni DELETE FROM dans le code des affaires, et aucune route DELETE', function () {
    foreach (['app/Crm/Affaires/Affaires.php', 'app/Http/Controllers/Api/Crm/AffairesController.php'] as $f) {
        $src = (string) file_get_contents(base_path($f));
        expect($src)->not->toContain('->delete(')->not->toContain('DELETE FROM')->not->toMatch('/\bDELETE\b\s+FROM/i');
    }
    $routes = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'crm/affaires'));
    expect($routes)->not->toBeEmpty();
    foreach ($routes as $r) {
        expect($r->methods())->not->toContain('DELETE');
    }
});
