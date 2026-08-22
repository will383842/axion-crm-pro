<?php

use App\Crm\Ingest\SiteSyncEvent;
use App\Crm\Taxonomy;
use Database\Seeders\GovernedTagsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * INGESTION SITE → CRM (lot L2) — `POST /api/internal/site-sync`.
 *
 * Ces tests attaquent l'endpoint par le RÉSEAU (signature comprise), pas le
 * service en direct : c'est la seule façon de prouver que l'authentification,
 * les drapeaux, le contrat d'entrée et l'ingestion sont réellement enchaînés
 * dans cet ordre.
 *
 * Trois familles de garanties :
 *   - INERTIE : drapeaux à OFF, rien ne s'écrit (le lot est fusionnable) ;
 *   - IDEMPOTENCE / DÉDUPLICATION : rejouer ou recroiser ne duplique jamais ;
 *   - ÉTANCHÉITÉ + RGPD : la garde candidats et l'anti-réinsertion.
 */
// Secret FACTICE construit à l'exécution : un littéral hex de 64 caractères,
// même bidon, a l'entropie d'un vrai secret et fait rougir le scan Gitleaks
// (vécu : fingerprint generic-api-key sur ce fichier au premier commit).
define('SITE_SYNC_SECRET', 'secret-de-test-' . str_repeat('a1b2', 12));

const SITE_SYNC_URL = '/api/internal/site-sync';

beforeEach(function () {
    config([
        'crm.ingest.enabled' => true,
        'crm.ingest.candidates_enabled' => false,
        'crm.ingest.hmac_secret' => SITE_SYNC_SECRET,
        'crm.ingest.business_workspace' => 'axion-ia',
        'crm.ingest.max_clock_skew_seconds' => 300,
    ]);

    siteSyncBusinessWorkspaceId();
});

function siteSyncBusinessWorkspaceId(): string
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

function siteSyncVivierWorkspaceId(): string
{
    return (string) DB::table('workspaces')
        ->where('slug', Taxonomy::VIVIER_WORKSPACE_SLUG)
        ->value('id');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function siteSyncEvent(array $overrides = []): array
{
    return array_replace_recursive([
        'schema_version' => 1,
        'event_id' => (string) Str::uuid(),
        'event_type' => 'form_submission',
        'form_type' => 'audit',
        'occurred_at' => '2026-08-14T09:30:00+02:00',
        'subject_ref' => 'site:submission:' . Str::uuid(),
        'person' => [
            'person_key' => hash('sha256', 'zz.test@example.invalid'),
            'email' => 'ZZ.Test@Example.Invalid',
            'first_name' => 'Jean',
            'last_name' => 'ZZ TEST',
            'phone' => '+33600000000',
        ],
        'company' => [
            'siren' => '900000101',
            'name' => 'ZZ TEST SAS',
            'postcode' => '38000',
            'city' => 'Grenoble',
        ],
        'consent' => [
            'version' => 'v1-2026-05-24',
            'at' => '2026-08-14T09:29:00+02:00',
            'text_ref' => 'unified-contact-form',
        ],
        'tags' => [],
        'payload' => ['page' => '/fr/contact'],
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $event
 */
function siteSyncPost(array $event, ?string $secret = null, ?string $timestamp = null): TestResponse
{
    $body = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $timestamp ??= (string) time();
    $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret ?? SITE_SYNC_SECRET);

    return test()->call(
        'POST',
        SITE_SYNC_URL,
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SITE_TIMESTAMP' => $timestamp,
            'HTTP_X_SITE_SIGNATURE' => $signature,
        ],
        $body,
    );
}

function siteSyncNothingWritten(): void
{
    expect(DB::table('companies')->count())->toBe(0)
        ->and(DB::table('contacts')->count())->toBe(0)
        ->and(DB::table('candidates')->count())->toBe(0)
        ->and(DB::table('activities')->count())->toBe(0);
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. INERTIE — drapeaux à OFF, le lot ne change RIEN
// ─────────────────────────────────────────────────────────────────────────────

test('drapeau maître à OFF : 503 et aucune écriture', function () {
    config(['crm.ingest.enabled' => false]);

    $response = siteSyncPost(siteSyncEvent());

    $response->assertStatus(503)->assertJsonPath('error', 'ingest_disabled');
    siteSyncNothingWritten();
});

test('drapeau candidats à OFF : le flux vivier reste fermé (503), rien n’est écrit', function () {
    $response = siteSyncPost(siteSyncEvent([
        'event_type' => 'application_submitted',
        'form_type' => null,
        'consent' => ['version' => 'careers-v2-2026-08-13', 'vivier_at' => '2026-08-14T09:29:00+02:00'],
        'candidate' => ['family' => 'candidat_commercial', 'offer_slug' => 'monteur-video'],
    ]));

    $response->assertStatus(503)->assertJsonPath('error', 'candidates_ingest_disabled');
    siteSyncNothingWritten();
});

test('valeurs par défaut des drapeaux : tout est fermé', function () {
    // On relit la configuration LIVRÉE, pas celle du beforeEach.
    //
    // 🔴 `config/crm.php` appelle `env()`. Sur un poste de développement dont le
    // `.env` porte `CRM_INGEST_ENABLED=true` — c'est ce que le §A.1 des
    // scénarios E2E fait poser — la variable arrive dans le conteneur par
    // `env_file:` et ce test répondait selon LE POSTE, pas selon le code livré.
    // Il pouvait donc rougir sur un code sain, ou verdir sur un code fautif.
    // On retire les deux clés de l'environnement le temps de la lecture : ce
    // qu'on veut mesurer, c'est le DÉFAUT écrit dans `config/crm.php`.
    // Il faut vider LES TROIS emplacements : le dépôt de variables de Laravel
    // interroge `$_SERVER` en premier, et `Env::getRepository()->clear()` seul
    // n'y touche pas (même cause que `tests/bootstrap.php`).
    $cles = ['CRM_INGEST_ENABLED', 'CRM_INGEST_CANDIDATES_ENABLED'];
    $avant = [];
    foreach ($cles as $cle) {
        $avant[$cle] = [$_SERVER[$cle] ?? null, $_ENV[$cle] ?? null, getenv($cle)];
        unset($_SERVER[$cle], $_ENV[$cle]);
        putenv($cle);
    }

    try {
        $defaults = require config_path('crm.php');

        expect($defaults['ingest']['enabled'])->toBeFalse()
            ->and($defaults['ingest']['candidates_enabled'])->toBeFalse();
    } finally {
        foreach ($cles as $cle) {
            [$serveur, $env, $getenv] = $avant[$cle];
            if ($serveur !== null) {
                $_SERVER[$cle] = $serveur;
            }
            if ($env !== null) {
                $_ENV[$cle] = $env;
            }
            if ($getenv !== false) {
                putenv("{$cle}={$getenv}");
            }
        }
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. AUTHENTIFICATION
// ─────────────────────────────────────────────────────────────────────────────

test('signature invalide : 401 et aucune écriture', function () {
    $response = siteSyncPost(siteSyncEvent(), secret: 'mauvais-secret');

    $response->assertStatus(401)->assertJsonPath('error', 'bad_signature');
    siteSyncNothingWritten();
});

test('signature valide mais horodatage périmé : 401 (anti-rejeu)', function () {
    $response = siteSyncPost(siteSyncEvent(), timestamp: (string) (time() - 3600));

    $response->assertStatus(401)->assertJsonPath('error', 'stale_signature');
    siteSyncNothingWritten();
});

test('secret absent côté CRM : aucune requête ne passe', function () {
    config(['crm.ingest.hmac_secret' => '']);

    siteSyncPost(siteSyncEvent(), secret: '')->assertStatus(401);
    siteSyncNothingWritten();
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. CONTRAT D'ENTRÉE STRICT
// ─────────────────────────────────────────────────────────────────────────────

test('un champ inconnu fait REJETER le message (schéma strict)', function () {
    $response = siteSyncPost(siteSyncEvent(['relation_type' => 'client']));

    $response->assertStatus(422)->assertJsonPath('error', 'unknown_field');
    siteSyncNothingWritten();
});

test('le site ne peut pas choisir le type de relation ni le workspace', function () {
    // Deux tentatives d'escalade : imposer un type de relation, imposer le
    // workspace de destination. Les deux sont hors contrat, donc refusées.
    siteSyncPost(siteSyncEvent(['workspace' => 'vivier-candidats']))->assertStatus(422);
    siteSyncPost(siteSyncEvent(['person' => ['relation_type' => 'client']]))->assertStatus(422);
    siteSyncNothingWritten();
});

test('type d’événement, type de formulaire et person_key sont contrôlés', function () {
    siteSyncPost(siteSyncEvent(['event_type' => 'peu_importe']))
        ->assertStatus(422)->assertJsonPath('error', 'unknown_event_type');

    siteSyncPost(siteSyncEvent(['form_type' => 'inconnu']))
        ->assertStatus(422)->assertJsonPath('error', 'unknown_form_type');

    siteSyncPost(siteSyncEvent(['person' => ['person_key' => 'trop-court']]))
        ->assertStatus(422)->assertJsonPath('error', 'invalid_person_key');

    siteSyncPost(siteSyncEvent(['company' => ['siren' => '12345']]))
        ->assertStatus(422)->assertJsonPath('error', 'invalid_siren');

    siteSyncNothingWritten();
});

test('un tag hors namespace gouverné est refusé', function () {
    siteSyncPost(siteSyncEvent(['tags' => ['promo-ete']]))
        ->assertStatus(422)->assertJsonPath('error', 'invalid_tag');

    siteSyncPost(siteSyncEvent(['tags' => ['marketing:promo']]))
        ->assertStatus(422)->assertJsonPath('error', 'ungoverned_tag_namespace');

    siteSyncNothingWritten();
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. CLASSEMENT AUTOMATIQUE À LA SOURCE
// ─────────────────────────────────────────────────────────────────────────────

test('une demande d’audit crée une fiche classée, taguée et horodatée', function () {
    $response = siteSyncPost(siteSyncEvent());

    $response->assertOk()->assertJsonPath('result.status', 'created');

    $company = DB::table('companies')->where('siren', '900000101')->first();
    expect($company)->not->toBeNull()
        ->and($company->relation_type)->toBe('prospect')
        ->and($company->lifecycle_stage)->toBe('opportunite')
        ->and($company->legal_basis)->toBe('precontractual')
        ->and($company->discovery_source)->toBe('site');

    $contact = DB::table('contacts')->first();
    expect($contact->person_key)->toBe(hash('sha256', 'zz.test@example.invalid'))
        // L'email est NORMALISÉ à l'écriture (le site ne le faisait nulle part).
        ->and(mb_strtolower((string) $contact->email))->toBe('zz.test@example.invalid')
        ->and($contact->legal_basis)->toBe('precontractual');

    $tags = DB::table('company_tag')
        ->join('tags', 'tags.id', '=', 'company_tag.tag_id')
        ->pluck('tags.slug')
        ->all();
    expect($tags)->toContain('src:site-formulaire-audit')
        ->and($tags)->toContain('svc:audit');

    $activity = DB::table('activities')->first();
    expect($activity->kind)->toBe('form_submission')
        ->and($activity->external_ref)->toStartWith('site:event:')
        ->and($activity->person_key)->toBe(hash('sha256', 'zz.test@example.invalid'));
});

test('une inscription newsletter porte la base légale CONSENTEMENT', function () {
    siteSyncPost(siteSyncEvent([
        'event_type' => 'newsletter_optin',
        'form_type' => null,
        'consent' => ['version' => 'newsletter-v1-2026-08-13'],
    ]))->assertOk();

    $company = DB::table('companies')->where('siren', '900000101')->first();
    expect($company->relation_type)->toBe('newsletter')
        ->and($company->legal_basis)->toBe('consent')
        ->and($company->consent_version)->toBe('newsletter-v1-2026-08-13');
});

test('le cycle de vie ne RECULE jamais', function () {
    $ws = siteSyncBusinessWorkspaceId();
    DB::table('companies')->insert([
        'workspace_id' => $ws,
        'siren' => '900000101',
        'denomination' => 'ZZ TEST SAS',
        'signals' => '{}',
        'metadata' => '{}',
        'quality_score' => 0,
        'relation_type' => 'client',
        'lifecycle_stage' => 'client',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    siteSyncPost(siteSyncEvent(['form_type' => 'autre']))->assertOk();

    $company = DB::table('companies')->where('siren', '900000101')->first();
    expect($company->relation_type)->toBe('client')
        ->and($company->lifecycle_stage)->toBe('client');
});

test('un lead SANS SIREN ne crée aucune fiche : il part en arbitrage', function () {
    $response = siteSyncPost(siteSyncEvent(['company' => ['siren' => null]]));

    $response->assertOk()->assertJsonPath('result.status', 'pending_match');

    expect(DB::table('companies')->count())->toBe(0)
        ->and(DB::table('contacts')->count())->toBe(0)
        ->and(DB::table('activities')->count())->toBe(1);

    $payload = json_decode((string) DB::table('activities')->value('payload'), true);
    expect($payload['pending_match']['denomination'])->toBe('ZZ TEST SAS');
});

// ─────────────────────────────────────────────────────────────────────────────
// 4bis. FRONTIÈRE ENTRE LES DEUX DÉPÔTS
//
// Les deux tests qui suivent sont les seuls garde-fous d'un contrat qui vit
// dans DEUX dépôts : aucun compilateur, aucun type ne relie
// `SiteSyncEvent::FORM_TYPES` à `CrmFormType` du site. Ils ont été écrits après
// avoir trouvé, à la relecture croisée, deux divergences RÉELLES :
//   · `simulateur_roi` était émis par le site et absent d'ici → 422, donc
//     `gave_up` dans l'outbox : AUCUN lead du simulateur ne serait jamais
//     arrivé ;
//   · quatre tags `src:` émis par le classifieur manquaient au référentiel
//     gouverné → la fiche était créée mais perdait sa PROVENANCE, sans le
//     moindre signal (`resolveTagId()` renvoie null et passe au suivant).
// ─────────────────────────────────────────────────────────────────────────────

test('chaque type de formulaire accepté a un tag de provenance GOUVERNÉ', function () {
    $referential = GovernedTagsSeeder::referential();
    $orphelins = [];

    foreach (SiteSyncEvent::FORM_TYPES as $formType) {
        // Exactement l'expression du classifieur quand le site n'envoie pas de
        // `source_slug` — le cas du formulaire unifié, du podcast et du ROI.
        $slug = 'src:site-formulaire-' . str_replace('_', '-', $formType);

        if (! array_key_exists($slug, $referential)) {
            $orphelins[] = $formType . ' → ' . $slug;
        }
    }

    expect($orphelins)->toBe([]);
});

test('les slugs de provenance envoyés par le site sont tous gouvernés', function () {
    // Liste PINNÉE des `source_slug` que le site émet explicitement. Source :
    // axion-ia `src/features/{job-application,commercial-application,
    // podcast-request}/actions.ts`, `src/server/calendly/discover.ts`,
    // `src/server/chatbot/tools/capturer-lead.ts`. Ajouter un `sourceSlug`
    // côté site sans l'ajouter ici ET au référentiel fait rougir ce test.
    $emisParLeSite = [
        'calendly',
        'chatbot',
        'site-formulaire-podcast',
        'site-candidature-offre',
        'site-candidature-commerciale',
    ];

    $referential = GovernedTagsSeeder::referential();
    $orphelins = [];

    foreach ($emisParLeSite as $sourceSlug) {
        if (! array_key_exists('src:' . $sourceSlug, $referential)) {
            $orphelins[] = 'src:' . $sourceSlug;
        }
    }

    expect($orphelins)->toBe([]);
});

test('un lead du simulateur de gains est accepté et porte sa provenance', function () {
    // Régression directe : ce type était refusé 422 « unknown_form_type ».
    siteSyncPost(siteSyncEvent(['form_type' => 'simulateur_roi']))
        ->assertOk()
        ->assertJsonPath('result.status', 'created');

    $tags = DB::table('company_tag')
        ->join('tags', 'tags.id', '=', 'company_tag.tag_id')
        ->pluck('tags.slug')
        ->all();

    expect($tags)->toContain('src:site-formulaire-simulateur-roi');
});

// ─────────────────────────────────────────────────────────────────────────────
// 5. IDEMPOTENCE ET DÉDUPLICATION
// ─────────────────────────────────────────────────────────────────────────────

test('rejouer le MÊME événement ne crée pas de doublon', function () {
    $event = siteSyncEvent();

    siteSyncPost($event)->assertOk()->assertJsonPath('result.status', 'created');
    siteSyncPost($event)->assertOk()->assertJsonPath('result.status', 'noop_idempotent');

    expect(DB::table('companies')->count())->toBe(1)
        ->and(DB::table('contacts')->count())->toBe(1)
        ->and(DB::table('activities')->count())->toBe(1);
});

test('deux événements distincts de la même personne alimentent UNE fiche', function () {
    siteSyncPost(siteSyncEvent())->assertOk();
    siteSyncPost(siteSyncEvent([
        'event_type' => 'calendly_booked',
        'form_type' => null,
        'subject_ref' => 'site:calendly_event:' . Str::uuid(),
    ]))->assertOk()->assertJsonPath('result.status', 'updated');

    expect(DB::table('companies')->count())->toBe(1)
        ->and(DB::table('contacts')->count())->toBe(1)
        ->and(DB::table('activities')->count())->toBe(2);
});

test('fiche SCRAPÉE puis lead ENTRANT : fusion, pas de doublon, le déclaré gagne', function () {
    $ws = siteSyncBusinessWorkspaceId();

    // Une fiche telle que la produit le pipeline de collecte : froide, base
    // légale intérêt légitime, aucun person_key, un téléphone approximatif.
    $companyId = DB::table('companies')->insertGetId([
        'workspace_id' => $ws,
        'siren' => '900000101',
        'denomination' => 'ZZ TEST',
        'city' => 'Lyon',
        'signals' => '{}',
        'metadata' => '{}',
        'quality_score' => 0,
        'relation_type' => 'prospect',
        'lifecycle_stage' => 'nouveau',
        'legal_basis' => 'legitimate_interest_b2b',
        'discovery_source' => 'insee',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('contacts')->insert([
        'workspace_id' => $ws,
        'company_id' => $companyId,
        'first_name' => 'J',
        'last_name' => 'ZZ TEST',
        'email' => 'zz.test@example.invalid',
        'phone' => '0400000000',
        'discovery_source' => 'mentions-legales',
        'sources' => '["mentions-legales"]',
        'metadata' => '{}',
        'legal_basis' => 'legitimate_interest_b2b',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $tagId = DB::table('tags')->insertGetId([
        'workspace_id' => $ws,
        'slug' => 'src:scraping-insee',
        'name' => 'Collecte — INSEE',
        'category' => 'intent',
        'kind' => 'auto',
        'rules' => '{}',
        'is_locked' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('company_tag')->insert([
        'company_id' => $companyId,
        'tag_id' => $tagId,
        'workspace_id' => $ws,
        'assigned_by' => 'auto-rule',
        'assigned_at' => now(),
    ]);

    siteSyncPost(siteSyncEvent())->assertOk()->assertJsonPath('result.status', 'updated');

    expect(DB::table('companies')->count())->toBe(1)
        ->and(DB::table('contacts')->count())->toBe(1);

    $company = DB::table('companies')->first();
    expect($company->lifecycle_stage)->toBe('opportunite')
        ->and($company->legal_basis)->toBe('precontractual')
        // Le déclaré écrase le collecté (et l'origine est tracée).
        ->and($company->city)->toBe('Grenoble')
        ->and($company->discovery_source)->toBe('insee');

    $origins = json_decode((string) $company->field_origins, true);
    expect($origins['city'])->toBe('declared');

    $contact = DB::table('contacts')->first();
    expect($contact->person_key)->toBe(hash('sha256', 'zz.test@example.invalid'))
        ->and($contact->phone)->toBe('+33600000000')
        ->and($contact->first_name)->toBe('Jean');

    // Les provenances se CUMULENT : la fiche garde son tag de collecte.
    $tags = DB::table('company_tag')
        ->join('tags', 'tags.id', '=', 'company_tag.tag_id')
        ->pluck('tags.slug')
        ->all();
    expect($tags)->toContain('src:scraping-insee')
        ->and($tags)->toContain('src:site-formulaire-audit');
});

// ─────────────────────────────────────────────────────────────────────────────
// 6. GARDE CANDIDATS — la règle la plus dure du lot
// ─────────────────────────────────────────────────────────────────────────────

test('une candidature SANS consentement v2 est REJETÉE', function () {
    config(['crm.ingest.candidates_enabled' => true]);

    $response = siteSyncPost(siteSyncEvent([
        'event_type' => 'application_submitted',
        'form_type' => null,
        'consent' => ['version' => 'careers-v1-2026-06-09'],
        'candidate' => ['family' => 'candidat_commercial'],
    ]));

    $response->assertStatus(422)->assertJsonPath('error', 'candidate_consent_v2_required');

    expect(DB::table('candidates')->count())->toBe(0)
        ->and(DB::table('activities')->count())->toBe(0);
});

test('une candidature sans AUCUN consentement est REJETÉE', function () {
    config(['crm.ingest.candidates_enabled' => true]);

    siteSyncPost(siteSyncEvent([
        'event_type' => 'application_submitted',
        'form_type' => null,
        'consent' => [],
        'candidate' => ['family' => 'candidat_video'],
    ]))->assertStatus(422)->assertJsonPath('error', 'candidate_consent_v2_required');

    expect(DB::table('candidates')->count())->toBe(0);
});

test('une candidature v2 entre dans le VIVIER, jamais dans la base commerciale', function () {
    config(['crm.ingest.candidates_enabled' => true]);

    siteSyncPost(siteSyncEvent([
        'event_type' => 'application_submitted',
        'form_type' => null,
        'consent' => [
            'version' => 'memo-v2-2026-08-13',
            'at' => '2026-08-14T09:29:00+02:00',
            'vivier_at' => '2026-08-14T09:29:00+02:00',
        ],
        'candidate' => [
            'family' => 'candidat_commercial',
            'offer_slug' => 'commercial-memo',
            'attributes' => ['b2b' => '3-5'],
            'experiences' => [['poste' => 'Commercial']],
            'cv_ref' => 'site:cv:42',
        ],
    ]))->assertOk()->assertJsonPath('result.subject_type', 'candidate');

    $candidate = DB::table('candidates')->first();
    expect($candidate)->not->toBeNull()
        ->and($candidate->workspace_id)->toBe(siteSyncVivierWorkspaceId())
        ->and($candidate->relation_type)->toBe('candidat_commercial')
        ->and($candidate->lifecycle_stage)->toBe('nouveau')
        ->and($candidate->legal_basis)->toBe('consent')
        ->and($candidate->consent_version)->toBe('memo-v2-2026-08-13')
        ->and($candidate->cv_ref)->toBe('site:cv:42');

    // ÉTANCHÉITÉ : rien n'a touché l'univers business.
    expect(DB::table('companies')->count())->toBe(0)
        ->and(DB::table('contacts')->count())->toBe(0)
        ->and(DB::table('activities')->where('workspace_id', siteSyncBusinessWorkspaceId())->count())->toBe(0)
        ->and(DB::table('activities')->where('workspace_id', siteSyncVivierWorkspaceId())->count())->toBe(1);

    $tags = DB::table('candidate_tag')
        ->join('tags', 'tags.id', '=', 'candidate_tag.tag_id')
        ->pluck('tags.slug')
        ->all();
    expect($tags)->toContain('cand-offre:commercial-memo');
});

test('rejouer une candidature ne crée pas un second candidat', function () {
    config(['crm.ingest.candidates_enabled' => true]);

    $event = siteSyncEvent([
        'event_type' => 'application_submitted',
        'form_type' => null,
        'consent' => ['version' => 'careers-v2-2026-08-13', 'vivier_at' => '2026-08-14T09:29:00+02:00'],
        'candidate' => ['family' => 'candidat_tech'],
    ]);

    siteSyncPost($event)->assertOk();
    siteSyncPost($event)->assertOk()->assertJsonPath('result.status', 'noop_idempotent');

    expect(DB::table('candidates')->count())->toBe(1);
});

test('integration du STOCK (information + 30 j sans opposition) entre au vivier avec sa version dédiée', function () {
    config(['crm.ingest.candidates_enabled' => true]);

    // C'est l'événement que le site émet au J+30 pour le stock d'avant-v2 :
    // version FERME `vivier-stock-2026-08-14`, jamais la v1 de la fiche.
    siteSyncPost(siteSyncEvent([
        'event_type' => 'application_submitted',
        'form_type' => null,
        'consent' => [
            'version' => 'vivier-stock-2026-08-14',
            'at' => '2026-08-14T09:29:00+02:00',
            'text_ref' => 'vivier-information-email',
            'vivier_at' => '2026-09-13T09:29:00+02:00',
        ],
        'candidate' => ['family' => 'candidat_commercial'],
    ]))->assertOk()->assertJsonPath('result.subject_type', 'candidate');

    $candidate = DB::table('candidates')->first();
    expect($candidate->consent_version)->toBe('vivier-stock-2026-08-14')
        ->and($candidate->legal_basis)->toBe('consent');

    // Et la v1 du stock reste REFUSÉE : la nouvelle version n'ouvre pas une
    // brèche générale, elle énumère UN acte juridique précis.
    siteSyncPost(siteSyncEvent([
        'event_type' => 'application_submitted',
        'form_type' => null,
        'consent' => ['version' => 'careers-v1-2026-06-09'],
        'candidate' => ['family' => 'candidat_commercial'],
        'person' => ['person_key' => hash('sha256', 'autre@example.invalid'), 'email' => 'autre@example.invalid'],
        'subject_ref' => 'site:job_application:autre-1',
    ]))->assertStatus(422)->assertJsonPath('error', 'candidate_consent_v2_required');
});

// ─────────────────────────────────────────────────────────────────────────────
// 7. OPPOSITION — anti-réinsertion, par univers
// ─────────────────────────────────────────────────────────────────────────────

test('une personne opposée n’est jamais réinsérée', function () {
    DB::table('opt_out')->insert([
        'email' => 'zz.test@example.invalid',
        'email_hash' => hash('sha256', 'zz.test@example.invalid'),
        'scope' => 'business',
        'source' => 'test',
        'created_at' => now(),
    ]);

    siteSyncPost(siteSyncEvent())->assertOk()->assertJsonPath('result.status', 'opted_out');

    siteSyncNothingWritten();
});

test('l’opposition d’un univers ne ferme pas l’autre', function () {
    config(['crm.ingest.candidates_enabled' => true]);

    // Opposée côté VIVIER seulement : la fiche business reste possible.
    DB::table('opt_out')->insert([
        'email' => 'zz.test@example.invalid',
        'email_hash' => hash('sha256', 'zz.test@example.invalid'),
        'scope' => 'vivier',
        'source' => 'test',
        'created_at' => now(),
    ]);

    siteSyncPost(siteSyncEvent())->assertOk()->assertJsonPath('result.status', 'created');

    siteSyncPost(siteSyncEvent([
        'event_type' => 'application_submitted',
        'form_type' => null,
        'consent' => ['version' => 'careers-v2-2026-08-13'],
        'candidate' => ['family' => 'candidat_autre'],
    ]))->assertOk()->assertJsonPath('result.status', 'opted_out');

    expect(DB::table('companies')->count())->toBe(1)
        ->and(DB::table('candidates')->count())->toBe(0);
});

test('une désinscription newsletter inscrit l’opposition côté business', function () {
    siteSyncPost(siteSyncEvent([
        'event_type' => 'newsletter_optout',
        'form_type' => null,
    ]))->assertOk();

    $optOut = DB::table('opt_out')->first();
    expect($optOut)->not->toBeNull()
        ->and($optOut->scope)->toBe('business')
        ->and($optOut->email_hash)->toBe(hash('sha256', 'zz.test@example.invalid'));

    expect(DB::table('activities')->where('kind', 'newsletter_optout')->count())->toBe(1);
});

// ─────────────────────────────────────────────────────────────────────────────
// 7 bis. OPPOSITION VIVIER — le clic d'un candidat, INGÉRÉ (et non pré-inséré)
//
// 🔴 Les trois tests ci-dessus n'exercent jamais ce chemin : celui qui vérifie
// l'étanchéité entre univers PRÉ-INSÈRE lui-même la ligne `scope = vivier`
// dont il a besoin. Aucun test ne demandait donc à l'ingestion de la PRODUIRE
// — et elle ne le faisait pas. Constaté en E2E le 2026-08-17.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * L'événement que `syncVivierOppositionToCrm()` émet côté site.
 *
 * Sans section `company` : le clic d'opposition d'un candidat ne porte aucune
 * donnée d'entreprise, et en laisser une ferait créer une fiche business par
 * l'événement même qui demande à être oublié.
 */
function siteSyncOppositionVivier(array $overrides = []): array
{
    $event = siteSyncEvent(array_replace_recursive([
        'event_type' => 'opt_out',
        'form_type' => null,
        'source_slug' => 'site-vivier-opposition',
        'subject_ref' => 'site:job_application:zz-opposition-1',
        'payload' => ['scope' => 'vivier'],
    ], $overrides));

    unset($event['company'], $event['consent']);

    return $event;
}

test('une opposition n’écrit JAMAIS l’adresse en clair', function () {
    // Plan §B.4 et §B.10, mot pour mot : « `email_hash` renseigné, email jamais
    // en clair ». L'adresse était conservée À CÔTÉ du hachage — qui ne
    // protégeait donc rien. Aucun lecteur ne s'en sert : les trois interrogent
    // `email_hash`.
    siteSyncPost(siteSyncEvent([
        'event_type' => 'newsletter_optout',
        'form_type' => null,
    ]))->assertOk();

    $ligne = DB::table('opt_out')->first();

    expect($ligne)->not->toBeNull()
        ->and($ligne->email)->toBeNull()
        ->and($ligne->email_hash)->toBe(hash('sha256', 'zz.test@example.invalid'));

    // Et la garde continue de voir l'opposition : c'est l'empreinte qu'elle lit.
    expect(DB::table('opt_out')->where('email_hash', hash('sha256', 'zz.test@example.invalid'))->exists())
        ->toBeTrue();
});

test('une opposition VIVIER est inscrite en scope vivier, pas business', function () {
    config(['crm.ingest.candidates_enabled' => true]);

    siteSyncPost(siteSyncOppositionVivier())->assertOk();

    $optOut = DB::table('opt_out')->first();
    expect($optOut)->not->toBeNull()
        // `SiteSyncClassifier::universe()` répond `business` pour un `opt_out`
        // (ce n'est ni une candidature ni un formulaire `recrutement`). Sans
        // lecture du scope déclaré, l'opposition d'un candidat atterrissait
        // dans la liste COMMERCIALE.
        ->and($optOut->scope)->toBe('vivier');

    expect(DB::table('opt_out')->where('scope', 'business')->count())->toBe(0);
});

test('une opposition VIVIER empêche le retour au vivier', function () {
    config(['crm.ingest.candidates_enabled' => true]);

    siteSyncPost(siteSyncOppositionVivier())->assertOk();

    // La même personne redépose une candidature : l'opposition prime.
    siteSyncPost(siteSyncEvent([
        'event_type' => 'application_submitted',
        'form_type' => null,
        'consent' => ['version' => 'careers-v2-2026-08-13'],
        'candidate' => ['family' => 'candidat_commercial'],
        'subject_ref' => 'site:job_application:zz-opposition-1-bis',
    ]))->assertOk()->assertJsonPath('result.status', 'opted_out');

    expect(DB::table('candidates')->count())->toBe(0);
});

test('une opposition VIVIER ne désinscrit pas des communications business', function () {
    config(['crm.ingest.candidates_enabled' => true]);

    siteSyncPost(siteSyncOppositionVivier())->assertOk();

    // Se retirer d'un vivier de recrutement n'est pas se désinscrire d'une
    // lettre d'information : les deux listes sont distinctes par construction.
    siteSyncPost(siteSyncEvent())->assertOk()->assertJsonPath('result.status', 'created');

    expect(DB::table('companies')->count())->toBe(1);
});

test('un opt_out ne peut pas se DÉCLARER vivier sans viser une candidature', function () {
    config(['crm.ingest.candidates_enabled' => true]);

    // Le payload confirme une déduction, il ne la choisit pas (plan §B.7.d) :
    // un `subject_ref` de newsletter reste une opposition commerciale, même si
    // l'émetteur écrit « vivier » dans le corps du message.
    siteSyncPost(siteSyncOppositionVivier([
        'subject_ref' => 'site:newsletter_subscriber:zz-1',
    ]))->assertOk();

    expect(DB::table('opt_out')->first()->scope)->toBe('business');
});

// ─────────────────────────────────────────────────────────────────────────────
// B13-005 — UN TAG DE PROVENANCE ÉCARTÉ EST DIT, PAS PERDU
// ─────────────────────────────────────────────────────────────────────────────

test('B13-005 — un tag de provenance hors référentiel est RENDU au site, et journalisé', function () {
    // 🔴 CE QUI SE PASSAIT, ET POURQUOI ÇA NE SE VOYAIT NULLE PART.
    //
    // `source_slug` est LIBRE côté site (aucune validation ne le ferme, cf.
    // `SiteSyncEvent::optionalString`), et le classifieur en fait
    // `src:<valeur>`. Si cette valeur n'est pas au référentiel gouverné,
    // `resolveTagId()` rend `null` et `attachTags()` faisait un `continue` nu :
    // l'événement repartait en **200**, `result.tags` amputé du tag de
    // provenance, sans un mot ni dans la réponse ni dans le journal.
    //
    // Les deux tests « les slugs de provenance envoyés par le site sont tous
    // gouvernés » (ci-dessus) gardent une LISTE PINNÉE : ils rougissent si l'on
    // oublie d'ajouter un slug connu. Ils ne peuvent RIEN voir d'une campagne
    // que le site inventera demain sans toucher à ce dépôt — et c'est
    // exactement le cas mesuré ici.
    Log::spy();

    $slug = 'zz-campagne-hors-referentiel';
    $reponse = siteSyncPost(siteSyncEvent(['source_slug' => $slug]));

    $reponse->assertOk()->assertJsonPath('result.status', 'created');

    $resultat = $reponse->json('result');

    // TÉMOIN — le tag n'est TOUJOURS PAS créé à la volée. Le correctif nomme la
    // perte, il n'ouvre pas le référentiel à l'ingestion : sans ce témoin, on
    // pourrait fermer le constat en fabriquant le tag, ce qui serait pire.
    expect(DB::table('tags')->where('slug', 'src:' . $slug)->exists())->toBeFalse(
        'Le tag inconnu a été CRÉÉ à la volée : une ingestion peut désormais polluer le référentiel '
        . 'gouverné. Ce n est pas le correctif attendu pour B13-005 — le tag reste écarté, il doit '
        . 'seulement être DIT.',
    );

    // ⚠️ `toContain()` est VARIADIQUE en Pest : y passer un message en second
    // argument en ferait une seconde AIGUILLE, et l'assertion mesurerait autre
    // chose que ce qu'elle annonce (piège déjà payé dans ce dépôt).
    expect(in_array('src:' . $slug, $resultat['tags'] ?? [], true))->toBeFalse(
        'Le tag écarté figure quand même dans `tags` : la réponse annonce un classement qui n existe pas.',
    );

    expect($resultat['tags_ignores'] ?? null)->toBe(
        ['src:' . $slug],
        "Le tag de provenance a été abandonné en SILENCE, avec une réponse 200 : le site ne peut ni "
        . "compter ses pertes ni les deviner (constat B13-005). Geste : collecter le slug écarté dans "
        . '`SiteSyncIngestService::attachTags()` au lieu du `continue` nu, et l exposer sous '
        . '`tags_ignores` dans `IngestOutcome::toArray()`.',
    );

    // L'autre moitié : l'exploitant qui cherche pourquoi un segment est vide
    // doit le trouver dans le journal, sans avoir à rejouer l'événement.
    Log::shouldHaveReceived('notice')
        ->withArgs(fn (string $message, array $contexte = []): bool => $message === 'crm.ingest.tag_ignore'
            && ($contexte['slug'] ?? null) === 'src:' . $slug)
        ->once();
});

test('B13-005 — quand rien n est écarté, `tags_ignores` est présent et VIDE', function () {
    // Un champ qui n'apparaît que les mauvais jours ne se surveille pas : le
    // site ne saurait pas distinguer « aucune perte » de « ancienne version du
    // CRM qui ne dit rien ». La clé est donc toujours là.
    $resultat = siteSyncPost(siteSyncEvent())->assertOk()->json('result');

    expect(array_key_exists('tags_ignores', $resultat))->toBeTrue(
        'La clé `tags_ignores` disparaît quand il n y a rien à signaler : le site ne peut plus '
        . 'distinguer « aucune perte » de « le CRM ne le dit pas » (constat B13-005).',
    );
    expect($resultat['tags_ignores'])->toBe(
        [],
        'Un événement nominal déclare des tags écartés : soit le référentiel a perdu une entrée, '
        . 'soit le collecteur d écartés compte à tort.',
    );
});
