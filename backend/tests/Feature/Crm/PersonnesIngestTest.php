<?php

use App\Crm\Personnes\Abonnements;
use App\Crm\Personnes\NatureEmail;
use App\Crm\Rgpd\SiteGdprService;
use App\Services\Rgpd\GdprErasureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * LOT L4-C — la lettre et le guide deviennent des PERSONNES du CRM.
 *
 * Les douze cas de la revue CRM du 2026-09-24 (§3.9), plus trois qui
 * manquaient au plan : le format ACTUEL de `newsletter_optin` (celui que le
 * site émet aujourd'hui, avant sa propre PR), le rebond dur, et le
 * rattachement dans l'ordre inverse (contact d'abord, lettre ensuite).
 *
 * Attaqués par le RÉSEAU, signature comprise, comme `SiteSyncIngestTest` :
 * c'est la seule façon de prouver que drapeaux, contrat et ingestion sont
 * enchaînés dans cet ordre. Adresses en `@example.invalid` uniquement (dépôt
 * PUBLIC).
 *
 * Le cas 12 (« neutraliser la garde de désordre fait rougir le test 3, et lui
 * seul ») est une preuve JOUÉE à la main, consignée dans la PR : une garde
 * qu'on pourrait désactiver par configuration pour la tester serait une garde
 * désactivable en production.
 */
define('L4C_SECRET', 'secret-de-test-l4c-' . str_repeat('c3d4', 12));

const L4C_EMAIL = 'zz.lettre@example.invalid';

beforeEach(function () {
    config([
        'crm.ingest.enabled' => true,
        'crm.ingest.candidates_enabled' => false,
        'crm.ingest.personnes_enabled' => true,
        'crm.ingest.hmac_secret' => L4C_SECRET,
        'crm.ingest.business_workspace' => 'axion-ia',
        'crm.ingest.max_clock_skew_seconds' => 300,
    ]);

    $this->ws = l4cWorkspace();
});

function l4cWorkspace(): string
{
    $existing = DB::table('workspaces')->where('slug', 'axion-ia')->value('id');
    if ($existing !== null) {
        return (string) $existing;
    }

    $id = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $id,
        'slug' => 'axion-ia',
        'name' => 'Axion-IA',
        'settings' => '{}',
        'cost_cap_eur' => 100,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function l4cKey(string $email = L4C_EMAIL): string
{
    // Une clé d'apparence valide (sha256 hex). Le vrai HMAC du site n'est pas
    // nécessaire : le CRM ne le recalcule jamais, il le reçoit.
    return hash('sha256', 'l4c|' . mb_strtolower($email));
}

/**
 * `newsletter_optin` au FORMAT ACTUEL du site (`newsletter/actions.ts`,
 * `syncNewsletterOptInToCrm`) : `occurred_at` = maintenant, AUCUN
 * `source_slug`, `payload.source` facultatif, `text_ref`
 * `newsletter-double-optin`.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function l4cOptinActuel(array $overrides = [], string $email = L4C_EMAIL): array
{
    return array_replace_recursive([
        'schema_version' => 1,
        'event_id' => (string) Str::uuid(),
        'event_type' => 'newsletter_optin',
        'occurred_at' => '2026-09-01T10:00:00.000Z',
        'subject_ref' => 'site:newsletter_subscriber:' . Str::uuid(),
        'person' => ['person_key' => l4cKey($email), 'email' => $email],
        'consent' => [
            'version' => 'newsletter-v1-2026-07-01',
            'at' => '2026-09-01T10:00:00.000Z',
            'text_ref' => 'newsletter-double-optin',
        ],
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function l4cEvent(string $type, string $at, array $overrides = [], string $email = L4C_EMAIL): array
{
    return array_replace_recursive([
        'schema_version' => 1,
        'event_id' => (string) Str::uuid(),
        'event_type' => $type,
        'occurred_at' => $at,
        'subject_ref' => 'site:newsletter_subscriber:l4c',
        'person' => ['person_key' => l4cKey($email), 'email' => $email],
    ], $overrides);
}

/** @param  array<string, mixed>  $event */
function l4cPost(array $event): TestResponse
{
    $body = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $timestamp = (string) time();

    return test()->call('POST', '/api/internal/site-sync', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SITE_TIMESTAMP' => $timestamp,
        'HTTP_X_SITE_SIGNATURE' => hash_hmac('sha256', $timestamp . '.' . $body, L4C_SECRET),
    ], $body);
}

function l4cPendingMatch(): int
{
    return DB::table('activities')->whereRaw("payload -> 'pending_match' IS NOT NULL")->count();
}

function l4cStatut(string $email = L4C_EMAIL): ?string
{
    $v = DB::table('abonnements')
        ->join('personnes', 'personnes.id', '=', 'abonnements.personne_id')
        ->where('personnes.person_key', l4cKey($email))
        ->value('abonnements.statut');

    return $v === null ? null : (string) $v;
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. Le cas d'origine : un abonné sans SIREN ni nom devient une personne
// ─────────────────────────────────────────────────────────────────────────────

test('L4-C 1 — un newsletter_optin sans SIREN ni nom donne 1 personne, 1 abonnement, 1 activité rattachée, 0 pending_match', function () {
    $r = l4cPost(l4cOptinActuel());

    $r->assertOk()->assertJsonPath('result.status', 'created')->assertJsonPath('result.subject_type', 'personne');

    expect(DB::table('personnes')->count())->toBe(1)
        ->and(DB::table('abonnements')->where('statut', 'abonne')->count())->toBe(1)
        ->and(DB::table('activities')->where('subject_type', 'personne')->count())->toBe(1)
        ->and(l4cPendingMatch())->toBe(0)
        ->and(DB::table('companies')->count())->toBe(0)
        ->and(DB::table('contacts')->count())->toBe(0);

    $p = DB::table('personnes')->first();
    expect($p->email)->toBe(L4C_EMAIL)
        ->and($p->email_hash)->toBe(hash('sha256', L4C_EMAIL))
        ->and($p->email_nature)->toBe('pro')
        ->and($p->first_name)->toBeNull()
        ->and($p->last_name)->toBeNull()
        ->and($p->premiere_source)->toBe('newsletter')
        ->and($p->legal_basis)->toBe('consent');
});

test('L4-C — le FORMAT ACTUEL du site (avant sa propre PR) passe : 200, 1 personne, 0 pending_match, jamais 422', function () {
    // Le corps EXACT d'aujourd'hui, `payload.source` compris.
    $r = l4cPost(l4cOptinActuel(['payload' => ['source' => 'guide-ia']]));

    expect($r->status())->toBe(200);
    expect(DB::table('personnes')->count())->toBe(1)
        ->and(l4cPendingMatch())->toBe(0)
        ->and(DB::table('abonnements')->value('source_slug'))->toBe('guide-ia');
});

test('L4-C — le NOUVEAU format (source_slug, placement, locale) passe aussi', function () {
    l4cPost(l4cOptinActuel([
        'source_slug' => 'newsletter',
        'payload' => ['placement' => 'guide-ia-haut', 'locale' => 'fr'],
    ]))->assertOk()->assertJsonPath('result.status', 'created');

    $p = DB::table('personnes')->first();
    expect($p->locale)->toBe('fr')
        ->and($p->premiere_source)->toBe('newsletter')
        ->and(DB::table('abonnements')->value('source_slug'))->toBe('guide-ia-haut');
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. Idempotence
// ─────────────────────────────────────────────────────────────────────────────

test('L4-C 2 — le même event_id rejoué ne produit aucun effet (noop_idempotent)', function () {
    $event = l4cOptinActuel();

    l4cPost($event)->assertOk();
    l4cPost($event)->assertOk()->assertJsonPath('result.status', 'noop_idempotent');

    expect(DB::table('personnes')->count())->toBe(1)
        ->and(DB::table('abonnements')->count())->toBe(1)
        ->and(DB::table('activities')->count())->toBe(1);
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. Garde de désordre (et 12 : la preuve par la rougeur, jouée à la main)
// ─────────────────────────────────────────────────────────────────────────────

test('L4-C 3 — un optout à T2 suivi d’un optin à T1 (rejoué par le backoff) laisse la personne désabonnée', function () {
    l4cPost(l4cEvent('newsletter_optout', '2026-09-02T12:00:00Z', ['payload' => ['reason' => 'unsubscribe-link']]))->assertOk();
    l4cPost(l4cOptinActuel(['occurred_at' => '2026-09-01T10:00:00Z']))->assertOk();

    expect(l4cStatut())->toBe('desabonne');
    // L'optin plus ancien est CONSIGNÉ dans la timeline, il ne change rien.
    expect(DB::table('activities')->where('kind', 'newsletter_optin')->where('subject_type', 'personne')->count())->toBe(1);
});

test('L4-C 3 bis — TÉMOIN : dans l’ordre, optin puis optout désabonne (la garde ne bloque pas tout)', function () {
    l4cPost(l4cOptinActuel(['occurred_at' => '2026-09-01T10:00:00Z']))->assertOk();
    expect(l4cStatut())->toBe('abonne');

    l4cPost(l4cEvent('newsletter_optout', '2026-09-02T12:00:00Z'))->assertOk();
    expect(l4cStatut())->toBe('desabonne');
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. D1 corrigé : se désabonner de la lettre ne ferme plus le CRM
// ─────────────────────────────────────────────────────────────────────────────

test('L4-C 4 — un optout lettre suivi d’un form_submission avec SIREN crée bien l’entreprise, PAS opted_out', function () {
    l4cPost(l4cEvent('newsletter_optout', '2026-09-02T12:00:00Z'))->assertOk();

    expect(DB::table('opt_out')->pluck('scope')->all())->toBe(['lettre']);

    l4cPost(l4cEvent('form_submission', '2026-09-03T09:00:00Z', [
        'form_type' => 'audit',
        'subject_ref' => 'site:submission:' . Str::uuid(),
        'person' => ['first_name' => 'Jean', 'last_name' => 'ZZ TEST'],
        'company' => ['siren' => '900000401', 'name' => 'ZZ TEST SAS'],
        'consent' => ['version' => 'v1-2026-05-24', 'at' => '2026-09-03T09:00:00Z', 'text_ref' => 'unified-contact-form'],
    ]))->assertOk()->assertJsonPath('result.status', 'created');

    expect(DB::table('companies')->count())->toBe(1)
        ->and(DB::table('contacts')->count())->toBe(1);
});

// ─────────────────────────────────────────────────────────────────────────────
// 5. Opposition générale : elle bloque, et elle désabonne
// ─────────────────────────────────────────────────────────────────────────────

test('L4-C 5 — une opposition business suivie d’un newsletter_optin donne opted_out, et l’abonnement existant passe en desabonne', function () {
    l4cPost(l4cOptinActuel(['occurred_at' => '2026-09-01T10:00:00Z']))->assertOk();
    expect(l4cStatut())->toBe('abonne');

    // Opposition générale (art. 21) venue du site.
    l4cPost(l4cEvent('opt_out', '2026-09-02T10:00:00Z', ['subject_ref' => 'site:opt_out:l4c']))->assertOk();
    expect(l4cStatut())->toBe('desabonne');

    l4cPost(l4cOptinActuel(['occurred_at' => '2026-09-05T10:00:00Z', 'consent' => ['at' => '2026-09-05T10:00:00Z']]))
        ->assertOk()
        ->assertJsonPath('result.status', 'opted_out');

    expect(l4cStatut())->toBe('desabonne');
});

// ─────────────────────────────────────────────────────────────────────────────
// 6. Réinscription après désabonnement
// ─────────────────────────────────────────────────────────────────────────────

test('L4-C 6 — un optout lettre suivi d’un NOUVEL optin (consentement postérieur) réabonne', function () {
    l4cPost(l4cOptinActuel(['occurred_at' => '2026-09-01T10:00:00Z']))->assertOk();
    l4cPost(l4cEvent('newsletter_optout', '2026-09-02T12:00:00Z'))->assertOk();
    expect(l4cStatut())->toBe('desabonne');

    l4cPost(l4cOptinActuel([
        'occurred_at' => '2026-09-10T08:00:00Z',
        'consent' => ['at' => '2026-09-10T08:00:00Z'],
    ]))->assertOk()->assertJsonPath('result.status', 'updated');

    expect(l4cStatut())->toBe('abonne');
    expect(DB::table('personnes')->count())->toBe(1);
});

// ─────────────────────────────────────────────────────────────────────────────
// 7. Le guide : une personne, jamais un abonné
// ─────────────────────────────────────────────────────────────────────────────

test('L4-C 7 — lead_magnet_requested crée la personne SANS abonnement, en intérêt légitime B2B', function () {
    l4cPost(l4cEvent('lead_magnet_requested', '2026-09-24T10:00:00Z', [
        'subject_ref' => 'site:guide_request:l4c',
        'source_slug' => 'guide-ia',
        'payload' => ['aimant' => 'guide-ia-entreprise', 'edition' => '2026-09', 'placement' => 'guide-ia-haut', 'locale' => 'fr', 'verifie' => true],
    ]))->assertOk()->assertJsonPath('result.status', 'created');

    $p = DB::table('personnes')->first();
    expect($p->premiere_source)->toBe('guide-ia')
        ->and($p->legal_basis)->toBe('legitimate_interest_b2b')
        ->and(DB::table('abonnements')->count())->toBe(0)
        ->and(DB::table('activities')->where('kind', 'lead_magnet_requested')->where('subject_type', 'personne')->count())->toBe(1);
});

test('L4-C — un demandeur du guide qui s’abonne ensuite passe en consentement, et ne redescend jamais', function () {
    l4cPost(l4cEvent('lead_magnet_requested', '2026-09-24T10:00:00Z', ['subject_ref' => 'site:guide_request:l4c']))->assertOk();
    l4cPost(l4cOptinActuel(['occurred_at' => '2026-09-24T11:00:00Z']))->assertOk();
    l4cPost(l4cEvent('lead_magnet_requested', '2026-09-25T10:00:00Z', ['subject_ref' => 'site:guide_request:l4c-2']))->assertOk();

    expect(DB::table('personnes')->value('legal_basis'))->toBe('consent')
        ->and(DB::table('personnes')->value('premiere_source'))->toBe('guide-ia');
});

test('L4-C — une adresse de messagerie grand public est classée « perso »', function () {
    // L'adresse est COMPOSÉE à l'exécution depuis la liste fermée : aucune
    // adresse d'un domaine réel n'est écrite dans ce dépôt public.
    $adresse = 'zz.l4c@' . NatureEmail::DOMAINES_GRAND_PUBLIC[0];
    l4cPost(l4cOptinActuel([], $adresse))->assertOk();

    expect(DB::table('personnes')->value('email_nature'))->toBe('perso');
});

// ─────────────────────────────────────────────────────────────────────────────
// 8. Rattachement automatique, dans les deux ordres
// ─────────────────────────────────────────────────────────────────────────────

test('L4-C 8 — un form_submission avec SIREN et la même person_key remplit personnes.contact_id et company_id', function () {
    l4cPost(l4cOptinActuel())->assertOk();

    l4cPost(l4cEvent('form_submission', '2026-09-03T09:00:00Z', [
        'form_type' => 'audit',
        'subject_ref' => 'site:submission:' . Str::uuid(),
        'person' => ['first_name' => 'Jean', 'last_name' => 'ZZ TEST'],
        'company' => ['siren' => '900000402', 'name' => 'ZZ TEST SAS'],
    ]))->assertOk();

    $p = DB::table('personnes')->first();
    $contact = DB::table('contacts')->first();
    expect($p->contact_id)->toBe($contact->id)
        ->and($p->company_id)->toBe($contact->company_id)
        ->and($p->rattachee_at)->not->toBeNull();
});

test('L4-C 8 bis — ordre inverse : la personne qui arrive APRÈS sa fiche contact y est rattachée dès sa création', function () {
    l4cPost(l4cEvent('form_submission', '2026-09-03T09:00:00Z', [
        'form_type' => 'audit',
        'subject_ref' => 'site:submission:' . Str::uuid(),
        'person' => ['first_name' => 'Jean', 'last_name' => 'ZZ TEST'],
        'company' => ['siren' => '900000403', 'name' => 'ZZ TEST SAS'],
    ]))->assertOk();

    l4cPost(l4cOptinActuel())->assertOk();

    expect(DB::table('personnes')->value('contact_id'))->toBe(DB::table('contacts')->value('id'));
});

// ─────────────────────────────────────────────────────────────────────────────
// 9. Effacement RGPD
// ─────────────────────────────────────────────────────────────────────────────

test('L4-C 9 — l’effacement RGPD vide personnes, abonnements et activities, et garde l’empreinte en opposition', function () {
    l4cPost(l4cOptinActuel(['occurred_at' => '2026-09-01T10:00:00Z']))->assertOk();
    l4cPost(l4cEvent('newsletter_optout', '2026-09-02T12:00:00Z'))->assertOk();

    $export = app(SiteGdprService::class)->export(l4cKey(), L4C_EMAIL);
    expect($export['business']['personnes'])->toHaveCount(1)
        ->and($export['business']['abonnements'])->toHaveCount(1);

    $result = app(SiteGdprService::class)->erase(l4cKey(), L4C_EMAIL);

    expect($result['deleted']['business']['personnes'])->toBe(1)
        ->and(DB::table('personnes')->count())->toBe(0)
        ->and(DB::table('abonnements')->count())->toBe(0)
        ->and(DB::table('activities')->where('person_key', l4cKey())->count())->toBe(0);

    $portees = DB::table('opt_out')->where('email_hash', hash('sha256', L4C_EMAIL))->pluck('scope')->sort()->values()->all();
    expect($portees)->toContain('business')->toContain('lettre');
    expect(DB::table('opt_out')->whereNotNull('email')->count())->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// 10. Drapeau fermé : le comportement d'avant, au bit près
// ─────────────────────────────────────────────────────────────────────────────

test('L4-C 10 — drapeau FERMÉ : newsletter_optin garde son chemin historique (pending_match), rien dans personnes', function () {
    config(['crm.ingest.personnes_enabled' => false]);

    l4cPost(l4cOptinActuel())->assertOk()->assertJsonPath('result.status', 'pending_match');

    expect(DB::table('personnes')->count())->toBe(0)
        ->and(l4cPendingMatch())->toBe(1);
});

test('L4-C 10 bis — drapeau FERMÉ : newsletter_optout écrit toujours l’opposition business d’avant', function () {
    config(['crm.ingest.personnes_enabled' => false]);

    l4cPost(l4cEvent('newsletter_optout', '2026-09-02T12:00:00Z'))->assertOk();

    expect(DB::table('opt_out')->pluck('scope')->all())->toBe(['business'])
        ->and(DB::table('personnes')->count())->toBe(0);
});

test('L4-C 10 ter — drapeau FERMÉ : les deux types nés avec ce lot sont refusés en 503, sans rien écrire', function () {
    config(['crm.ingest.personnes_enabled' => false]);

    foreach (['lead_magnet_requested', 'email_hard_bounced'] as $type) {
        l4cPost(l4cEvent($type, '2026-09-24T10:00:00Z'))
            ->assertStatus(503)
            ->assertJsonPath('error', 'personnes_ingest_disabled');
    }

    expect(DB::table('activities')->count())->toBe(0)
        ->and(DB::table('email_suppressions')->count())->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// Rebond dur
// ─────────────────────────────────────────────────────────────────────────────

test('L4-C — email_hard_bounced inscrit la suppression (empreinte seule) et ne crée JAMAIS de personne', function () {
    l4cPost(l4cEvent('email_hard_bounced', '2026-09-24T10:00:00Z', ['subject_ref' => 'site:email_log:l4c']))->assertOk();

    $s = DB::table('email_suppressions')->first();
    expect($s->reason)->toBe('hard_bounce')
        ->and($s->email)->toBeNull()
        ->and($s->email_hash)->toBe(hash('sha256', L4C_EMAIL))
        ->and(DB::table('personnes')->count())->toBe(0)
        ->and(l4cPendingMatch())->toBe(0);
});

test('L4-C — un rebond dur d’une personne connue s’inscrit dans SA timeline, et la sort de l’éligibilité', function () {
    l4cPost(l4cOptinActuel())->assertOk();
    l4cPost(l4cEvent('email_hard_bounced', '2026-09-24T10:00:00Z', ['subject_ref' => 'site:email_log:l4c']))->assertOk();

    expect(DB::table('activities')->where('kind', 'email_hard_bounced')->where('subject_type', 'personne')->count())->toBe(1);
    expect(Abonnements::eligiblesALaDiffusion($this->ws)->count())->toBe(0);
});

test('L4-C — éligibilité : un abonné confirmé l’est, un demandeur du guide sans la case ne l’est JAMAIS', function () {
    l4cPost(l4cOptinActuel())->assertOk();
    l4cPost(l4cEvent('lead_magnet_requested', '2026-09-24T10:00:00Z', ['subject_ref' => 'site:guide_request:l4c-b'], 'zz.guide@example.invalid'))->assertOk();

    $eligibles = Abonnements::eligiblesALaDiffusion($this->ws)->pluck('personnes.email')->all();

    expect($eligibles)->toBe([L4C_EMAIL]);
});

test('L4-C — aucune adresse n’atteint le journal pendant l’ingestion', function () {
    $lignes = [];
    Log::listen(function ($message) use (&$lignes): void {
        $lignes[] = json_encode([$message->message, $message->context]);
    });

    l4cPost(l4cOptinActuel())->assertOk();
    l4cPost(l4cEvent('newsletter_optout', '2026-09-02T12:00:00Z'))->assertOk();

    foreach ($lignes as $ligne) {
        expect(str_contains((string) $ligne, L4C_EMAIL))->toBeFalse();
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// Relectures de la PR (2026-09-24)
// ─────────────────────────────────────────────────────────────────────────────

test('L4-C relecture — une OPPOSITION GÉNÉRALE du site (site:email_opposition) reste une opposition business : 0 personne, et un formulaire suivant rend opted_out', function () {
    // `email/opposition.ts` : lien « ne plus recevoir de sollicitations » de
    // tous les e-mails, oppositions saisies en console. Même type d'événement
    // que la désinscription de la lettre, autre `subject_ref`.
    l4cPost(l4cEvent('newsletter_optout', '2026-09-02T12:00:00Z', [
        'subject_ref' => 'site:email_opposition:l4c',
        'payload' => ['reason' => 'lien-email'],
    ]))->assertOk();

    expect(DB::table('opt_out')->pluck('scope')->all())->toBe(['business'])
        ->and(DB::table('personnes')->count())->toBe(0);

    l4cPost(l4cEvent('form_submission', '2026-09-03T09:00:00Z', [
        'form_type' => 'audit',
        'subject_ref' => 'site:submission:' . Str::uuid(),
        'person' => ['first_name' => 'Jean', 'last_name' => 'ZZ TEST'],
        'company' => ['siren' => '900000404', 'name' => 'ZZ TEST SAS'],
    ]))->assertOk()->assertJsonPath('result.status', 'opted_out');

    expect(DB::table('contacts')->count())->toBe(0);
});

test('L4-C relecture — une opposition générale d’un ABONNÉ le désabonne de la lettre (chemin historique + désabonnement)', function () {
    l4cPost(l4cOptinActuel(['occurred_at' => '2026-09-01T10:00:00Z']))->assertOk();

    l4cPost(l4cEvent('newsletter_optout', '2026-09-02T12:00:00Z', [
        'subject_ref' => 'site:email_opposition:l4c-2',
        'payload' => ['reason' => 'console-admin'],
    ]))->assertOk();

    expect(l4cStatut())->toBe('desabonne')
        ->and(DB::table('opt_out')->pluck('scope')->all())->toBe(['business']);
});

test('L4-C relecture — drapeau FERMÉ : un formulaire avec SIREN et une opposition business n’émettent AUCUNE requête sur personnes ni abonnements', function () {
    // La fenêtre de déploiement : le nouveau code tourne AVANT `migrate`.
    config(['crm.ingest.personnes_enabled' => false]);

    $requetes = [];
    DB::listen(function ($q) use (&$requetes): void {
        $requetes[] = $q->sql;
    });

    l4cPost(l4cEvent('form_submission', '2026-09-03T09:00:00Z', [
        'form_type' => 'audit',
        'subject_ref' => 'site:submission:' . Str::uuid(),
        'person' => ['first_name' => 'Jean', 'last_name' => 'ZZ TEST'],
        'company' => ['siren' => '900000405', 'name' => 'ZZ TEST SAS'],
    ]))->assertOk()->assertJsonPath('result.status', 'created');
    l4cPost(l4cEvent('opt_out', '2026-09-04T10:00:00Z', ['subject_ref' => 'site:opt_out:l4c-ferme']))->assertOk();

    $touchees = array_filter($requetes, static fn (string $sql): bool => str_contains($sql, 'personnes') || str_contains($sql, 'abonnements'));

    expect($requetes)->not->toBeEmpty()
        ->and(array_values($touchees))->toBe([]);
});

test('L4-C relecture — base légale : format actuel → consent ; adresse pro inscrite à la demande du guide → intérêt légitime B2B, sans date de consentement inventée', function () {
    l4cPost(l4cOptinActuel())->assertOk();
    expect(DB::table('abonnements')->value('legal_basis'))->toBe('consent');

    l4cPost(l4cOptinActuel([
        'consent' => ['at' => null, 'version' => 'guide-mention-v1', 'text_ref' => 'guide-mention'],
        'payload' => ['base_legale' => 'legitimate_interest_b2b', 'email_nature' => 'pro'],
    ], 'zz.pro@example.invalid'))->assertOk()->assertJsonPath('result.status', 'created');

    $ab = DB::table('abonnements')
        ->join('personnes', 'personnes.id', '=', 'abonnements.personne_id')
        ->where('personnes.person_key', l4cKey('zz.pro@example.invalid'))
        ->first(['abonnements.legal_basis', 'abonnements.statut', 'abonnements.consent_at', 'personnes.legal_basis AS base_personne']);

    expect($ab->statut)->toBe('abonne')
        ->and($ab->legal_basis)->toBe('legitimate_interest_b2b')
        ->and($ab->consent_at)->toBeNull()
        ->and($ab->base_personne)->toBe('legitimate_interest_b2b');
});

test('L4-C relecture — une base légale hors liste fermée retombe sur consent', function () {
    l4cPost(l4cOptinActuel(['payload' => ['base_legale' => 'contract']]))->assertOk();

    expect(DB::table('abonnements')->value('legal_basis'))->toBe('consent');
});

test('L4-C relecture — un abonné par intérêt légitime qui coche ensuite la case passe en consentement, et ne redescend jamais', function () {
    $li = ['payload' => ['base_legale' => 'legitimate_interest_b2b']];
    l4cPost(l4cOptinActuel(['occurred_at' => '2026-09-01T10:00:00Z'] + $li))->assertOk();
    l4cPost(l4cOptinActuel(['occurred_at' => '2026-09-02T10:00:00Z', 'consent' => ['at' => '2026-09-02T10:00:00Z']]))->assertOk();
    l4cPost(l4cOptinActuel(['occurred_at' => '2026-09-03T10:00:00Z'] + $li))->assertOk();

    expect(DB::table('abonnements')->value('legal_basis'))->toBe('consent');
});

test('L4-C relecture — adresse PERSONNELLE en intérêt légitime : jamais inscrite (site ou CRM qui dit « perso » suffit)', function () {
    // Le site la déclare perso.
    l4cPost(l4cOptinActuel(['payload' => ['base_legale' => 'legitimate_interest_b2b', 'email_nature' => 'perso']]))
        ->assertOk();

    // Le site la déclare pro, mais la liste du CRM la reconnaît.
    $adresse = 'zz.l4c@' . NatureEmail::DOMAINES_GRAND_PUBLIC[0];
    l4cPost(l4cOptinActuel(['payload' => ['base_legale' => 'legitimate_interest_b2b', 'email_nature' => 'pro']], $adresse))
        ->assertOk();

    expect(DB::table('abonnements')->count())->toBe(0)
        ->and(DB::table('personnes')->count())->toBe(2)
        ->and(DB::table('personnes')->pluck('legal_basis')->unique()->values()->all())->toBe(['legitimate_interest_b2b'])
        ->and(DB::table('activities')->where('title', 'like', 'Inscription à la lettre refusée%')->count())->toBe(2)
        ->and(Abonnements::eligiblesALaDiffusion($this->ws)->count())->toBe(0);
});

test('L4-C relecture — l’intérêt légitime ne lève JAMAIS une désinscription de la lettre ; un consentement, si', function () {
    l4cPost(l4cOptinActuel(['occurred_at' => '2026-09-01T10:00:00Z']))->assertOk();
    l4cPost(l4cEvent('newsletter_optout', '2026-09-02T12:00:00Z'))->assertOk();

    l4cPost(l4cOptinActuel([
        'occurred_at' => '2026-09-10T08:00:00Z',
        'consent' => ['at' => null],
        'payload' => ['base_legale' => 'legitimate_interest_b2b'],
    ]))->assertOk()->assertJsonPath('result.status', 'opted_out');
    expect(l4cStatut())->toBe('desabonne');

    l4cPost(l4cOptinActuel(['occurred_at' => '2026-09-11T08:00:00Z', 'consent' => ['at' => '2026-09-11T08:00:00Z']]))->assertOk();
    expect(l4cStatut())->toBe('abonne');
});

test('L4-C relecture — un désabonnement reçu en premier crée la personne SANS adresse ni nom ; l’inscription plus ancienne ne l’y remet pas', function () {
    l4cPost(l4cEvent('newsletter_optout', '2026-09-02T12:00:00Z', [
        'person' => ['first_name' => 'Jean', 'last_name' => 'ZZ TEST'],
    ]))->assertOk();

    $p = DB::table('personnes')->first();
    expect($p->email)->toBeNull()
        ->and($p->first_name)->toBeNull()
        ->and($p->last_name)->toBeNull()
        ->and($p->email_nature)->toBe('inconnue')
        ->and($p->email_hash)->toBe(hash('sha256', L4C_EMAIL));

    // L'inscription de T1, rejouée par le backoff : consignée, rien d'autre.
    l4cPost(l4cOptinActuel(['occurred_at' => '2026-09-01T10:00:00Z']))->assertOk();

    expect(DB::table('personnes')->value('email'))->toBeNull()
        ->and(l4cStatut())->toBe('desabonne');

    // L'effacement la retrouve quand même, par l'empreinte.
    $bilan = app(GdprErasureService::class)->erase(L4C_EMAIL);
    expect($bilan['deleted']['personnes'])->toBe(1)
        ->and(DB::table('personnes')->count())->toBe(0);
});

test('L4-C relecture — la timeline ne recopie que les clés FERMÉES de payload', function () {
    l4cPost(l4cOptinActuel(['payload' => [
        'source' => 'guide-ia',
        'ip' => '192.0.2.10',
        'contact_secondaire' => 'zz.autre@example.invalid',
    ]]))->assertOk();

    $payload = json_decode((string) DB::table('activities')->value('payload'), true);

    expect($payload)->toHaveKey('source')
        ->not->toHaveKey('ip')
        ->not->toHaveKey('contact_secondaire');
});

test('L4-C relecture — NatureEmail couvre les FAMILLES de l’amendement sous toute extension nationale, sans avaler un vrai nom de domaine', function () {
    foreach (['hotmail.co.uk', 'yahoo.co.uk', 'outlook.be', 'gmx.de', 'live.com.au', 'yahoo.com.br', 'protonmail.ch'] as $domaine) {
        expect(NatureEmail::estGrandPublic($domaine))->toBeTrue("{$domaine} devrait être grand public");
    }
    foreach (['example.invalid', 'live.example.fr', 'outlookconseil.fr', 'yahoo.entreprise.example'] as $domaine) {
        expect(NatureEmail::estGrandPublic($domaine))->toBeFalse("{$domaine} ne devrait pas être grand public");
    }
    expect(NatureEmail::de(null))->toBe('inconnue');
});
