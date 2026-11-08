<?php

use App\Envoi\Sante\DepotSanteDb;
use App\Models\User;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(fn () => $this->seed(PermissionsAndRolesSeeder::class));

test('GET /api/v1/envoi/sante exige une session', function () {
    $this->getJson('/api/v1/envoi/sante')->assertUnauthorized();
});

test('l’écran « Santé des envois » rend le dernier relevé, les constats ouverts et les pauses — de SON espace seulement', function () {
    $ws = (string) Str::uuid();
    $autre = (string) Str::uuid();
    foreach ([$ws, $autre] as $id) {
        DB::table('workspaces')->insert(['id' => $id, 'name' => 'T', 'slug' => 'k15-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach ([$ws, $autre] as $id) {
        DB::table('envoi_serveurs')->insert(['workspace_id' => $id, 'source' => 'src', 'url_base' => 'https://envoi.example.test']);
    }
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'k15-' . Str::random(6) . '@example.test', 'name' => 'K15',
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $ws, 'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($ws);
    $user->assignRole('admin');
    $depot = new DepotSanteDb;
    $t = new DateTimeImmutable('2026-11-13 10:00:00', new DateTimeZone('UTC'));
    $depot->noterReleve($ws, 'src', $t, true, ['disque_libre_pct' => 5.0]);
    $depot->ouvrir($ws, 'src', 'disque', 'critique', 'disque presque plein', $t);
    $depot->noterReleve($autre, 'src', $t, true, ['disque_libre_pct' => 99.0]);
    $depot->ouvrir($autre, 'src', 'ntp', 'attention', 'secret-autre-espace', $t);

    $json = $this->actingAs($user)->getJson('/api/v1/envoi/sante')->assertOk()->json();

    expect($json['serveurs'])->toHaveCount(1);
    $s = $json['serveurs'][0];
    expect($s['source'])->toBe('src')
        ->and($s['dernier_releve']['ok'])->toBeTrue()
        ->and($s['dernier_releve']['mesures']['disque_libre_pct'])->toEqual(5.0)
        ->and(array_column($s['constats'], 'code'))->toBe(['disque'])
        ->and($s['constats'][0]['gravite'])->toBe('critique');
    expect(json_encode($json))->not->toContain('secret-autre-espace');
});

test('S1 : « Santé des envois » est refusée au viewer et à l’operator (403), accordée à l’admin (200) ; un viewer ne lit ni domaines ni listes noires ni pauses', function (string $role, int $statut) {
    $ws = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $ws, 'name' => 'T', 'slug' => 'k15s1-' . substr($ws, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'k15s1-' . Str::random(6) . '@example.test', 'name' => 'K15',
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $ws, 'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($ws);
    $user->assignRole($role);
    (new DepotSanteDb)->ouvrir($ws, 'src', 'liste_noire:ip:203.0.113.9', 'critique', 'secret-liste-noire', new DateTimeImmutable('2026-11-13 10:00:00'));

    $rep = $this->actingAs($user)->getJson('/api/v1/envoi/sante')->assertStatus($statut);
    if ($statut === 403) {
        expect($rep->getContent())->not->toContain('secret-liste-noire')->not->toContain('203.0.113.9');
    }
})->with([['viewer', 403], ['operator', 403], ['admin', 200]]);
