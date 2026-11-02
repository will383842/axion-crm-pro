<?php

/**
 * NR-11 — R6, la synchro du site vers le CRM (`POST /api/internal/site-sync`).
 *
 * Drapeau éteint ⇒ 503 et rien d’écrit ; signature fausse, horodatage trop vieux
 * ⇒ refus ; et UN événement de chaque type connu donne le même résultat
 * qu’aujourd’hui (photo `instantanes/nr11-evenements.json` : statut HTTP, code
 * d’erreur éventuel, lignes créées).
 *
 * Gardes existantes rattachées au groupe : `SiteSyncIngestTest` (49 tests),
 * `SiteGdprTest`.
 *
 * Secret FACTICE construit à l’exécution ; adresses en `@example.test`.
 */

use App\Crm\Ingest\SiteSyncEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\NonRegression\Instantane;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const NR11_URL = '/api/internal/site-sync';

function nr11Secret(): string
{
    return 'secret-factice-nr11-' . str_repeat('c3d4', 8);
}

beforeEach(function () {
    config([
        'crm.ingest.enabled' => true,
        'crm.ingest.candidates_enabled' => false,
        'crm.ingest.personnes_enabled' => false,
        'crm.ingest.dossier_enabled' => false,
        'crm.ingest.hmac_secret' => nr11Secret(),
        'crm.ingest.business_workspace' => 'axion-ia',
        'crm.ingest.max_clock_skew_seconds' => 300,
    ]);
    DB::table('workspaces')->insert([
        'id' => (string) Str::uuid(), 'slug' => 'axion-ia', 'name' => 'Espace NR-11', 'settings' => '{}',
        'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
});

/** @return array<string, mixed> */
function nr11Evenement(string $type, ?string $formulaire = null): array
{
    return [
        'schema_version' => 1,
        'event_id' => 'nr11-' . $type . '-' . ($formulaire ?? 'x') . '-' . Str::lower(Str::random(6)),
        'event_type' => $type,
        'form_type' => $type === 'form_submission' ? ($formulaire ?? 'audit') : null,
        'occurred_at' => '2026-08-14T09:30:00+02:00',
        'subject_ref' => 'site:submission:' . Str::uuid(),
        'person' => [
            'person_key' => hash('sha256', 'personne@nr11.example.test'),
            'email' => 'Personne@Nr11.Example.Test',
            'first_name' => 'Jeanne',
            'last_name' => 'Exemple',
        ],
        'company' => ['siren' => '900000101', 'name' => 'Societe Nr11', 'postcode' => '69001', 'city' => 'Lyon'],
        'consent' => ['version' => 'v1-2026-05-24', 'at' => '2026-08-14T09:29:00+02:00', 'text_ref' => 'unified-contact-form'],
        'tags' => [],
        'payload' => ['page' => '/fr/contact'],
    ];
}

function nr11Poster(array $evenement, ?string $secret = null, ?string $horodatage = null): TestResponse
{
    $corps = json_encode($evenement, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $horodatage ??= (string) time();

    return test()->call('POST', NR11_URL, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SITE_TIMESTAMP' => $horodatage,
        'HTTP_X_SITE_SIGNATURE' => hash_hmac('sha256', $horodatage . '.' . $corps, $secret ?? nr11Secret()),
    ], $corps);
}

/** @return array<string, int> */
function nr11Comptes(): array
{
    return [
        'entreprises' => DB::table('companies')->count(),
        'contacts' => DB::table('contacts')->count(),
        'candidats' => DB::table('candidates')->count(),
        'activites' => DB::table('activities')->count(),
        'oppositions' => DB::table('opt_out')->count(),
    ];
}

test('NR-11 drapeau éteint : 503 et rien n’est écrit', function () {
    config(['crm.ingest.enabled' => false]);
    $avant = nr11Comptes();

    nr11Poster(nr11Evenement('form_submission'))->assertStatus(503)->assertJsonPath('error', 'ingest_disabled');

    expect(nr11Comptes())->toBe($avant);
});

test('NR-11 signature fausse, secret absent ou horodatage trop vieux : refus, rien n’est écrit', function () {
    $avant = nr11Comptes();

    nr11Poster(nr11Evenement('form_submission'), 'mauvais-secret')->assertStatus(401);
    nr11Poster(nr11Evenement('form_submission'), null, (string) (time() - 3600))->assertStatus(401);
    config(['crm.ingest.hmac_secret' => '']);
    expect(nr11Poster(nr11Evenement('form_submission'))->getStatusCode())->toBeGreaterThanOrEqual(400);

    expect(nr11Comptes())->toBe($avant);
});

test('NR-11 un événement de chaque type connu donne le résultat de la photo de référence', function () {
    $resultats = [];
    foreach (SiteSyncEvent::EVENT_TYPES as $type) {
        $avant = nr11Comptes();
        $reponse = nr11Poster(nr11Evenement($type));
        $apres = nr11Comptes();

        $resultats[$type] = [
            'statut' => $reponse->getStatusCode(),
            'erreur' => $reponse->json('error'),
            'cree' => array_filter(array_map(fn ($k) => $apres[$k] - $avant[$k], array_combine(array_keys($apres), array_keys($apres)))),
        ];
    }

    Instantane::verifier('nr11-evenements', $resultats);
});

test('NR-11 chaque type de formulaire connu est accepté (200), sauf « recrutement » fermé avec les candidats', function () {
    foreach (SiteSyncEvent::FORM_TYPES as $formulaire) {
        $r = nr11Poster(nr11Evenement('form_submission', $formulaire));
        // « recrutement » suit le drapeau candidats (éteint par défaut) : 503 constaté.
        expect($r->getStatusCode())->toBe($formulaire === 'recrutement' ? 503 : 200);
    }
});

test('NR-11 rejouer le même événement ne crée pas de doublon', function () {
    $e = nr11Evenement('form_submission');
    nr11Poster($e)->assertOk();
    $apres = nr11Comptes();

    nr11Poster($e)->assertOk();

    expect(nr11Comptes())->toBe($apres);
});

test('NR-11 un type d’événement inconnu est refusé', function () {
    nr11Poster(nr11Evenement('peu_importe'))->assertStatus(422);
});
