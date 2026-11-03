<?php

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Contrat v3 de l'origine (ADR-CONTRAT-V3-ORIGINE), côté CRM : stockage de
 * l'historique en union sans retrait, archive au-delà de 50, lecture seule dans
 * l'API des personnes, filtre famille/détail. Tout derrière `origines.v3_enabled`
 * (éteint par défaut). Adresses en `.test` uniquement (dépôt PUBLIC).
 */
const OV3_SECRET = 'secret-de-test-ov3-' . 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6';

const OV3_EMAIL = 'zz.origines@exemple.test';

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    config([
        'crm.console_v2' => true,
        'crm.ingest.enabled' => true,
        'crm.ingest.personnes_enabled' => true,
        'crm.ingest.hmac_secret' => OV3_SECRET,
        'crm.ingest.business_workspace' => 'axion-ia',
        'crm.ingest.max_clock_skew_seconds' => 300,
        'origines.v3_enabled' => true,
    ]);

    $this->workspace = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'axion-ia', 'name' => 'Axion-IA', 'settings' => []]);
});

function ov3Entree(array $sur = []): array
{
    return array_replace([
        'mot' => 'guide', 'reseau' => 'instagram', 'guide' => 'guide-ia', 'date' => '2026-11-01T10:00:00Z',
        'post_ref' => 'post_ref_test_01', 'canal' => 'commentaire', 'famille' => 'guide_reseau', 'detail' => 'guide_ia',
        'compte' => 'page_principale',
    ], $sur);
}

function ov3Evenement(string $at, array $origines, int $version = 3): array
{
    return [
        'schema_version' => $version,
        'event_id' => (string) Str::uuid(),
        'event_type' => 'lead_magnet_requested',
        'occurred_at' => $at,
        'subject_ref' => 'site:guide-reseau:' . Str::uuid(),
        'person' => ['person_key' => hash('sha256', 'ov3|' . OV3_EMAIL), 'email' => OV3_EMAIL],
        'origines' => $origines,
    ];
}

function ov3Post(array $event): TestResponse
{
    $body = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $timestamp = (string) time();

    return test()->call('POST', '/api/internal/site-sync', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SITE_TIMESTAMP' => $timestamp,
        'HTTP_X_SITE_SIGNATURE' => hash_hmac('sha256', $timestamp . '.' . $body, OV3_SECRET),
    ], $body);
}

function ov3Personne(): ?object
{
    return DB::table('personnes')->where('person_key', hash('sha256', 'ov3|' . OV3_EMAIL))->first();
}

function ov3Origines(): array
{
    return json_decode((string) (ov3Personne()->origines ?? '[]'), true);
}

test('OV3 1 — charge v3 : l\'historique est stocké, origine_contenu inchangé', function () {
    ov3Post(ov3Evenement('2026-11-01T10:00:00Z', [ov3Entree()]) + ['origine_contenu' => ['mot' => 'guide', 'reseau' => 'instagram', 'guide' => 'guide-ia', 'date' => '2026-11-01T10:00:00Z']])->assertOk();

    $p = ov3Personne();
    expect(ov3Origines())->toHaveCount(1)
        ->and(ov3Origines()[0]['famille'])->toBe('guide_reseau')
        ->and(json_decode($p->origine_contenu, true)['guide'])->toBe('guide-ia');
});

test('OV3 2 — interrupteur éteint : la charge v3 est refusée en 503, rien n\'est écrit', function () {
    config(['origines.v3_enabled' => false]);

    ov3Post(ov3Evenement('2026-11-01T10:00:00Z', [ov3Entree()]))->assertStatus(503);

    expect(DB::table('personnes')->count())->toBe(0);
});

test('OV3 3 — v1 et v2 restent acceptées, interrupteur éteint comme allumé', function (bool $allume) {
    config(['origines.v3_enabled' => $allume]);
    $e = ov3Evenement('2026-11-01T10:00:00Z', []);
    unset($e['origines']);

    ov3Post(['schema_version' => 1] + $e)->assertOk();
    ov3Post(['schema_version' => 2] + $e)->assertOk();
})->with([true, false]);

test('OV3 4 — version 4 refusée ; « origines » en v2 refusé (champ inconnu)', function () {
    ov3Post(ov3Evenement('2026-11-01T10:00:00Z', [ov3Entree()], 4))->assertStatus(422);
    ov3Post(ov3Evenement('2026-11-01T10:00:00Z', [ov3Entree()], 2))->assertStatus(422);
});

test('OV3 5 — union sans retrait, idempotente, quel que soit l\'ordre d\'arrivée', function () {
    $a = ov3Entree();
    $b = ov3Entree(['post_ref' => 'post_ref_test_02', 'date' => '2026-11-02T10:00:00Z']);

    ov3Post(ov3Evenement('2026-11-02T10:00:00Z', [$b]))->assertOk();
    ov3Post(ov3Evenement('2026-11-01T10:00:00Z', [$a]))->assertOk();
    ov3Post(ov3Evenement('2026-11-01T10:00:00Z', [$a]))->assertOk(); // rejeu

    expect(collect(ov3Origines())->pluck('post_ref')->all())->toBe(['post_ref_test_01', 'post_ref_test_02']);

    // Une charge plus courte ou vide ne retire rien.
    ov3Post(ov3Evenement('2026-11-03T10:00:00Z', []))->assertOk();
    expect(ov3Origines())->toHaveCount(2);
});

test('OV3 6 — la 51e entrée passe dans l\'archive, rien n\'est supprimé', function () {
    for ($i = 0; $i < 51; $i++) {
        ov3Post(ov3Evenement('2026-11-01T10:00:00Z', [ov3Entree([
            'post_ref' => sprintf('post_ref_%04d', $i),
            'date' => gmdate('Y-m-d\TH:i:s\Z', 1_793_000_000 + $i * 60),
        ])]))->assertOk();
    }

    $p = ov3Personne();
    $archive = DB::table('personne_origines_archive')->where('personne_id', $p->id)->get();
    expect(ov3Origines())->toHaveCount(50)
        ->and($archive)->toHaveCount(1)
        ->and(json_decode($archive[0]->origine, true)['post_ref'])->toBe('post_ref_0000');

    // Rejouer la plus ancienne ne perd rien et ne duplique pas l'archive.
    ov3Post(ov3Evenement('2026-11-01T10:00:00Z', [ov3Entree(['post_ref' => 'post_ref_0000', 'date' => gmdate('Y-m-d\TH:i:s\Z', 1_793_000_000)])]))->assertOk();
    expect(DB::table('personne_origines_archive')->where('personne_id', $p->id)->count())->toBe(1)
        ->and(count(ov3Origines()))->toBe(50);
});

test('OV3 7 — le rôle applicatif ne peut ni modifier ni supprimer l\'archive', function () {
    $role = (string) config('database.connections.pgsql_app.username', 'axion_app');
    if (DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$role]) === null) {
        $this->markTestSkipped('rôle applicatif absent');
    }
    foreach (['UPDATE', 'DELETE', 'TRUNCATE'] as $droit) {
        expect(DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS ok', [$role, 'personne_origines_archive', $droit])->ok)->toBeFalse();
    }
});

// ── API ─────────────────────────────────────────────────────────────────────

function ov3Admin(): User
{
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'ov3.admin@exemple.test', 'name' => 'Opérateur',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => test()->workspace->id, 'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($user->current_workspace_id);
    $user->assignRole('admin');
    DB::table('user_workspaces')->insertOrIgnore([
        'user_id' => $user->id, 'workspace_id' => test()->workspace->id, 'role_slug' => 'owner',
        'invited_at' => now(), 'joined_at' => now(),
    ]);

    return $user;
}

function ov3Fiche(string $email, array $origines): int
{
    return (int) DB::table('personnes')->insertGetId([
        'workspace_id' => test()->workspace->id,
        'person_key' => hash('sha256', 'ov3api|' . $email), 'email' => $email, 'email_hash' => hash('sha256', $email),
        'email_nature' => 'pro', 'premiere_source' => 'guide', 'premiere_source_at' => now(), 'derniere_interaction_at' => now(),
        'legal_basis' => 'consent', 'origines' => json_encode($origines),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('OV3 8 — l\'API expose l\'origine en lecture seule, sans empreinte de commentaire', function () {
    $this->actingAs(ov3Admin());
    $id = ov3Fiche('zz.api1@exemple.test', [ov3Entree(['commentaire_ref' => str_repeat('a', 64)]), ov3Entree(['post_ref' => 'post_ref_test_02', 'date' => '2026-11-02T10:00:00Z', 'famille' => 'carrousel', 'detail' => 'autre_non_classe'])]);

    $liste = $this->getJson('/api/v1/crm/personnes')->assertOk();
    expect($liste->json('data.0.origine.famille'))->toBe('carrousel')
        ->and($liste->json('data.0.origines_nombre'))->toBe(2);

    $fiche = $this->getJson("/api/v1/crm/personnes/{$id}")->assertOk();
    expect($fiche->json('origines'))->toHaveCount(2)
        ->and(json_encode($fiche->json()))->not->toContain(str_repeat('a', 64));
});

test('OV3 9 — filtre par famille et par détail', function () {
    $this->actingAs(ov3Admin());
    ov3Fiche('zz.api2@exemple.test', [ov3Entree()]);
    ov3Fiche('zz.api3@exemple.test', [ov3Entree(['famille' => 'carrousel', 'detail' => 'autre_non_classe'])]);

    expect($this->getJson('/api/v1/crm/personnes?origine_famille=guide_reseau')->assertOk()->json('data'))->toHaveCount(1);
    expect($this->getJson('/api/v1/crm/personnes?origine_famille=carrousel&origine_detail=autre_non_classe')->assertOk()->json('data'))->toHaveCount(1);
    expect($this->getJson('/api/v1/crm/personnes?origine_famille=guide_reseau&origine_detail=autre_non_classe')->assertOk()->json('data'))->toHaveCount(0);
    $this->getJson('/api/v1/crm/personnes?origine_famille=inconnue')->assertStatus(422);
});

test('OV3 10 — interrupteur éteint : ni champ ni filtre dans l\'API', function () {
    config(['origines.v3_enabled' => false]);
    $this->actingAs(ov3Admin());
    $id = ov3Fiche('zz.api4@exemple.test', [ov3Entree()]);

    $liste = $this->getJson('/api/v1/crm/personnes?origine_famille=carrousel')->assertOk();
    expect($liste->json('data'))->toHaveCount(1)
        ->and($liste->json('data.0'))->not->toHaveKey('origine');
    expect($this->getJson("/api/v1/crm/personnes/{$id}")->assertOk()->json())->not->toHaveKey('origines');
});
