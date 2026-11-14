<?php

use App\Crm\Personnes\Abonnements;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * CRM-G2 — le CRM accepte l'événement « guide réseaux » du site, de bout en bout.
 *
 * Route : `POST /api/internal/site-sync` (contrat v3, `lead_magnet_requested`,
 * `subject_ref = site:guide-reseau:<run>`). Charge calquée sur celle du site :
 * event_id, person_key, prénom, origine (mot / réseau / post / compte
 * « page_principale »), version du texte, consentement. Adresses en `.test`
 * uniquement (dépôt PUBLIC) ; aucun envoi réel.
 */
const G2_SECRET = 'secret-de-test-g2-' . 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6';

const G2_EMAIL = 'zz.guide-reseau@exemple.test';

beforeEach(function () {
    config([
        'crm.ingest.enabled' => true,
        'crm.ingest.personnes_enabled' => true,
        'crm.ingest.hmac_secret' => G2_SECRET,
        'crm.ingest.business_workspace' => 'axion-ia',
        'crm.ingest.max_clock_skew_seconds' => 300,
        'origines.v3_enabled' => true,
    ]);

    Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'axion-ia', 'name' => 'Axion-IA', 'settings' => []]);
});

/** @param  array<string, mixed>  $sur */
function g2Evenement(array $sur = [], string $at = '2026-11-01T10:00:00Z'): array
{
    return array_replace_recursive([
        'schema_version' => 3,
        'event_id' => (string) Str::uuid(),
        'event_type' => 'lead_magnet_requested',
        'occurred_at' => $at,
        'source_slug' => 'guide-reseau',
        'subject_ref' => 'site:guide-reseau:' . Str::uuid(),
        'person' => ['person_key' => hash('sha256', 'g2|' . G2_EMAIL), 'email' => G2_EMAIL, 'first_name' => 'Alice'],
        'consent' => ['version' => 'guide-reseau-m1-v1', 'at' => $at, 'text_ref' => 'guide-mention'],
        'payload' => ['newsletter_optin' => false],
        'origines' => [[
            'mot' => 'guide', 'reseau' => 'instagram', 'guide' => 'guide-ia', 'date' => $at,
            'post_ref' => 'post_ref_test_01', 'canal' => 'commentaire', 'famille' => 'guide_reseau',
            'detail' => 'guide_ia', 'compte' => 'page_principale', 'version_texte' => 'm1-v1',
        ]],
    ], $sur);
}

function g2Poster(array $event, ?string $secret = null): TestResponse
{
    $body = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $timestamp = (string) time();

    return test()->call('POST', '/api/internal/site-sync', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SITE_TIMESTAMP' => $timestamp,
        'HTTP_X_SITE_SIGNATURE' => hash_hmac('sha256', $timestamp . '.' . $body, $secret ?? G2_SECRET),
    ], $body);
}

function g2Personne(): ?object
{
    return DB::table('personnes')->where('person_key', hash('sha256', 'g2|' . G2_EMAIL))->first();
}

test('CRM-G2 1 — charge du site acceptée : personne créée avec prénom, origine et version du texte', function () {
    g2Poster(g2Evenement())->assertOk();

    $p = g2Personne();
    $origines = json_decode((string) $p->origines, true);
    expect($p->first_name)->toBe('Alice')
        ->and($p->premiere_source)->toBe('guide-reseau')
        ->and($origines)->toHaveCount(1)
        ->and($origines[0]['compte'])->toBe('page_principale')
        ->and($origines[0]['version_texte'])->toBe('m1-v1');
});

test('CRM-G2 2 — interrupteur de réception éteint : 503 et rien n’est écrit', function () {
    config(['crm.ingest.enabled' => false]);

    g2Poster(g2Evenement())->assertStatus(503);

    expect(DB::table('personnes')->count())->toBe(0)
        ->and(DB::table('activities')->count())->toBe(0);
});

test('CRM-G2 3 — signature invalide : refus, rien n’est écrit', function () {
    $r = g2Poster(g2Evenement(), 'mauvais-secret-' . str_repeat('x', 32));

    expect($r->status())->toBeIn([401, 403])
        ->and(DB::table('personnes')->count())->toBe(0);
});

test('CRM-G2 4 — rejeu du même event_id : UN SEUL effet', function () {
    $e = g2Evenement();
    g2Poster($e)->assertOk();
    $avant = [DB::table('personnes')->count(), DB::table('activities')->count(), count(json_decode((string) g2Personne()->origines, true))];

    g2Poster($e)->assertOk();

    expect([DB::table('personnes')->count(), DB::table('activities')->count(), count(json_decode((string) g2Personne()->origines, true))])
        ->toBe($avant)
        ->and(DB::table('personnes')->count())->toBe(1);
});

test('CRM-G2 5 — première source conservée quand un second événement arrive pour la même personne', function () {
    g2Poster(g2Evenement())->assertOk();
    $premiere = g2Personne()->premiere_source_at;

    g2Poster(g2Evenement(['source_slug' => 'autre-source', 'origines' => [['post_ref' => 'post_ref_test_02']]], '2026-11-02T10:00:00Z'))->assertOk();

    $p = g2Personne();
    expect($p->premiere_source)->toBe('guide-reseau')
        ->and($p->premiere_source_at)->toBe($premiere)
        ->and(DB::table('personnes')->count())->toBe(1);
});

test('CRM-G2 6 — aucun abonnement à la lettre quand newsletter_optin est absent ou faux', function () {
    $absent = g2Evenement();
    unset($absent['payload']['newsletter_optin']);
    g2Poster($absent)->assertOk();
    g2Poster(g2Evenement(['payload' => ['newsletter_optin' => false]], '2026-11-02T10:00:00Z'))->assertOk();

    expect(DB::table('abonnements')->count())->toBe(0);
});

test('CRM-G2 7 — une personne opposée reste opposée après l’événement', function () {
    g2Poster(g2Evenement())->assertOk();
    $opposee = g2Evenement(['schema_version' => 2, 'opposition' => ['actif' => true, 'date' => '2026-11-01T11:00:00Z']], '2026-11-02T10:00:00Z');
    unset($opposee['origines']);
    g2Poster($opposee)->assertOk();
    $opposition = g2Personne()->opposition_at;
    expect($opposition)->not->toBeNull();

    g2Poster(g2Evenement([], '2026-11-03T10:00:00Z'))->assertOk();

    $p = g2Personne();
    expect($p->opposition_at)->toBe($opposition)
        ->and(DB::table('abonnements')->count())->toBe(0)
        ->and(DB::table('personnes')->count())->toBe(1);
});

test('CRM-G2 8 — opposition reçue d’abord, puis inscription à la lettre et événement guide : l’opposition l’emporte quel que soit l’ordre', function () {
    // Personne inconnue : l'opposition est inscrite à la liste d'opposition.
    $opposee = g2Evenement(['schema_version' => 2, 'opposition' => ['actif' => true, 'date' => '2026-11-01T11:00:00Z']], '2026-11-02T10:00:00Z');
    unset($opposee['origines']);
    g2Poster($opposee)->assertOk();
    $empreinte = hash('sha256', G2_EMAIL);
    expect(DB::table('opt_out')->where('scope', 'business')->where('email_hash', $empreinte)->exists())->toBeTrue();

    // APRÈS l'opposition : un VRAI événement d'inscription à la lettre (c'est lui, et lui seul, qui abonne), puis le guide.
    $inscription = g2Evenement(['event_type' => 'newsletter_optin', 'source_slug' => 'newsletter'], '2026-11-03T10:00:00Z');
    unset($inscription['origines']);
    g2Poster($inscription)->assertOk();
    g2Poster(g2Evenement(['payload' => ['newsletter_optin' => true]], '2026-11-04T10:00:00Z'))->assertOk();

    // Aucun abonnement actif, aucune fiche ouverte pour cette personne ; l'opposition reste posée, rien n'est supprimé.
    expect(DB::table('abonnements')->where('statut', '!=', 'desabonne')->count())->toBe(0)
        ->and(DB::table('personnes')->count())->toBe(0)
        ->and(DB::table('opt_out')->where('scope', 'business')->where('email_hash', $empreinte)->count())->toBe(1);
});

test('CRM-G2 9 — guide reçu APRÈS une opposition : rien n’est écrit, les données déjà là sont gardées, événement ignoré journalisé', function () {
    g2Poster(g2Evenement())->assertOk();
    $opposition = g2Evenement(['schema_version' => 2, 'opposition' => ['actif' => true, 'date' => '2026-11-01T11:00:00Z']], '2026-11-02T10:00:00Z');
    unset($opposition['origines']);
    g2Poster($opposition)->assertOk();
    $avant = g2Personne();
    Log::spy();

    // Guide tardif : autre date, donc une autre clé d'origine — l'ancien code l'ajoutait à la fiche opposée.
    g2Poster(g2Evenement(['payload' => ['newsletter_optin' => true]], '2026-11-04T10:00:00Z'))->assertOk();

    $apres = g2Personne();
    expect(json_decode((string) $apres->origines, true))->toHaveCount(1)
        ->and($apres->origines)->toBe($avant->origines)
        ->and($apres->accords_at)->toBe($avant->accords_at)
        ->and($apres->opposition_at)->toBe($avant->opposition_at)
        ->and(DB::table('personnes')->count())->toBe(1)
        ->and(DB::table('abonnements')->count())->toBe(0);
    Log::shouldHaveReceived('info')->withArgs(fn ($message) => $message === 'Événement ignoré : personne opposée')->once();
});

test('CRM-G2 10 — adresse sur la liste d’opposition (fiche gardée) : un guide tardif n’ajoute rien', function () {
    g2Poster(g2Evenement())->assertOk();
    DB::table('opt_out')->insert([
        'email' => null,
        'email_hash' => hash('sha256', G2_EMAIL),
        'scope' => 'business',
        'source' => 'test',
        'reason' => 'effacement',
        'created_at' => now(),
    ]);
    $avant = g2Personne();

    g2Poster(g2Evenement([], '2026-11-04T10:00:00Z'))->assertOk();

    $apres = g2Personne();
    expect($apres)->not->toBeNull()
        ->and(json_decode((string) $apres->origines, true))->toHaveCount(1)
        ->and($apres->origines)->toBe($avant->origines)
        ->and(DB::table('personnes')->count())->toBe(1);
});

test('CRM-G2 11 — sonde A : fiche opposée (opposition_at posée), puis inscription avec un autre nom : rien écrasé, aucun abonnement', function () {
    g2Poster(g2Evenement())->assertOk();
    DB::table('personnes')->where('id', g2Personne()->id)->update(['opposition_at' => '2026-11-02 10:00:00']);
    $avant = g2Personne();

    $inscription = g2Evenement(
        ['event_type' => 'newsletter_optin', 'source_slug' => 'newsletter', 'person' => ['first_name' => 'Bob', 'last_name' => 'Nouveau']],
        '2026-11-05T10:00:00Z',
    );
    unset($inscription['origines']);
    g2Poster($inscription)->assertOk();

    $apres = g2Personne();
    expect($apres->first_name)->toBe($avant->first_name)
        ->and($apres->last_name)->toBe($avant->last_name)
        ->and($apres->opposition_at)->toBe($avant->opposition_at)
        ->and(DB::table('abonnements')->where('statut', '!=', 'desabonne')->count())->toBe(0)
        ->and(DB::table('personnes')->count())->toBe(1);
});

test('CRM-G2 12 — sonde B : opposition sans adresse (aucune ligne opt_out), puis inscription : aucun abonnement actif, personne non éligible', function () {
    g2Poster(g2Evenement())->assertOk();
    $sansAdresse = g2Evenement(['schema_version' => 2, 'opposition' => ['actif' => true, 'date' => '2026-11-02T10:00:00Z']], '2026-11-02T10:00:00Z');
    unset($sansAdresse['origines'], $sansAdresse['person']['email']);
    g2Poster($sansAdresse)->assertOk();
    expect(g2Personne()->opposition_at)->not->toBeNull()
        ->and(DB::table('opt_out')->where('scope', 'business')->count())->toBe(0);

    $inscription = g2Evenement(['event_type' => 'newsletter_optin', 'source_slug' => 'newsletter'], '2026-11-05T10:00:00Z');
    unset($inscription['origines']);
    g2Poster($inscription)->assertOk();

    $workspaceId = (string) DB::table('workspaces')->where('slug', 'axion-ia')->value('id');
    expect(DB::table('abonnements')->where('statut', '!=', 'desabonne')->count())->toBe(0)
        ->and(Abonnements::eligiblesALaDiffusion($workspaceId)->count())->toBe(0)
        ->and(DB::table('personnes')->count())->toBe(1);
});

test('CRM-G2 13 — fiche opposée sans opt_out mais abonnée par ailleurs : jamais éligible à la diffusion', function () {
    g2Poster(g2Evenement())->assertOk();
    $personne = g2Personne();
    $workspaceId = (string) DB::table('workspaces')->where('slug', 'axion-ia')->value('id');
    DB::table('abonnements')->insert([
        'workspace_id' => $workspaceId, 'personne_id' => $personne->id, 'canal' => 'lettre', 'statut' => 'abonne',
        'consent_version' => 'v1', 'consent_at' => now(), 'dernier_evenement_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    expect(Abonnements::eligiblesALaDiffusion($workspaceId)->count())->toBe(1);

    DB::table('personnes')->where('id', $personne->id)->update(['opposition_at' => now()]);

    expect(Abonnements::eligiblesALaDiffusion($workspaceId)->count())->toBe(0);
});
