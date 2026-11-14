<?php

use App\Crm\Outbound\ConsentOutboundRecorder;
use App\Crm\Outbound\OppositionSortante;
use App\Crm\Rgpd\SiteGdprService;
use App\Envoi\Desinscription\InscriptionsEnvoi;
use App\Envoi\Desinscription\JetonDesinscription;
use App\Envoi\Imap\ClasseRetour;
use App\Envoi\Imap\RecepteurRetoursCrm;
use App\Envoi\Imap\RetourDetecte;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * STOP-T4-CRM (T4-a) — toute opposition NÉE AU CRM part au site, jamais l'inverse.
 * Fixtures FICTIVES (adresses @example.test), aucun envoi réel, drapeau sortant éteint par défaut.
 */
const T4_URL = 'https://site.test/api/internal/crm-webhook';

function t4Hash(string $email): string
{
    return ConsentOutboundRecorder::hashEmail($email);
}

function t4Evenements(?string $email = null, ?string $portee = null)
{
    return DB::table('crm_outbound_events')
        ->when($email !== null, fn ($q) => $q->where('email_hash', t4Hash($email)))
        ->when($portee !== null, fn ($q) => $q->where('scope', $portee));
}

function t4Allumer(): void
{
    config([
        'crm.outbound_enabled' => true,
        'crm.outbound.site_webhook_url' => T4_URL,
        'crm.ingest.hmac_secret' => 'secret-de-test-' . str_repeat('a1b2', 12),
    ]);
}

function t4Journaliste(string $workspaceId, ?string $email, ?int $contactId = null): int
{
    return (int) DB::table('journalists')->insertGetId([
        'workspace_id' => $workspaceId, 'last_name' => 'Durand', 'first_name' => 'Camille', 'email' => $email,
        'contact_id' => $contactId, 'source' => 'ours', 'opt_out' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function t4Contact(string $workspaceId, string $email): int
{
    $company = DB::table('companies')->insertGetId([
        'workspace_id' => $workspaceId, 'denomination' => 'ZZ T4', 'siren' => (string) random_int(100000000, 999999999),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $workspaceId, 'company_id' => $company, 'first_name' => 'Jean', 'last_name' => 'Concerne',
        'email' => $email, 'person_key' => hash('sha256', $email), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function t4Demande(string $workspaceId, string $type, string $email, array $metadata = []): int
{
    return (int) DB::table('rgpd_requests')->insertGetId([
        'workspace_id' => $workspaceId, 'type' => $type, 'status' => 'pending', 'subject_email' => $email,
        'requested_at' => now(), 'metadata' => json_encode($metadata), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Pose une ligne d'opposition « comme si le site l'avait écrite ». */
function t4OptOut(string $email, string $scope, string $source): void
{
    DB::table('opt_out')->insert([
        'email' => null, 'email_hash' => t4Hash($email), 'scope' => $scope, 'source' => $source, 'created_at' => now(),
    ]);
}

function t4Fichier(array $lignes): string
{
    $f = tempnam(sys_get_temp_dir(), 't4-retours-');
    file_put_contents($f, implode("\n", array_map(fn ($l) => json_encode($l), $lignes)) . "\n");

    return $f;
}

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->workspace = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'axion-ia', 'name' => 'WS', 'settings' => []]);
    $this->utilisateur = User::create([
        'id' => (string) Str::uuid(), 'email' => Str::uuid() . '@example.test', 'name' => 'Console',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $this->workspace->id, 'first_login_completed_at' => now(),
    ]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->id);
    $this->utilisateur->assignRole('owner');
    config(['crm.ingest.business_workspace' => 'axion-ia']);
});

// ── 1. Un événement par opposition née au CRM ───────────────────────────────

test('1. C1 lien de désinscription (page puis un clic) : 1 ligne, portée du lien, motif desinscription_lien', function () {
    config(['envoi.cle_desinscription' => 'cle-de-test-seulement-0123456789', 'envoi.desinscription_validite_jours' => 30]);
    $ws = F::espace('zz-t4-lien');
    $ref = InscriptionsEnvoi::creer($ws, 'lien@example.test');
    $chemin = '/desinscription/' . JetonDesinscription::emettre($ws, $ref);

    $this->post($chemin)->assertOk();
    $this->post($chemin, ['List-Unsubscribe' => 'One-Click'])->assertOk();

    $lignes = t4Evenements('lien@example.test')->get();
    expect($lignes)->toHaveCount(1)
        ->and($lignes[0]->scope)->toBe('business')
        ->and($lignes[0]->origin)->toBe('crm')
        ->and(json_decode((string) $lignes[0]->payload, true)['motif'])->toBe('desinscription_lien');
});

test('2. C2 journaliste avec contact lié d\'adresse DIFFÉRENTE : 2 lignes business, une par empreinte', function () {
    $contact = t4Contact($this->workspace->id, 'contact-lie@example.test');
    $id = t4Journaliste($this->workspace->id, 'journaliste@example.test', $contact);
    $this->actingAs($this->utilisateur);

    $this->postJson("/api/v1/journalists/{$id}/opt-out")->assertOk();

    expect(DB::table('crm_outbound_events')->count())->toBe(2)
        ->and(t4Evenements('journaliste@example.test', 'business')->count())->toBe(1)
        ->and(t4Evenements('contact-lie@example.test', 'business')->count())->toBe(1)
        ->and(DB::table('crm_outbound_events')->where('origin', '!=', 'crm')->count())->toBe(0);
    $payload = json_decode((string) t4Evenements('journaliste@example.test')->value('payload'), true);
    expect($payload)->toMatchArray(['motif' => 'opposition_presse', 'canal' => 'console_presse']);
});

test('3. C3 effacement d\'un journaliste (D1) : consent_optout business, jamais erasure', function () {
    $id = t4Journaliste($this->workspace->id, 'efface@example.test');
    $this->actingAs($this->utilisateur);

    $this->deleteJson("/api/v1/journalists/{$id}")->assertNoContent();

    expect(t4Evenements('efface@example.test', 'business')->where('event_type', 'consent_optout')->count())->toBe(1)
        ->and(DB::table('crm_outbound_events')->where('event_type', 'erasure')->count())->toBe(0)
        ->and(json_decode((string) t4Evenements('efface@example.test')->value('payload'), true)['motif'])->toBe('effacement_presse');
});

test('4. C4 art. 21 traité en console : 2 lignes (business et vivier)', function () {
    $demande = t4Demande($this->workspace->id, 'opposition', 'art21@example.test');
    $this->actingAs($this->utilisateur);

    $this->postJson("/api/v1/rgpd/requests/{$demande}/process")->assertOk();

    expect(t4Evenements('art21@example.test')->pluck('scope')->sort()->values()->all())->toBe(['business', 'vivier'])
        ->and(json_decode((string) t4Evenements('art21@example.test')->value('payload'), true)['motif'])->toBe('rgpd_art21');
});

test('5. C5 art. 17 console : 2 lignes, personne mise à l\'écart, rien supprimé', function () {
    $contact = t4Contact($this->workspace->id, 'art17@example.test');
    $demande = t4Demande($this->workspace->id, 'erasure', 'art17@example.test');
    $this->actingAs($this->utilisateur);
    $avant = DB::table('contacts')->count();

    $this->postJson("/api/v1/rgpd/requests/{$demande}/process")->assertOk();

    expect(t4Evenements('art17@example.test')->pluck('scope')->sort()->values()->all())->toBe(['business', 'vivier'])
        ->and(t4Evenements('art17@example.test')->where('event_type', 'erasure')->count())->toBe(0)
        ->and(DB::table('contacts')->count())->toBe($avant) // rien n'est effacé
        ->and(DB::table('contacts')->where('id', $contact)->value('deleted_at'))->not->toBeNull() // mise à l'écart
        ->and(DB::table('opt_out')->where('email_hash', t4Hash('art17@example.test'))->pluck('source')->unique()->all())->toBe(['gdpr_erasure']);
});

test('6. C6 retours de campagne : désinscription et plainte = 1 ligne chacune ; rebond dur et --dry-run = 0', function () {
    $f = t4Fichier([
        ['type' => 'desinscription', 'email' => 'desinscrit@example.test', 'campagne' => 'c1'],
        ['type' => 'plainte', 'email' => 'plaint@example.test', 'campagne' => 'c1'],
        ['type' => 'rebond_dur', 'email' => 'rebond@example.test', 'campagne' => 'c1'],
    ]);
    $this->artisan('crm:campagne:retours', ['file' => $f, '--dry-run' => true])->assertExitCode(0);
    expect(DB::table('crm_outbound_events')->count())->toBe(0);

    $this->artisan('crm:campagne:retours', ['file' => $f])->assertExitCode(0);

    expect(t4Evenements('desinscrit@example.test', 'business')->count())->toBe(1)
        ->and(t4Evenements('plaint@example.test', 'business')->count())->toBe(1)
        ->and(t4Evenements('rebond@example.test')->count())->toBe(0)
        ->and(json_decode((string) t4Evenements('plaint@example.test')->value('payload'), true)['motif'])->toBe('retour_campagne_plainte');
    @unlink($f);
});

test('7. C7 relève IMAP d\'un STOP (récepteur réel) : 1 ligne business', function () {
    (new RecepteurRetoursCrm)->recevoir(
        (string) $this->workspace->id,
        new RetourDetecte(ClasseRetour::Desinscription, 'inj-1', 'stop@example.test', null, 'cle-imap-1'),
    );

    expect(t4Evenements('stop@example.test', 'business')->count())->toBe(1)
        ->and(DB::table('opt_out')->where('email_hash', t4Hash('stop@example.test'))->value('source'))->toBe('campagne:releve-imap');
});

test('8. atomicité : panne de l\'outbox pendant C2 → opposition validée, 0 ligne ; le rattrapage la reprend', function () {
    $id = t4Journaliste($this->workspace->id, 'panne@example.test');
    $this->actingAs($this->utilisateur);
    $panne = true;
    DB::listen(function (QueryExecuted $q) use (&$panne): void {
        if ($panne && str_contains($q->sql, 'insert into "crm_outbound_events"')) {
            throw new RuntimeException('panne forcée de l\'outbox');
        }
    });
    Log::spy();

    $this->postJson("/api/v1/journalists/{$id}/opt-out")->assertOk();

    expect(DB::table('journalists')->where('id', $id)->value('opt_out'))->toBeTrue()
        ->and(DB::table('opt_out')->where('email_hash', t4Hash('panne@example.test'))->count())->toBe(1)
        ->and(DB::table('crm_outbound_events')->count())->toBe(0);
    Log::shouldHaveReceived('error')->with('crm.outbound.record_failed', Mockery::on(fn ($c) => ! str_contains(json_encode($c), 'panne@example.test')));

    $panne = false;
    $this->artisan('crm:outbound-rattraper', ['--depuis' => '2000-01-01', '--appliquer' => true])->assertExitCode(0);
    expect(t4Evenements('panne@example.test', 'business')->count())->toBe(1);
});

// ── Aucun événement pour une origine site ───────────────────────────────────

test('9. ingestion site (newsletter_optout) et SiteGdprService::erase : 0 ligne', function () {
    config([
        'crm.ingest.enabled' => true, 'crm.ingest.candidates_enabled' => false,
        'crm.ingest.hmac_secret' => 'secret-de-test-' . str_repeat('a1b2', 12), 'crm.ingest.max_clock_skew_seconds' => 300,
    ]);
    $corps = json_encode([
        'schema_version' => 1, 'event_id' => (string) Str::uuid(), 'event_type' => 'newsletter_optout',
        'occurred_at' => '2026-08-14T09:30:00+02:00', 'subject_ref' => 'site:submission:' . Str::uuid(),
        'person' => ['person_key' => hash('sha256', 'site@example.test'), 'email' => 'site@example.test'],
        'consent' => ['version' => 'v1-2026-05-24', 'at' => '2026-08-14T09:29:00+02:00', 'text_ref' => 'unified-contact-form'],
        'tags' => [], 'payload' => ['page' => '/fr/contact'],
    ], JSON_THROW_ON_ERROR);
    $ts = (string) time();
    $this->call('POST', '/api/internal/site-sync', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SITE_TIMESTAMP' => $ts,
        'HTTP_X_SITE_SIGNATURE' => hash_hmac('sha256', $ts . '.' . $corps, 'secret-de-test-' . str_repeat('a1b2', 12)),
    ], $corps)->assertOk();
    app(SiteGdprService::class)->erase('pk-' . Str::random(8), 'site-gdpr@example.test', 'business');

    expect(DB::table('opt_out')->where('email_hash', t4Hash('site@example.test'))->value('source'))->toStartWith('site-sync:')
        ->and(DB::table('crm_outbound_events')->count())->toBe(0);
});

test('10. opposition SITE puis opposition console du même journaliste : 0 ligne', function () {
    t4OptOut('deja-site@example.test', 'business', 'site-sync:newsletter_optout');
    $id = t4Journaliste($this->workspace->id, 'deja-site@example.test');
    $this->actingAs($this->utilisateur);

    $this->postJson("/api/v1/journalists/{$id}/opt-out")->assertOk();

    expect(DB::table('journalists')->where('id', $id)->value('opt_out'))->toBeTrue()
        ->and(DB::table('crm_outbound_events')->count())->toBe(0);
});

test('11. demande RGPD créée par le site puis traitée en console (art. 17 et art. 21) : 0 ligne, source demande_site', function () {
    $erasure = t4Demande($this->workspace->id, 'erasure', 'demande-site@example.test', ['origin' => 'site-sync-gdpr']);
    $opposition = t4Demande($this->workspace->id, 'opposition', 'demande-site-21@example.test', ['origin' => 'site-sync-gdpr']);
    $this->actingAs($this->utilisateur);

    $this->postJson("/api/v1/rgpd/requests/{$erasure}/process")->assertOk();
    $this->postJson("/api/v1/rgpd/requests/{$opposition}/process")->assertOk();

    expect(DB::table('crm_outbound_events')->count())->toBe(0)
        ->and(DB::table('opt_out')->where('email_hash', t4Hash('demande-site@example.test'))->pluck('source')->unique()->all())->toBe(['gdpr_erasure_demande_site'])
        ->and(DB::table('opt_out')->where('email_hash', t4Hash('demande-site-21@example.test'))->pluck('source')->unique()->all())->toBe(['gdpr_erasure_demande_site']);

    // Même après coup, le rattrapage ne les reprend pas.
    $this->artisan('crm:outbound-rattraper', ['--depuis' => '2000-01-01', '--appliquer' => true])->assertExitCode(0);
    expect(DB::table('crm_outbound_events')->count())->toBe(0);
});

test('12. rattrapage : sources site, gdpr_erasure_bisystem et inconnues ne sont jamais reprises', function () {
    t4OptOut('s1@example.test', 'business', 'site-sync:accords');
    t4OptOut('s2@example.test', 'vivier', 'gdpr_erasure_bisystem');
    t4OptOut('s3@example.test', 'business', 'inconnue');
    t4OptOut('crm@example.test', 'business', 'campagne:x');

    $this->artisan('crm:outbound-rattraper', ['--depuis' => '2000-01-01', '--appliquer' => true])->assertExitCode(0);

    expect(DB::table('crm_outbound_events')->count())->toBe(1)
        ->and(t4Evenements('crm@example.test', 'business')->count())->toBe(1)
        ->and(json_decode((string) t4Evenements('crm@example.test')->value('payload'), true)['motif'])->toBe('rattrapage');
});

test('13. garde de structure : ni l\'ingestion du site ni SiteGdprService ne nomment l\'émission', function () {
    $fichiers = array_merge(glob(app_path('Crm/Ingest/*.php')) ?: [], [app_path('Crm/Rgpd/SiteGdprService.php')]);
    expect($fichiers)->not->toBeEmpty();
    foreach ($fichiers as $f) {
        $code = (string) file_get_contents($f);
        expect($code)->not->toContain('OppositionSortante')->and($code)->not->toContain('ConsentOutboundRecorder');
    }
});

// ── Rejeu = une seule ligne ─────────────────────────────────────────────────

test('14. même opposition cliquée deux fois, puis après passage sent : toujours 1 ligne', function () {
    $id = t4Journaliste($this->workspace->id, 'deux-clics@example.test');
    $this->actingAs($this->utilisateur);

    $this->postJson("/api/v1/journalists/{$id}/opt-out")->assertOk();
    $this->postJson("/api/v1/journalists/{$id}/opt-out")->assertOk();
    expect(t4Evenements('deux-clics@example.test')->count())->toBe(1);

    t4Evenements('deux-clics@example.test')->update(['status' => 'sent', 'sent_at' => now()]);
    $this->postJson("/api/v1/journalists/{$id}/opt-out")->assertOk();

    expect(t4Evenements('deux-clics@example.test')->count())->toBe(1);
});

test('15. presse puis art. 21 sur la même adresse : 1 ligne business (+1 vivier)', function () {
    $id = t4Journaliste($this->workspace->id, 'meme@example.test');
    $demande = t4Demande($this->workspace->id, 'opposition', 'meme@example.test');
    $this->actingAs($this->utilisateur);

    $this->postJson("/api/v1/journalists/{$id}/opt-out")->assertOk();
    $this->postJson("/api/v1/rgpd/requests/{$demande}/process")->assertOk();

    expect(t4Evenements('meme@example.test', 'business')->count())->toBe(1)
        ->and(t4Evenements('meme@example.test', 'vivier')->count())->toBe(1);
});

test('16. même fichier de retours rejoué : aucune ligne de plus', function () {
    $f = t4Fichier([['type' => 'desinscription', 'email' => 'rejeu@example.test', 'campagne' => 'c2']]);

    $this->artisan('crm:campagne:retours', ['file' => $f])->assertExitCode(0);
    $this->artisan('crm:campagne:retours', ['file' => $f])->assertExitCode(0);

    expect(t4Evenements('rejeu@example.test')->count())->toBe(1);
    @unlink($f);
});

test('17. deux signaler simultanés : le verrou consultatif est pris et sérialise les connexions', function () {
    t4OptOut('verrou@example.test', 'business', 'campagne:x');
    $sql = [];
    DB::listen(function (QueryExecuted $q) use (&$sql): void {
        $sql[] = $q->sql;
    });
    $sortante = new OppositionSortante;

    $premier = $sortante->signaler(t4Hash('verrou@example.test'), 'business', 'rattrapage', 'rattrapage');
    $second = $sortante->signaler(t4Hash('verrou@example.test'), 'business', 'rattrapage', 'rattrapage');

    expect($premier)->not->toBeNull()->and($second)->toBeNull()
        ->and(collect($sql)->contains(fn ($s) => str_contains($s, 'pg_advisory_xact_lock')))->toBeTrue();
    // La transaction du test détient le verrou : une AUTRE connexion ne peut pas le prendre.
    $cle = 'crm_outbound|consent_optout|business|' . t4Hash('verrou@example.test');
    $autre = DB::connection('pgsql_owner')->selectOne('select pg_try_advisory_xact_lock(hashtextextended(?, 0)) as pris', [$cle]);
    expect((bool) $autre->pris)->toBeFalse();
    expect(t4Evenements('verrou@example.test', 'business')->count())->toBe(1);
});

test('18. rattrapage lancé deux fois : la seconde rend 0 à rattraper ; sans --appliquer il ne compte que', function () {
    t4OptOut('rattrape@example.test', 'business', 'gdpr_art21_console');

    $this->artisan('crm:outbound-rattraper', ['--depuis' => '2000-01-01'])->assertExitCode(0);
    expect(DB::table('crm_outbound_events')->count())->toBe(0);

    $this->artisan('crm:outbound-rattraper', ['--depuis' => '2000-01-01', '--appliquer' => true])->assertExitCode(0);
    expect(DB::table('crm_outbound_events')->count())->toBe(1);

    $this->artisan('crm:outbound-rattraper', ['--depuis' => '2000-01-01', '--appliquer' => true])
        ->expectsTable(['compteur', 'nombre'], [
            ['a_rattraper', 0], ['rattrapes', 0], ['ignores_site', 0], ['ignores_deja_en_file', 1], ['ignores_abandonnes', 0], ['erreurs', 0],
        ])->assertExitCode(0);
    expect(DB::table('crm_outbound_events')->count())->toBe(1);
});

test('18 bis. rattrapage : --depuis est obligatoire ; un refus 422 n\'est jamais repris', function () {
    $this->artisan('crm:outbound-rattraper')->assertExitCode(1);

    t4OptOut('refuse@example.test', 'business', 'campagne:x');
    DB::table('crm_outbound_events')->insert([
        'event_id' => (string) Str::uuid(), 'event_type' => 'consent_optout', 'email_hash' => t4Hash('refuse@example.test'),
        'scope' => 'business', 'origin' => 'crm', 'payload' => '{}', 'status' => 'gave_up', 'attempts' => 1,
        'last_error' => 'HTTP 422 (refus définitif du site) : x', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->artisan('crm:outbound-rattraper', ['--depuis' => '2000-01-01', '--appliquer' => true, '--inclure-abandonnes' => true])->assertExitCode(0);
    expect(DB::table('crm_outbound_events')->count())->toBe(1);
});

// ── Une opposition n'est jamais effacée et gagne sur un accord ──────────────

test('19. après C2 puis sent, une inscription du site pour la même empreinte ne lève rien', function () {
    config([
        'crm.ingest.enabled' => true, 'crm.ingest.candidates_enabled' => false,
        'crm.ingest.hmac_secret' => 'secret-de-test-' . str_repeat('a1b2', 12), 'crm.ingest.max_clock_skew_seconds' => 300,
    ]);
    $id = t4Journaliste($this->workspace->id, 'zz.test@example.invalid');
    $this->actingAs($this->utilisateur);
    $this->postJson("/api/v1/journalists/{$id}/opt-out")->assertOk();
    t4Evenements('zz.test@example.invalid')->update(['status' => 'sent', 'sent_at' => now()]);
    $optOutAvant = DB::table('opt_out')->orderBy('id')->get()->all();

    $corps = json_encode([
        'schema_version' => 1, 'event_id' => (string) Str::uuid(), 'event_type' => 'form_submission', 'form_type' => 'audit',
        'occurred_at' => '2026-08-14T09:30:00+02:00', 'subject_ref' => 'site:submission:' . Str::uuid(),
        'person' => ['person_key' => hash('sha256', 'zz.test@example.invalid'), 'email' => 'ZZ.Test@Example.Invalid', 'first_name' => 'Jean', 'last_name' => 'ZZ TEST', 'phone' => '+33600000000'],
        'company' => ['siren' => '900000101', 'name' => 'ZZ TEST SAS', 'postcode' => '38000', 'city' => 'Grenoble'],
        'consent' => ['version' => 'v1-2026-05-24', 'at' => '2026-08-14T09:29:00+02:00', 'text_ref' => 'unified-contact-form'],
        'tags' => [], 'payload' => ['page' => '/fr/contact'],
    ], JSON_THROW_ON_ERROR);
    $ts = (string) time();
    $this->call('POST', '/api/internal/site-sync', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SITE_TIMESTAMP' => $ts,
        'HTTP_X_SITE_SIGNATURE' => hash_hmac('sha256', $ts . '.' . $corps, 'secret-de-test-' . str_repeat('a1b2', 12)),
    ], $corps);

    expect(DB::table('opt_out')->orderBy('id')->get()->all())->toEqual($optOutAvant)
        ->and(t4Evenements('zz.test@example.invalid')->count())->toBe(1)
        ->and(t4Evenements('zz.test@example.invalid')->value('status'))->toBe('sent')
        ->and(DB::table('crm_outbound_events')->where('event_type', '!=', 'consent_optout')->count())->toBe(0);
});

test('20. aucun chemin (flush 2xx, 422, gave_up, rattrapage) ne supprime d\'opt_out ni d\'événement', function () {
    t4Allumer();
    foreach (['a', 'b', 'c'] as $n) {
        t4OptOut("{$n}@example.test", 'business', 'campagne:x');
    }
    $this->artisan('crm:outbound-rattraper', ['--depuis' => '2000-01-01', '--appliquer' => true])->assertExitCode(0);
    $avant = [DB::table('opt_out')->count(), DB::table('crm_outbound_events')->count()];

    foreach ([200, 422, 500] as $statut) {
        Http::fake([T4_URL => Http::response(['ok' => true], $statut)]);
        DB::table('crm_outbound_events')->whereIn('status', ['failed'])->update(['next_attempt_at' => now()->subMinute()]);
        $this->artisan('crm:flush-outbound')->assertExitCode(0);
    }
    $this->artisan('crm:outbound-rattraper', ['--depuis' => '2000-01-01', '--appliquer' => true])->assertExitCode(0);

    expect([DB::table('opt_out')->count(), DB::table('crm_outbound_events')->count()])->toBe($avant);
});

// ── Panne du tiers et désordre ──────────────────────────────────────────────

test('21. coupure réseau : attempts inchangé, next_attempt_at repoussé', function () {
    t4Allumer();
    t4OptOut('reseau@example.test', 'business', 'campagne:x');
    (new OppositionSortante)->signaler(t4Hash('reseau@example.test'), 'business', 'rattrapage', 'rattrapage');
    Http::fake([T4_URL => fn () => throw new ConnectionException('coupure')]);

    $this->artisan('crm:flush-outbound')->assertExitCode(0);

    $ligne = t4Evenements('reseau@example.test')->first();
    expect((int) $ligne->attempts)->toBe(0)->and(strtotime((string) $ligne->next_attempt_at))->toBeGreaterThan(time());
});

test('22. 401 (D3) : report SANS essai consommé + alerte critique à chaque passage', function () {
    t4Allumer();
    t4OptOut('signature@example.test', 'business', 'campagne:x');
    (new OppositionSortante)->signaler(t4Hash('signature@example.test'), 'business', 'rattrapage', 'rattrapage');
    Http::fake([T4_URL => Http::response('unauthorized', 401)]);
    Log::spy();

    $this->artisan('crm:flush-outbound')->assertExitCode(0);

    $ligne = t4Evenements('signature@example.test')->first();
    expect((int) $ligne->attempts)->toBe(0)->and($ligne->status)->toBe('pending')
        ->and(strtotime((string) $ligne->next_attempt_at))->toBeGreaterThan(time());
    Log::shouldHaveReceived('critical')->with('crm.outbound.signature_refusee', Mockery::any())->once();
});

test('24. occurred_at émis = created_at ; deux portées d\'une même empreinte partent indépendamment', function () {
    t4Allumer();
    Carbon::setTestNow(Carbon::parse('2026-11-14 10:00:00', 'UTC'));
    $demande = t4Demande($this->workspace->id, 'opposition', 'portees@example.test');
    $this->actingAs($this->utilisateur);
    $this->postJson("/api/v1/rgpd/requests/{$demande}/process")->assertOk();
    $createdAt = (string) t4Evenements('portees@example.test', 'business')->value('created_at');
    t4Evenements('portees@example.test', 'vivier')->update(['next_attempt_at' => now()->addHour()]); // seule « business » est due
    Http::fake([T4_URL => Http::response(['ok' => true, 'outcome' => 'applied'], 200)]);

    $this->artisan('crm:flush-outbound')->assertExitCode(0);

    expect(t4Evenements('portees@example.test', 'business')->value('status'))->toBe('sent')
        ->and(t4Evenements('portees@example.test', 'vivier')->value('status'))->toBe('pending');
    Http::assertSent(fn ($r) => json_decode($r->body(), true)['occurred_at'] === Carbon::parse($createdAt)->toIso8601String());
    Carbon::setTestNow();
});

// ── Décision D4 et validations de la classe ─────────────────────────────────

test('D4. une plainte ZeptoMail n\'est jamais émise vers le site', function () {
    $jeton = 'jeton-de-test-' . str_repeat('ab12', 8); // factice, construit à l'exécution
    config(['mail.webhooks.zeptomail_token' => $jeton]);
    $corps = ['event_name' => ['complaint'], 'event_message' => [['event_data' => [[
        'event_name' => 'complaint', 'details' => [['bounced_recipient' => 'plainte-zepto@example.test']],
    ]]]]];

    $this->postJson('/api/internal/email/zeptomail?t=' . $jeton, $corps)
        ->assertOk()->assertJsonPath('counts.complaint', 1);

    expect(DB::table('crm_outbound_events')->count())->toBe(0);
});

test('signaler refuse une empreinte, une portée ou un motif invalides ; la portée lettre n\'est jamais émise', function () {
    $s = new OppositionSortante;
    $h = t4Hash('x@example.test');

    expect(fn () => $s->signaler('ABC', 'business', 'rattrapage', 'c'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $s->signaler($h, 'lettre', 'rattrapage', 'c'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $s->signaler($h, 'business', 'motif-libre', 'c'))->toThrow(InvalidArgumentException::class)
        ->and(DB::table('crm_outbound_events')->count())->toBe(0);
});

test('un corps d\'événement ne porte jamais d\'adresse ni de payload local', function () {
    t4Allumer();
    $id = t4Journaliste($this->workspace->id, 'prive@example.test');
    $this->actingAs($this->utilisateur);
    $this->postJson("/api/v1/journalists/{$id}/opt-out")->assertOk();
    Http::fake([T4_URL => Http::response(['ok' => true], 200)]);

    $this->artisan('crm:flush-outbound')->assertExitCode(0);

    Http::assertSent(fn ($r) => ! str_contains($r->body(), 'prive@example.test') && ! str_contains($r->body(), 'motif'));
});
