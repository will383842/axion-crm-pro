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
use Illuminate\Support\Facades\Cache;
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
    // `viewer` : rgpd.view mais pas contacts.view_pii (lecture seule).
    $operateur = l5Compte($a, 'viewer');

    $json = $this->actingAs($operateur)->getJson('/api/v1/rgpd/requests?statut=ouverte')->assertOk()->getContent();

    expect($json)->not->toContain('pierre.durand@example.invalid')->toContain('p***@example.invalid');
});

test('la pastille demandes_rgpd compte pending + processing et retombe à 0 après le traitement', function () {
    $a = l5Espace();
    l5Demande($a, 'pending');
    l5Demande($a, 'processing');
    $demande = RgpdRequest::where('status', 'pending')->firstOrFail();
    $owner = l5Compte($a, 'owner');
    config(['crm.console_v2' => true]);
    Cache::flush();
    ATraiterController::oublier($a->id);

    expect($this->actingAs($owner)->getJson('/api/v1/crm/a-traiter/compteurs')->assertOk()->json('demandes_rgpd'))->toBe(2);

    DB::table('rgpd_requests')->whereIn('status', ['pending', 'processing'])->update(['status' => 'done']);
    Cache::flush();
    ATraiterController::oublier($a->id);
    expect($this->actingAs($owner)->getJson('/api/v1/crm/a-traiter/compteurs')->assertOk()->json('demandes_rgpd'))->toBe(0);
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

test('une panne de la liste ne met PAS le message (donc l’adresse) dans le journal', function () {
    $a = l5Espace();
    l5Demande($a, 'pending', email: 'pierre.durand@example.invalid');
    $owner = l5Compte($a, 'owner');
    // Une exception dont le MESSAGE contient l'adresse, comme un message SQL qui cite la requête.
    RgpdRequest::retrieved(function (): void {
        throw new RuntimeException('SQLSTATE[XX000] ... pierre.durand@example.invalid');
    });
    Log::spy();

    $this->actingAs($owner)->getJson('/api/v1/rgpd/requests?statut=ouverte')->assertOk();

    Log::shouldHaveReceived('error')->withArgs(function ($message, $contexte = []) {
        return $message === 'rgpd.requests.index failed'
            && ($contexte['exception'] ?? '') === RuntimeException::class
            && ! str_contains(json_encode($contexte), 'pierre.durand');
    })->atLeast()->once();
});

test('B2 : la réponse de process rapporte la VRAIE mise à l’écart (personnes = 1)', function () {
    $a = l5Espace();
    $demande = l5Demande($a, 'pending', email: 'efface2@example.invalid');
    DB::table('personnes')->insert([
        'workspace_id' => $a->id, 'person_key' => 'pky', 'email' => 'efface2@example.invalid',
        'email_hash' => hash('sha256', 'efface2@example.invalid'), 'premiere_source' => 'site',
        'premiere_source_at' => now(), 'legal_basis' => 'consent',
    ]);
    $owner = l5Compte($a, 'owner');

    $rep = $this->actingAs($owner)->postJson("/api/v1/rgpd/requests/{$demande->id}/process")->assertOk();

    expect($rep->json('mise_a_l_ecart.personnes'))->toBe(1)
        ->and($rep->json('mise_a_l_ecart.abonnements'))->toBeInt()
        ->and($rep->json('supprime'))->toBe(0);
});

test('un viewer ne voit ni subject_email ni metadata.note (toute la ligne est masquée)', function () {
    $a = l5Espace();
    $d = l5Demande($a, 'pending', email: 'pierre.durand@example.invalid');
    $d->update(['metadata' => ['note' => 'Rappeler Pierre Durand au 0612345678']]);
    $viewer = l5Compte($a, 'viewer');

    $json = $this->actingAs($viewer)->getJson('/api/v1/rgpd/requests?statut=ouverte')->assertOk()->getContent();

    expect($json)->not->toContain('0612345678')->not->toContain('Pierre Durand')->not->toContain('pierre.durand');
});

test('une panne de la liste ne laisse PAS le message dans le journal, même via report()', function () {
    $a = l5Espace();
    l5Demande($a, 'pending', email: 'pierre.durand@example.invalid');
    $owner = l5Compte($a, 'owner');
    RgpdRequest::retrieved(function (): void {
        throw new RuntimeException('SQLSTATE[XX000] ... pierre.durand@example.invalid');
    });
    $vus = [];
    Log::listen(function ($e) use (&$vus): void {
        $vus[] = $e->message . json_encode($e->context);
    });

    $this->actingAs($owner)->getJson('/api/v1/rgpd/requests?statut=ouverte')->assertOk();

    expect(implode("\n", $vus))->not->toContain('pierre.durand');
});

test('la ligne d’une demande d’effacement est masquée pour operator et viewer, en clair pour admin et owner', function () {
    $a = l5Espace();
    $d = l5Demande($a, 'pending', email: 'pierre.durand@example.invalid');
    $d->update(['metadata' => ['note' => 'Rappeler Pierre Durand au 0612345678']]);
    DB::table('rgpd_requests')->where('id', $d->id)->update(['export_token' => 'jeton-secret-xyz']);

    // operator possède contacts.view_pii, mais PAS rgpd.view_pii : il ne doit rien voir.
    foreach (['operator', 'viewer'] as $role) {
        $json = $this->actingAs(l5Compte($a, $role))->getJson('/api/v1/rgpd/requests?statut=ouverte')->assertOk()->getContent();
        expect($json)->not->toContain('0612345678', $role)
            ->not->toContain('Pierre Durand', $role)
            ->not->toContain('pierre.durand', $role)
            ->not->toContain('jeton-secret-xyz', $role);
    }

    foreach (['admin', 'owner'] as $role) {
        $json = $this->actingAs(l5Compte($a, $role))->getJson('/api/v1/rgpd/requests?statut=ouverte')->assertOk()->getContent();
        expect($json)->toContain('pierre.durand@example.invalid', $role)->toContain('0612345678', $role);
    }
});
