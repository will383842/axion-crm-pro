<?php

/**
 * L5-back — liste `statut=ouverte`, pastille, mise à l'écart SANS suppression,
 * et aucune PII dans les journaux de la liste.
 */

use App\Http\Controllers\Api\Crm\ATraiterController;
use App\Models\RgpdRequest;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function l5Compte(Workspace $ws, string $role): User
{
    $u = User::create([
        'id' => (string) Str::uuid(), 'email' => $role . '-' . Str::random(6) . '@rgpd.test', 'name' => ucfirst($role),
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $ws->id, 'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($ws->id);
    $u->assignRole($role);

    return $u;
}

function l5Espace(): Workspace
{
    return Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'l5-' . Str::random(6), 'name' => 'L5']);
}

function l5Demande(Workspace $ws, string $statut, string $type = 'erasure', string $email = 'vue@example.invalid', ?string $quand = null): RgpdRequest
{
    return RgpdRequest::create([
        'workspace_id' => $ws->id, 'type' => $type, 'status' => $statut, 'subject_email' => $email,
        'requested_at' => $quand ?? now(), 'metadata' => [],
    ]);
}

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
});

test('statut=ouverte rend pending + processing de l’espace courant seulement, la plus ancienne d’abord', function () {
    $a = l5Espace();
    $b = l5Espace();
    $recente = l5Demande($a, 'pending', quand: now()->subHour());
    $ancienne = l5Demande($a, 'processing', quand: now()->subDays(2));
    l5Demande($a, 'done');
    l5Demande($a, 'rejected');
    l5Demande($b, 'pending', email: 'autre@example.invalid');
    $owner = l5Compte($a, 'owner');

    $ids = collect($this->actingAs($owner)->getJson('/api/v1/rgpd/requests?statut=ouverte')->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->toBe([$ancienne->id, $recente->id]);
});

test('statut=traitee et statut=toutes ; une valeur inconnue → 422 sans écho', function () {
    $a = l5Espace();
    l5Demande($a, 'pending');
    $fait = l5Demande($a, 'done');
    $owner = l5Compte($a, 'owner');

    $traitees = $this->actingAs($owner)->getJson('/api/v1/rgpd/requests?statut=traitee')->assertOk()->json('data');
    $toutes = $this->actingAs($owner)->getJson('/api/v1/rgpd/requests?statut=toutes')->assertOk()->json('data');
    $rep = $this->actingAs($owner)->getJson('/api/v1/rgpd/requests?statut=xyz-secret')->assertStatus(422);

    expect(array_column($traitees, 'id'))->toBe([$fait->id])
        ->and($toutes)->toHaveCount(2)
        ->and($rep->getContent())->not->toContain('xyz-secret');
});

test('le filtre type=erasure est appliqué', function () {
    $a = l5Espace();
    l5Demande($a, 'pending', 'erasure');
    l5Demande($a, 'pending', 'access');
    $owner = l5Compte($a, 'owner');

    $data = $this->actingAs($owner)->getJson('/api/v1/rgpd/requests?statut=ouverte&type=erasure')->assertOk()->json('data');

    expect($data)->toHaveCount(1)->and($data[0]['type'])->toBe('erasure');
});

test('un compte sans droit de voir les coordonnées voit subject_email masqué', function () {
    $a = l5Espace();
    l5Demande($a, 'pending', email: 'pierre.durand@example.invalid');
    $operateur = l5Compte($a, 'operator');

    $json = $this->actingAs($operateur)->getJson('/api/v1/rgpd/requests?statut=ouverte')->assertOk()->getContent();

    expect($json)->not->toContain('pierre.durand@example.invalid')->toContain('p***@example.invalid');
});

test('la pastille demandes_rgpd compte pending + processing et retombe à 0 après le traitement', function () {
    $a = l5Espace();
    l5Demande($a, 'pending');
    l5Demande($a, 'processing');
    $demande = RgpdRequest::where('status', 'pending')->firstOrFail();
    $owner = l5Compte($a, 'owner');
    ATraiterController::oublier($a->id);

    expect($this->actingAs($owner)->getJson('/api/v1/crm/a-traiter')->assertOk()->json('demandes_rgpd'))->toBe(2);

    DB::table('rgpd_requests')->whereIn('status', ['pending', 'processing'])->update(['status' => 'done']);
    ATraiterController::oublier($a->id);
    expect($this->actingAs($owner)->getJson('/api/v1/crm/a-traiter')->assertOk()->json('demandes_rgpd'))->toBe(0);
    expect($demande->id)->toBeInt();
});

test('process par un admin → 403 ; par l’owner → 200 sans suppression ; second appel → 409', function () {
    $a = l5Espace();
    $demande = l5Demande($a, 'pending', email: 'efface@example.invalid');
    DB::table('personnes')->insert([
        'workspace_id' => $a->id, 'person_key' => 'pkx', 'email' => 'efface@example.invalid',
        'email_hash' => hash('sha256', 'efface@example.invalid'), 'premiere_source' => 'site',
        'premiere_source_at' => now(), 'legal_basis' => 'consent',
    ]);
    $avant = DB::table('personnes')->count();
    $admin = l5Compte($a, 'admin');
    $owner = l5Compte($a, 'owner');

    $this->actingAs($admin)->postJson("/api/v1/rgpd/requests/{$demande->id}/process")->assertForbidden();
    $rep = $this->actingAs($owner)->postJson("/api/v1/rgpd/requests/{$demande->id}/process")->assertOk();

    expect($rep->json('supprime'))->toBe(0)
        ->and($rep->json())->toHaveKey('mise_a_l_ecart')
        ->and(DB::table('personnes')->count())->toBe($avant)
        ->and(DB::table('personnes')->where('email', 'efface@example.invalid')->exists())->toBeTrue();

    $this->actingAs($owner)->postJson("/api/v1/rgpd/requests/{$demande->id}/process")->assertStatus(409);
});

test('une panne SQL de la liste ne met PAS le message (donc l’adresse) dans le journal', function () {
    $a = l5Espace();
    $owner = l5Compte($a, 'owner');
    Log::spy();
    // Force l'exception : la colonne triée n'existe pas dans le message d'erreur du pilote, qui cite la requête.
    DB::statement('ALTER TABLE rgpd_requests RENAME COLUMN requested_at TO requested_at_x');

    $this->actingAs($owner)->getJson('/api/v1/rgpd/requests?statut=ouverte&type=erasure')->assertOk();

    Log::shouldHaveReceived('error')->withArgs(function ($message, $contexte = []) {
        return $message === 'rgpd.requests.index failed'
            && ($contexte['exception'] ?? '') !== ''
            && str_starts_with((string) $contexte['exception'], 'Illuminate\\')
            && ! str_contains(json_encode($contexte), 'SQLSTATE');
    })->atLeast()->once();
});
