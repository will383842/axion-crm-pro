<?php

/**
 * Lot G2-A — « Mes tâches » côté serveur (cahier G1-G2 § 8, PR #619).
 *
 * Une tâche est une ligne `activities` de nature `task`. Le cloisonnement par
 * espace est rejoué SOUS LE RÔLE APPLICATIF (pgsql_app, RLS) ET SOUS LE
 * PROPRIÉTAIRE (qui ignore la RLS : c'est lui qui prouve le filtre explicite).
 * Données fictives seulement (`@example.test`).
 */

use App\Crm\Taches\MesTaches;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config(['crm.console_v2' => true]);
    $this->seed(PermissionsAndRolesSeeder::class);
    Carbon::setTestNow(CarbonImmutable::parse('2026-12-10 10:00:00', 'UTC'));
    $this->espaceA = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'ws-taches-a', 'name' => 'Espace A', 'settings' => []]);
    $this->espaceB = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'ws-taches-b', 'name' => 'Espace B', 'settings' => []]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function tacheActeur(string $espaceId, string $role): User
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

/** @param array<string,mixed> $plus */
function tacheLigne(string $ws, ?string $user, string $titre, array $plus = []): int
{
    return (int) DB::table('activities')->insertGetId($plus + [
        'workspace_id' => $ws, 'user_id' => $user, 'type' => 'task', 'kind' => 'task',
        'occurred_at' => now(), 'due_at' => now()->addDay(), 'title' => $titre, 'created_at' => now(),
        'payload' => '{}',
    ]);
}

function tacheEntreprise(string $ws, string $siren, string $nom): int
{
    return (int) DB::table('companies')->insertGetId([
        'workspace_id' => $ws, 'siren' => $siren, 'denomination' => $nom,
        'discovery_source' => 'site', 'quality_score' => 0, 'signals' => '{}', 'metadata' => '{}',
        'relation_type' => 'prospect', 'lifecycle_stage' => 'nouveau',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function tacheTitres(array $reponse): array
{
    return collect($reponse['data'])->pluck('titre')->sort()->values()->all();
}

function tacheSous(string $mode, string $ws, Closure $f): mixed
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

test('1. un lecteur : GET 200, mais 403 sur POST, PATCH et PUT, et rien ne bouge', function () {
    $moi = tacheActeur($this->espaceA->id, 'viewer');
    $id = tacheLigne($this->espaceA->id, $moi->id, 'Appeler le client fictif');
    $this->actingAs($moi);

    $this->getJson('/api/v1/crm/taches')->assertOk();
    $this->postJson('/api/v1/crm/taches', ['titre' => 'Nouvelle tâche fictive'])->assertForbidden();
    $this->patchJson("/api/v1/crm/taches/{$id}", ['faite' => true])->assertForbidden();
    $this->putJson('/api/v1/crm/taches/rappel-matin', ['actif' => true])->assertForbidden();

    expect(DB::table('activities')->count())->toBe(1)
        ->and(DB::table('activities')->where('id', $id)->value('done_at'))->toBeNull();
});

test('2. je vois les miennes et celles du système, pas celles d’un collègue ni d’un autre espace (rôle applicatif ET propriétaire)', function (string $mode) {
    $moi = tacheActeur($this->espaceA->id, 'operator');
    $collegue = tacheActeur($this->espaceA->id, 'operator');
    $autre = tacheActeur($this->espaceB->id, 'operator');
    $ficheA = tacheEntreprise($this->espaceA->id, '111111111', 'Société fictive A');
    tacheLigne($this->espaceA->id, $moi->id, 'Ma tâche sans sujet');
    tacheLigne($this->espaceA->id, $moi->id, 'Ma tâche entreprise', ['subject_type' => 'company', 'subject_id' => $ficheA]);
    tacheLigne($this->espaceA->id, $moi->id, 'Ma tâche personne', ['subject_type' => 'personne', 'subject_id' => 999999]);
    tacheLigne($this->espaceA->id, null, 'Tâche du système');
    tacheLigne($this->espaceA->id, $collegue->id, 'Tâche du collègue');
    tacheLigne($this->espaceB->id, $autre->id, 'Tâche autre espace');
    tacheLigne($this->espaceB->id, null, 'Tâche système autre espace');

    $liste = tacheSous($mode, $this->espaceA->id, fn () => MesTaches::liste($this->espaceA->id, $moi->id, CarbonImmutable::now('UTC')));

    expect(collect($liste)->pluck('titre')->sort()->values()->all())
        ->toBe(['Ma tâche entreprise', 'Ma tâche personne', 'Ma tâche sans sujet', 'Tâche du système'], $mode);
    $parTitre = collect($liste)->keyBy('titre');
    expect($parTitre['Ma tâche entreprise']['contexte'])->toBe('Société fictive A', $mode)
        ->and($parTitre['Ma tâche sans sujet']['contexte'])->toBe('—', $mode);
})->with(['app', 'proprio']);

test('2 bis. même cloisonnement par HTTP, et le contexte ne joint jamais l’entreprise d’un autre espace', function () {
    $moi = tacheActeur($this->espaceA->id, 'operator');
    $etrangere = tacheEntreprise($this->espaceB->id, '222222222', 'Société fictive B');
    tacheLigne($this->espaceA->id, $moi->id, 'Pointe vers B', ['subject_type' => 'company', 'subject_id' => $etrangere]);
    tacheLigne($this->espaceB->id, null, 'Tâche autre espace');
    $this->actingAs($moi);

    $r = $this->getJson('/api/v1/crm/taches')->assertOk();

    expect(tacheTitres($r->json()))->toBe(['Pointe vers B'])
        ->and($r->json('data.0.contexte'))->toBe('—')
        ->and($r->json('rappel_matin'))->toBeFalse()
        ->and($r->json('aujourdhui'))->toBe('2026-12-10');
});

test('3. faite il y a 8 jours : absente ; il y a 2 jours : présente avec faite=true', function () {
    $moi = tacheActeur($this->espaceA->id, 'operator');
    tacheLigne($this->espaceA->id, $moi->id, 'Vieille', ['done_at' => now()->subDays(8)]);
    tacheLigne($this->espaceA->id, $moi->id, 'Récente', ['done_at' => now()->subDays(2)]);
    $this->actingAs($moi);

    $r = $this->getJson('/api/v1/crm/taches')->assertOk();

    expect(tacheTitres($r->json()))->toBe(['Récente'])
        ->and($r->json('data.0.faite'))->toBeTrue()
        ->and($r->json('data.0.faite_le'))->not->toBeNull();
});

test('4. PATCH sur la tâche d’un collègue, d’un autre espace ou sur un appel : 404, rien ne change', function () {
    $moi = tacheActeur($this->espaceA->id, 'operator');
    $collegue = tacheActeur($this->espaceA->id, 'operator');
    $autre = tacheActeur($this->espaceB->id, 'operator');
    $ids = [
        tacheLigne($this->espaceA->id, $collegue->id, 'Collègue'),
        tacheLigne($this->espaceB->id, $autre->id, 'Autre espace'),
        tacheLigne($this->espaceB->id, null, 'Système autre espace'),
        tacheLigne($this->espaceA->id, $moi->id, 'Un appel', ['kind' => 'call', 'type' => 'call']),
    ];
    $this->actingAs($moi);

    foreach ($ids as $id) {
        $this->patchJson("/api/v1/crm/taches/{$id}", ['faite' => true])->assertNotFound();
    }

    expect(DB::table('activities')->whereIn('id', $ids)->whereNotNull('done_at')->count())->toBe(0);
});

test('4 bis. PATCH exige faite booléen', function () {
    $moi = tacheActeur($this->espaceA->id, 'operator');
    $id = tacheLigne($this->espaceA->id, $moi->id, 'Valide');
    $this->actingAs($moi);

    $this->patchJson("/api/v1/crm/taches/{$id}", [])->assertUnprocessable();
    $this->patchJson("/api/v1/crm/taches/{$id}", ['faite' => 'peut-être'])->assertUnprocessable();
});

test('5. faite=true puis faite=false : rouvre, la ligne existe toujours ; la tâche du système se clôt aussi', function () {
    $moi = tacheActeur($this->espaceA->id, 'operator');
    $id = tacheLigne($this->espaceA->id, $moi->id, 'À cycler');
    $systeme = tacheLigne($this->espaceA->id, null, 'Système');
    $this->actingAs($moi);

    $this->patchJson("/api/v1/crm/taches/{$id}", ['faite' => true])->assertOk()->assertJsonPath('faite', true);
    expect(DB::table('activities')->where('id', $id)->value('done_at'))->not->toBeNull();

    $this->patchJson("/api/v1/crm/taches/{$id}", ['faite' => false])->assertOk()->assertJsonPath('faite', false);
    expect(DB::table('activities')->where('id', $id)->value('done_at'))->toBeNull();

    $this->patchJson("/api/v1/crm/taches/{$systeme}", ['faite' => true])->assertOk();
    expect(DB::table('activities')->count())->toBe(2)
        ->and(DB::table('activities')->where('id', $systeme)->value('done_at'))->not->toBeNull();
});

test('6. double POST dans la même minute : une seule ligne', function () {
    $moi = tacheActeur($this->espaceA->id, 'operator');
    $this->actingAs($moi);
    $corps = ['titre' => 'Rappeler la société fictive', 'contexte' => 'Contexte court', 'echeance' => '2026-12-12', 'heure' => '09:30'];

    $premiere = $this->postJson('/api/v1/crm/taches', $corps)->assertCreated();
    $seconde = $this->postJson('/api/v1/crm/taches', $corps)->assertOk();

    expect(DB::table('activities')->where('kind', 'task')->count())->toBe(1)
        ->and($seconde->json('id'))->toBe($premiere->json('id'))
        ->and($premiere->json('echeance'))->toBe('2026-12-12')
        ->and($premiere->json('heure'))->toBe('09:30')
        ->and($premiere->json('contexte'))->toBe('Contexte court')
        ->and($premiere->json('faite'))->toBeFalse();
    $ligne = DB::table('activities')->first();
    expect($ligne->user_id)->toBe($moi->id)->and($ligne->subject_type)->toBeNull();
    // 09:30 à Paris en décembre = 08:30 UTC.
    expect(CarbonImmutable::parse($ligne->due_at)->utc()->format('Y-m-d H:i'))->toBe('2026-12-12 08:30');
});

test('6 bis. POST valide titre, heure et date', function () {
    $this->actingAs(tacheActeur($this->espaceA->id, 'operator'));

    $this->postJson('/api/v1/crm/taches', ['titre' => 'ab'])->assertUnprocessable();
    $this->postJson('/api/v1/crm/taches', ['titre' => 'Titre valable', 'heure' => '25:99'])->assertUnprocessable();
    $this->postJson('/api/v1/crm/taches', ['titre' => 'Titre valable', 'echeance' => 'pas une date'])->assertUnprocessable();
    $this->postJson('/api/v1/crm/taches', ['titre' => 'Titre valable', 'contexte' => str_repeat('x', 201)])->assertUnprocessable();

    expect(DB::table('activities')->count())->toBe(0);
});

test('7. horloge à 23 h 30 UTC : echeance et aujourdhui suivent la date de Paris (lendemain)', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-12-10 23:30:00', 'UTC'));
    $moi = tacheActeur($this->espaceA->id, 'operator');
    tacheLigne($this->espaceA->id, $moi->id, 'Sans échéance', ['due_at' => null]);
    tacheLigne($this->espaceA->id, $moi->id, 'Échéance minuit Paris', ['due_at' => '2026-12-10 23:30:00+00', 'payload' => json_encode(['avec_heure' => true])]);
    $this->actingAs($moi);

    $r = $this->getJson('/api/v1/crm/taches')->assertOk();
    $parTitre = collect($r->json('data'))->keyBy('titre');

    expect($r->json('aujourdhui'))->toBe('2026-12-11')
        ->and($parTitre['Sans échéance']['echeance'])->toBe('2026-12-11')
        ->and($parTitre['Sans échéance']['heure'])->toBeNull()
        ->and($parTitre['Échéance minuit Paris']['echeance'])->toBe('2026-12-11')
        ->and($parTitre['Échéance minuit Paris']['heure'])->toBe('00:30');
});

test('8. PUT rappel-matin : 409, aucune écriture, aucun courriel', function () {
    Mail::fake();
    $moi = tacheActeur($this->espaceA->id, 'operator');
    $this->actingAs($moi);
    $avant = [DB::table('activities')->count(), DB::table('users')->where('id', $moi->id)->value('updated_at')];

    $this->putJson('/api/v1/crm/taches/rappel-matin', ['actif' => true])
        ->assertStatus(409)
        ->assertJson(['error' => 'rappel_indisponible', 'actif' => false]);

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    expect([DB::table('activities')->count(), DB::table('users')->where('id', $moi->id)->value('updated_at')])->toBe($avant);
});

test('9. les tâches des fiches (entreprise, personne) apparaissent ici et se terminent ici comme sur la fiche', function () {
    $moi = tacheActeur($this->espaceA->id, 'operator');
    $ficheId = tacheEntreprise($this->espaceA->id, '333333333', 'Société fictive C');
    $this->actingAs($moi);

    $this->postJson("/api/v1/companies/{$ficheId}/taches", ['title' => 'Depuis la fiche entreprise'])->assertCreated();
    $liste = $this->getJson('/api/v1/crm/taches')->assertOk();
    expect(tacheTitres($liste->json()))->toBe(['Depuis la fiche entreprise'])
        ->and($liste->json('data.0.contexte'))->toBe('Société fictive C');

    $id = $liste->json('data.0.id');
    $this->patchJson("/api/v1/crm/taches/{$id}", ['faite' => true])->assertOk();

    $this->getJson("/api/v1/companies/{$ficheId}/taches")->assertOk()->assertJsonPath('data.0.done', true);
});

test('Mail::fake : créer et clore une tâche n’envoie rien', function () {
    Mail::fake();
    $this->actingAs(tacheActeur($this->espaceA->id, 'admin'));
    $id = $this->postJson('/api/v1/crm/taches', ['titre' => 'Tâche sans envoi'])->json('id');
    $this->patchJson("/api/v1/crm/taches/{$id}", ['faite' => true])->assertOk();

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});
