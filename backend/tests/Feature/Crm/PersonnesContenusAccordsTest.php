<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * PV-6 (côté CRM) — réception de « Contenus et accords » par la synchro.
 * Attaqué par le réseau, signature comprise, comme `PersonnesIngestTest`.
 * Adresses en `.test` uniquement, aucune donnée de personne (dépôt PUBLIC).
 */
define('PV6_SECRET', 'secret-de-test-pv6-' . str_repeat('e5f6', 12));

const PV6_EMAIL = 'zz.accords@example.test';

beforeEach(function () {
    config([
        'crm.ingest.enabled' => true,
        'crm.ingest.personnes_enabled' => true,
        'crm.ingest.hmac_secret' => PV6_SECRET,
        'crm.ingest.business_workspace' => 'axion-ia',
        'crm.ingest.max_clock_skew_seconds' => 300,
    ]);

    if (DB::table('workspaces')->where('slug', 'axion-ia')->doesntExist()) {
        DB::table('workspaces')->insert([
            'id' => (string) Str::uuid(), 'slug' => 'axion-ia', 'name' => 'Axion-IA', 'settings' => '{}',
            'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
});

/** @param  array<string, mixed>  $extra */
function pv6Event(string $at, array $extra = [], int $version = 2): array
{
    return array_replace([
        'schema_version' => $version,
        'event_id' => (string) Str::uuid(),
        'event_type' => 'lead_magnet_requested',
        'occurred_at' => $at,
        'subject_ref' => 'site:guide_request:' . Str::uuid(),
        'person' => ['person_key' => hash('sha256', 'pv6|' . PV6_EMAIL), 'email' => PV6_EMAIL],
    ], $extra);
}

/** @param  array<string, mixed>  $event */
function pv6Post(array $event): TestResponse
{
    $body = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $timestamp = (string) time();

    return test()->call('POST', '/api/internal/site-sync', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SITE_TIMESTAMP' => $timestamp,
        'HTTP_X_SITE_SIGNATURE' => hash_hmac('sha256', $timestamp . '.' . $body, PV6_SECRET),
    ], $body);
}

function pv6Personne(): object
{
    return DB::table('personnes')->where('person_key', hash('sha256', 'pv6|' . PV6_EMAIL))->first();
}

/** @return array<string, mixed> */
function pv6Champs(string $guide = 'guide-ia', string $date = '2026-11-02T10:00:00Z', string $mot = 'guide-ia'): array
{
    return [
        'origine_contenu' => ['mot' => $mot, 'reseau' => 'reseau-a', 'guide' => $guide, 'date' => $date],
        'guides_recus' => [['guide' => $guide, 'date' => $date]],
        'consentements' => [['nature' => 'information', 'date' => $date, 'source' => 'mot-' . $mot . ':reseau-a', 'version' => 'M1']],
        'opposition' => ['actif' => false],
    ];
}

test('PV-6 1 — charge complète : les quatre champs sont stockés', function () {
    pv6Post(pv6Event('2026-11-02T10:00:00Z', pv6Champs()))->assertOk();

    $p = pv6Personne();
    expect(json_decode($p->origine_contenu, true)['reseau'])->toBe('reseau-a')
        ->and(json_decode($p->guides_recus, true))->toHaveCount(1)
        ->and(json_decode($p->consentements, true)[0]['version'])->toBe('M1')
        ->and($p->opposition_at)->toBeNull();
});

test('PV-6 2 — charge ancienne sans les champs (version 1) : rien ne casse, colonnes vides', function () {
    pv6Post(pv6Event('2026-11-02T10:00:00Z', [], 1))->assertOk();

    $p = pv6Personne();
    expect($p)->not->toBeNull()
        ->and($p->origine_contenu)->toBeNull()
        ->and($p->guides_recus)->toBeNull()
        ->and($p->consentements)->toBeNull();
});

test('PV-6 3 — l’opposition gagne : écart de tout envoi, données gardées', function () {
    pv6Post(pv6Event('2026-11-02T10:00:00Z', pv6Champs()))->assertOk();
    pv6Post(pv6Event('2026-11-03T10:00:00Z', ['opposition' => ['actif' => true, 'date' => '2026-11-03T10:00:00Z']]))->assertOk();

    $hash = hash('sha256', PV6_EMAIL);
    expect(DB::table('opt_out')->where('email_hash', $hash)->where('scope', 'business')->count())->toBe(1)
        ->and(DB::table('opt_out')->where('email_hash', $hash)->value('email'))->toBeNull()
        ->and(pv6Personne()->opposition_at)->not->toBeNull()
        // données gardées
        ->and(json_decode(pv6Personne()->guides_recus, true))->toHaveCount(1);

    // une levée ultérieure n'annule rien
    pv6Post(pv6Event('2026-11-04T10:00:00Z', ['opposition' => ['actif' => false]]))->assertOk();
    expect(pv6Personne()->opposition_at)->not->toBeNull()
        ->and(DB::table('opt_out')->where('email_hash', $hash)->count())->toBe(1);
});

test('PV-6 4 — ordre inverse des messages : le plus récent gagne, les listes s’additionnent', function () {
    $recent = pv6Champs('guide-b', '2026-11-05T10:00:00Z', 'mot-recent');
    $ancien = pv6Champs('guide-a', '2026-11-01T10:00:00Z', 'mot-ancien');

    pv6Post(pv6Event('2026-11-05T10:00:00Z', $recent))->assertOk();
    pv6Post(pv6Event('2026-11-01T10:00:00Z', $ancien))->assertOk();

    $p = pv6Personne();
    expect(json_decode($p->origine_contenu, true)['mot'])->toBe('mot-recent')
        ->and(collect(json_decode($p->guides_recus, true))->pluck('guide')->sort()->values()->all())->toBe(['guide-a', 'guide-b']);
});

test('PV-6 5 — aucune suppression propagée : une charge plus courte ou vide ne retire rien', function () {
    pv6Post(pv6Event('2026-11-02T10:00:00Z', pv6Champs()))->assertOk();
    pv6Post(pv6Event('2026-11-03T10:00:00Z', ['guides_recus' => [], 'consentements' => []]))->assertOk();

    $p = pv6Personne();
    expect(json_decode($p->guides_recus, true))->toHaveCount(1)
        ->and(json_decode($p->consentements, true))->toHaveCount(1)
        ->and(json_decode($p->origine_contenu, true)['mot'])->toBe('guide-ia');
});

test('PV-6 6 — pseudo de réseau et texte de message refusés par le contrat', function () {
    pv6Post(pv6Event('2026-11-02T10:00:00Z', ['origine_contenu' => ['mot' => 'guide-ia', 'pseudo' => 'x']]))->assertStatus(422);
    pv6Post(pv6Event('2026-11-02T10:00:00Z', ['message' => 'x']))->assertStatus(422);
});

test('PV-6 7 — drapeau fermé : les champs sont ignorés, rien n’est écrit', function () {
    config(['crm.ingest.personnes_enabled' => false]);
    pv6Post(pv6Event('2026-11-02T10:00:00Z', pv6Champs()))->assertStatus(503);

    expect(DB::table('personnes')->count())->toBe(0)
        ->and(DB::table('opt_out')->count())->toBe(0);
});

test('PV-6 8 — opposition_at : une date future du site est ramenée à maintenant', function () {
    $futur = now()->addDays(30)->utc()->format('Y-m-d\TH:i:s\Z');
    pv6Post(pv6Event(now()->utc()->format('Y-m-d\TH:i:s\Z'), ['opposition' => ['actif' => true, 'date' => $futur]]))->assertOk();

    $posee = Carbon\Carbon::parse(pv6Personne()->opposition_at);
    expect($posee->lessThanOrEqualTo(now()->addMinute()))->toBeTrue();
});
