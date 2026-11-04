<?php

use App\Console\Commands\CrmPersonnesDepuisDemandes;
use App\Crm\Console\FilesATraiter;
use App\Crm\Ingest\IngestOutcome;
use App\Crm\Ingest\PersonnesIngestService;
use App\Crm\Ingest\SiteSyncEvent;
use App\Crm\Ingest\SiteSyncIngestService;
use App\Crm\Personnes\Abonnements;
use App\Crm\Rgpd\EffacementCoordonneesFiches;
use App\Crm\Rgpd\SiteGdprService;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Audit\AuditHashChain;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * FICHE DEMANDE (lots C1, C2, C3 et rattrapage) : toute demande du site
 * (formulaire, rendez-vous) existe dans le CRM comme PERSONNE, même sans SIREN.
 *
 * Les événements passent par le VRAI service d'ingestion (même chemin que
 * `POST /api/internal/site-sync`), le rattachement par la VRAIE route de la
 * console. Adresses en `@example.invalid` (dépôt PUBLIC), jamais de donnée
 * réelle.
 */
beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);

    config([
        'crm.console_v2' => true,
        'crm.ingest.enabled' => true,
        'crm.ingest.candidates_enabled' => false,
        'crm.ingest.personnes_enabled' => false,
        'crm.ingest.demandes_personne_enabled' => true,
        'crm.ingest.business_workspace' => 'ws-pdd',
    ]);

    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'ws-pdd',
        'name' => 'Fiche demande',
        'settings' => [],
    ]);

    $user = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'pdd-operateur@example.invalid',
        'name' => 'Opérateur',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $this->workspace->id,
        'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($user->current_workspace_id);
    $user->assignRole('admin', 'owner');
    DB::table('user_workspaces')->insertOrIgnore([
        'user_id' => $user->id,
        'workspace_id' => $this->workspace->id,
        'role_slug' => 'owner',
        'invited_at' => now(),
        'joined_at' => now(),
    ]);

    $this->actingAs($user);
});

const PDD_EMAIL = 'zz.demande@example.invalid';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pddEvent(array $overrides = [], string $email = PDD_EMAIL): array
{
    return array_replace_recursive([
        'schema_version' => 1,
        'event_id' => (string) Str::uuid(),
        'event_type' => 'form_submission',
        'form_type' => 'audit',
        'occurred_at' => '2026-11-04T09:30:00+01:00',
        'subject_ref' => 'site:submission:' . Str::uuid(),
        'person' => [
            'person_key' => hash('sha256', 'pdd|' . $email),
            'email' => $email,
            'first_name' => 'Jeanne',
            'last_name' => 'ZZ DEMANDE',
            'phone' => '+33600000001',
        ],
        'company' => ['name' => 'ZZ SOCIETE DECLAREE'],
        'consent' => [
            'version' => 'v1-2026-05-24',
            'at' => '2026-11-04T09:29:00+01:00',
            'text_ref' => 'unified-contact-form',
        ],
        'tags' => [],
        'payload' => ['page' => '/fr/contact'],
    ], $overrides);
}

/** @param  array<string, mixed>  $event */
function pddIngerer(array $event): IngestOutcome
{
    return app(SiteSyncIngestService::class)->ingest(SiteSyncEvent::fromArray($event));
}

function pddPersonne(string $email = PDD_EMAIL): ?stdClass
{
    return DB::table('personnes')->where('email', $email)->first();
}

function pddEntreprise(string $workspaceId, string $siren = '900000501'): int
{
    return (int) DB::table('companies')->insertGetId([
        'workspace_id' => $workspaceId,
        'siren' => $siren,
        'denomination' => 'ZZ TEST ' . $siren,
        'discovery_source' => 'site',
        'quality_score' => 0,
        'signals' => '{}',
        'metadata' => '{}',
        'relation_type' => 'prospect',
        'lifecycle_stage' => 'nouveau',
        'legal_basis' => 'legitimate_interest_b2b',
        'field_origins' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// C1 : la demande sans SIREN fait une personne, et reste dans l'arbitrage
// ─────────────────────────────────────────────────────────────────────────────

test('formulaire sans SIREN : une personne avec téléphone et entreprise déclarée, et pending_match conservé', function () {
    $sortie = pddIngerer(pddEvent());

    // Le retour reste EXACTEMENT celui d'avant : aucun sujet.
    expect($sortie->status)->toBe('pending_match')
        ->and($sortie->subjectType)->toBeNull()
        ->and($sortie->subjectId)->toBeNull();

    $personne = pddPersonne();
    expect($personne)->not->toBeNull()
        ->and($personne->phone)->toBe('+33600000001')
        ->and($personne->entreprise_declaree)->toBe('ZZ SOCIETE DECLAREE')
        ->and($personne->first_name)->toBe('Jeanne')
        ->and($personne->legal_basis)->toBe('precontractual')
        ->and(json_decode($personne->field_origins, true))->toMatchArray(['phone' => 'declared', 'entreprise_declaree' => 'declared']);

    $activite = DB::table('activities')->where('id', $sortie->activityId)->first();
    expect($activite->subject_id)->toBeNull()
        ->and(json_decode($activite->payload, true))->toHaveKey('pending_match');
});

test('rien dans company_tag, companies ni contacts : un id de personne ne devient jamais un id d’entreprise', function () {
    pddIngerer(pddEvent());

    expect(DB::table('company_tag')->count())->toBe(0)
        ->and(DB::table('companies')->count())->toBe(0)
        ->and(DB::table('contacts')->count())->toBe(0);
});

test('la provenance n’est jamais « newsletter » : formulaire ou rendez-vous', function () {
    pddIngerer(pddEvent());
    pddIngerer(pddEvent(['event_type' => 'calendly_booked', 'form_type' => null, 'source_slug' => null, 'payload' => ['typeRendezVous' => 'diagnostic']], 'zz.rdv@example.invalid'));

    expect(pddPersonne()->premiere_source)->toBe('formulaire')
        ->and(pddPersonne('zz.rdv@example.invalid')->premiere_source)->toBe('rendez-vous')
        ->and(DB::table('personnes')->where('premiere_source', 'newsletter')->count())->toBe(0);
});

test('rejeu du même événement : une seule fiche ; nouvelle demande de la même personne : toujours une seule', function () {
    $event = pddEvent();
    pddIngerer($event);
    expect(pddIngerer($event)->status)->toBe('noop_idempotent');

    pddIngerer(pddEvent(['occurred_at' => '2026-11-05T10:00:00+01:00', 'person' => ['phone' => '+33600000002']]));

    expect(DB::table('personnes')->count())->toBe(1)
        ->and(pddPersonne()->phone)->toBe('+33600000002')
        ->and(DB::table('activities')->where('kind', 'form_submission')->count())->toBe(2);
});

test('une opposition générale déjà reçue : aucune fiche', function () {
    DB::table('opt_out')->insert([
        'email' => null,
        'email_hash' => hash('sha256', PDD_EMAIL),
        'scope' => 'business',
        'source' => 'test',
        'reason' => 'test',
        'created_at' => now(),
    ]);

    $sortie = pddIngerer(pddEvent());

    expect($sortie->status)->toBe('opted_out')
        ->and(DB::table('personnes')->count())->toBe(0);
});

test('une personne mise à l’écart n’est pas touchée par une nouvelle demande', function () {
    pddIngerer(pddEvent());
    DB::table('personnes')->update(['opposition_at' => now()]);

    pddIngerer(pddEvent(['person' => ['phone' => '+33600000099']]));

    expect(pddPersonne()->phone)->toBe('+33600000001');
});

test('drapeau fermé : aucune requête sur personnes et le comportement d’avant', function () {
    config(['crm.ingest.demandes_personne_enabled' => false]);

    DB::enableQueryLog();
    $sortie = pddIngerer(pddEvent());
    $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");
    DB::disableQueryLog();

    expect($sortie->status)->toBe('pending_match')
        ->and($sql)->not->toContain('personnes')
        ->and(DB::table('personnes')->count())->toBe(0);
});

test('jamais pour une opposition, un désabonnement ni un avis', function (string $type) {
    $overrides = ['event_type' => $type, 'form_type' => null, 'company' => ['name' => null]];
    if ($type === 'newsletter_optout') {
        $overrides['subject_ref'] = 'site:newsletter_subscriber:' . Str::uuid();
    }

    pddIngerer(pddEvent($overrides));

    expect(DB::table('personnes')->count())->toBe(0);
})->with(['opt_out', 'newsletter_optout', 'review_posted']);

test('jamais pour un échange apporteur, un recrutement ni un autre univers', function () {
    $service = app(PersonnesIngestService::class);

    $apporteur = SiteSyncEvent::fromArray(pddEvent([
        'event_type' => 'calendly_booked', 'form_type' => null,
        'payload' => ['eventTypeName' => 'Échange Apporteur d’affaires (15 min)'],
    ]));
    $recrutement = SiteSyncEvent::fromArray(pddEvent(['form_type' => 'recrutement']));
    $normal = SiteSyncEvent::fromArray(pddEvent());

    expect($service->concerneUneDemande($apporteur, 'business'))->toBeFalse()
        ->and($service->concerneUneDemande($recrutement, 'business'))->toBeFalse()
        ->and($service->concerneUneDemande($normal, 'vivier'))->toBeFalse()
        ->and($service->concerneUneDemande($normal, 'business'))->toBeTrue();

    // Et de bout en bout : l'échange apporteur reste en attente, sans personne.
    $sortie = pddIngerer(pddEvent([
        'event_type' => 'calendly_booked', 'form_type' => null,
        'payload' => ['eventTypeName' => 'Échange apporteur d’affaires (15 min)'],
    ], 'zz.apporteur@example.invalid'));
    expect($sortie->status)->toBe('pending_match')
        ->and(DB::table('personnes')->count())->toBe(0);
});

test('un candidat (univers vivier) est bien parcouru par l’ingestion et n’entre jamais dans personnes', function () {
    config(['crm.ingest.candidates_enabled' => true]);

    $sortie = pddIngerer(pddEvent([
        'form_type' => 'recrutement',
        'consent' => ['version' => 'careers-v2-2026-08-13', 'vivier_at' => '2026-11-04T09:29:00+01:00'],
    ], 'zz.candidat@example.invalid'));

    // Le chemin vivier a réellement tourné : une fiche candidat existe.
    expect($sortie->subjectType)->toBe('candidate')
        ->and(DB::table('candidates')->count())->toBe(1)
        ->and(DB::table('personnes')->count())->toBe(0);
});

test('partenariat, presse, speaker, investisseur, support_client : jamais une personne', function (string $type) {
    $sortie = pddIngerer(pddEvent(['form_type' => $type], 'zz.' . $type . '@example.invalid'));

    expect($sortie->status)->toBe('pending_match')
        ->and(DB::table('personnes')->count())->toBe(0);
})->with(['partenariat', 'presse', 'speaker', 'investisseur', 'support_client']);

test('un numéro déjà opposé n’est pas enregistré, la personne reste', function () {
    DB::table('opt_out')->insert([
        'email' => null,
        'email_hash' => null,
        'phone' => '0600000001',
        'scope' => 'business',
        'source' => 'test',
        'created_at' => now(),
    ]);

    // Même numéro, écriture internationale : les variantes comptent.
    pddIngerer(pddEvent());

    $personne = pddPersonne();
    expect($personne)->not->toBeNull()
        ->and($personne->phone)->toBeNull()
        ->and($personne->entreprise_declaree)->toBe('ZZ SOCIETE DECLAREE');
});

test('un nom déjà connu n’est jamais remplacé par celui d’un formulaire', function () {
    pddIngerer(pddEvent());
    pddIngerer(pddEvent([
        'occurred_at' => '2026-11-06T10:00:00+01:00',
        'person' => ['first_name' => 'Intrus', 'last_name' => 'ZZ AUTRE NOM'],
    ]));

    $personne = pddPersonne();
    expect($personne->first_name)->toBe('Jeanne')->and($personne->last_name)->toBe('ZZ DEMANDE');

    // Un nom absent, lui, est rempli.
    DB::table('personnes')->update(['first_name' => null]);
    pddIngerer(pddEvent([
        'occurred_at' => '2026-11-07T10:00:00+01:00',
        'person' => ['first_name' => 'Marie'],
    ]));
    expect(pddPersonne()->first_name)->toBe('Marie')->and(pddPersonne()->last_name)->toBe('ZZ DEMANDE');
});

test('valeur saisie à la main jamais écrasée ; valeur absente ou déclarée remplacée', function () {
    pddIngerer(pddEvent());
    // La console a corrigé le téléphone à la main : l'origine n'est plus « declared ».
    DB::table('personnes')->update([
        'phone' => '+33611111111',
        'field_origins' => json_encode(['email' => 'declared', 'phone' => 'manual']),
        'entreprise_declaree' => null,
    ]);

    pddIngerer(pddEvent(['person' => ['phone' => '+33622222222'], 'company' => ['name' => 'ZZ AUTRE NOM']]));

    $personne = pddPersonne();
    expect($personne->phone)->toBe('+33611111111')
        ->and($personne->entreprise_declaree)->toBe('ZZ AUTRE NOM');
});

test('jamais d’abonnement ni d’éligibilité à une diffusion, adresse pro ou personnelle', function () {
    pddIngerer(pddEvent());
    pddIngerer(pddEvent([], 'zz.perso@gmail.com'));

    expect(DB::table('personnes')->count())->toBe(2)
        ->and(DB::table('abonnements')->count())->toBe(0)
        ->and(Abonnements::eligiblesALaDiffusion((string) $this->workspace->id)->count())->toBe(0);
});

test('l’export « prospectable » ne contient pas la personne née d’une demande, mais garde un abonné', function () {
    pddIngerer(pddEvent());

    // Témoin : un abonné à la lettre sort bien (sinon le test ne prouverait rien).
    config(['crm.ingest.personnes_enabled' => true]);
    $abonne = 'zz.abonne@example.invalid';
    $pid = (int) DB::table('personnes')->insertGetId([
        'workspace_id' => $this->workspace->id,
        'person_key' => hash('sha256', 'pdd|' . $abonne),
        'email' => $abonne,
        'email_hash' => hash('sha256', $abonne),
        'email_nature' => 'pro',
        'premiere_source' => 'newsletter',
        'premiere_source_at' => now(),
        'derniere_interaction_at' => now(),
        'legal_basis' => 'consent',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('abonnements')->insert([
        'workspace_id' => $this->workspace->id, 'personne_id' => $pid, 'canal' => 'lettre', 'statut' => 'abonne',
        'consent_version' => 'v1', 'consent_at' => now(), 'dernier_evenement_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $csv = $this->get('/api/v1/crm/personnes/export')->assertOk()->streamedContent();

    expect($csv)->toContain($abonne)->and($csv)->not->toContain(PDD_EMAIL);
});

// ─────────────────────────────────────────────────────────────────────────────
// C2 : migration, export, mise à l'écart
// ─────────────────────────────────────────────────────────────────────────────

test('la migration refuse son retour arrière quand une personne porte une valeur : colonnes et valeur conservées', function () {
    pddIngerer(pddEvent());

    $migration = require database_path('migrations/2026_11_08_000010_personnes_demandes.php');
    $migration->down();

    expect(Schema::hasColumn('personnes', 'phone'))->toBeTrue()
        ->and(Schema::hasColumn('personnes', 'entreprise_declaree'))->toBeTrue()
        ->and(pddPersonne()->phone)->toBe('+33600000001')
        ->and(pddPersonne()->entreprise_declaree)->toBe('ZZ SOCIETE DECLAREE');
});

test('l’export de portabilité couvre le téléphone et l’entreprise déclarée', function () {
    pddIngerer(pddEvent());

    $export = app(SiteGdprService::class)->export(hash('sha256', 'pdd|' . PDD_EMAIL), PDD_EMAIL);

    expect($export['business']['personnes'])->toHaveCount(1)
        ->and($export['business']['personnes'][0])->toMatchArray([
            'phone' => '+33600000001',
            'entreprise_declaree' => 'ZZ SOCIETE DECLAREE',
        ]);
});

test('la mise à l’écart relève le téléphone de la personne (anti-réinsertion) sans rien supprimer', function () {
    pddIngerer(pddEvent());

    $telephones = EffacementCoordonneesFiches::telephonesDesContacts(PDD_EMAIL, (string) $this->workspace->id, hash('sha256', 'pdd|' . PDD_EMAIL));
    expect($telephones)->toContain('+33600000001');

    $resultat = app(SiteGdprService::class)->erase(hash('sha256', 'pdd|' . PDD_EMAIL), PDD_EMAIL);

    expect($resultat['mis_a_l_ecart']['business']['personnes'])->toBe(1)
        ->and(DB::table('personnes')->count())->toBe(1)
        ->and(pddPersonne()->phone)->toBe('+33600000001')
        ->and(pddPersonne()->opposition_at)->not->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// C3 : le rattachement solde les événements de la même personne
// ─────────────────────────────────────────────────────────────────────────────

test('rattacher un événement vide la file des autres événements de la même personne, sauf le dossier client', function () {
    $formulaire = pddIngerer(pddEvent())->activityId;
    $rendezVous = pddIngerer(pddEvent([
        'event_type' => 'calendly_booked', 'form_type' => null,
        'payload' => ['typeRendezVous' => 'diagnostic'],
    ]))->activityId;
    $autrePersonne = pddIngerer(pddEvent([], 'zz.autre@example.invalid'))->activityId;

    $dossier = (int) DB::table('activities')->insertGetId([
        'workspace_id' => $this->workspace->id,
        'type' => 'dossier_client_synchro',
        'kind' => 'dossier_client_synchro',
        'occurred_at' => now(),
        'person_key' => hash('sha256', 'pdd|' . PDD_EMAIL),
        'external_ref' => 'site:event:' . Str::uuid(),
        'title' => 'Dossier client',
        'payload' => json_encode(['pending_match' => ['subject_ref' => 'site:client:1']]),
        'created_at' => now(),
    ]);

    $companyId = pddEntreprise((string) $this->workspace->id);

    $this->postJson("/api/v1/crm/arbitrage/{$formulaire}/attach", ['company_id' => $companyId])
        ->assertOk()
        ->assertJsonPath('soldes', [$rendezVous]);

    $apres = fn (int $id) => DB::table('activities')->where('id', $id)->first();
    expect($apres($formulaire)->subject_id)->toBe($companyId)
        ->and($apres($rendezVous)->subject_id)->toBe($companyId)
        ->and(json_decode($apres($rendezVous)->payload, true)['arbitrage']['solde_avec'])->toBe($formulaire)
        // Un événement d'une AUTRE personne et le dossier client restent en attente.
        ->and($apres($autrePersonne)->subject_id)->toBeNull()
        ->and($apres($dossier)->subject_id)->toBeNull()
        ->and(FilesATraiter::aRattacher((string) $this->workspace->id)->pluck('id')->sort()->values()->all())->toBe([$autrePersonne, $dossier]);

    // La personne se lie au contact créé, même avec la lettre et le guide fermés.
    $personne = pddPersonne();
    expect($personne->contact_id)->not->toBeNull()
        ->and($personne->company_id)->toBe($companyId);
});

// ─────────────────────────────────────────────────────────────────────────────
// Rattrapage : crm:personnes-depuis-demandes
// ─────────────────────────────────────────────────────────────────────────────

/** @return array<int, string> empreinte de chaque ligne d'activité */
function pddEmpreinteActivites(): array
{
    return DB::table('activities')->orderBy('id')->get()->map(fn ($l) => md5(json_encode($l)))->all();
}

test('rattrapage : à blanc par défaut, puis --appliquer ; activités intactes, aucune adresse en sortie, rejouable', function () {
    // État d'AVANT le lot : drapeau fermé, les demandes restent sans personne.
    config(['crm.ingest.demandes_personne_enabled' => false]);
    pddIngerer(pddEvent());
    pddIngerer(pddEvent(['occurred_at' => '2026-11-05T10:00:00+01:00']));
    pddIngerer(pddEvent([
        'event_type' => 'calendly_booked', 'form_type' => null,
        'payload' => ['eventTypeName' => 'Échange apporteur'],
    ], 'zz.apporteur@example.invalid'));
    pddIngerer(pddEvent([], 'zz.seconde@example.invalid'));
    expect(DB::table('personnes')->count())->toBe(0);

    $avant = pddEmpreinteActivites();

    // À blanc (défaut).
    expect(Artisan::call(CrmPersonnesDepuisDemandes::SIGNATURE))->toBe(0);
    $sortie = Artisan::output();
    expect(DB::table('personnes')->count())->toBe(0)
        ->and($sortie)->toContain('À BLANC')
        ->and($sortie)->toContain('Personnes distinctes concernées : 2')
        ->and($sortie)->not->toContain('@')
        ->and($sortie)->not->toContain('+33')
        ->and(pddEmpreinteActivites())->toBe($avant);

    // Contradictoire.
    expect(Artisan::call(CrmPersonnesDepuisDemandes::SIGNATURE, ['--a-blanc' => true, '--appliquer' => true]))->toBe(1);
    expect(DB::table('personnes')->count())->toBe(0);

    // Application.
    expect(Artisan::call(CrmPersonnesDepuisDemandes::SIGNATURE, ['--appliquer' => true]))->toBe(0);
    $sortie = Artisan::output();
    expect($sortie)->toContain('APPLIQUÉ')->and($sortie)->not->toContain('@')->and($sortie)->not->toContain('+33');
    expect(DB::table('personnes')->count())->toBe(2)
        ->and(pddPersonne()->phone)->toBe('+33600000001')
        ->and(pddPersonne()->entreprise_declaree)->toBe('ZZ SOCIETE DECLAREE')
        ->and(DB::table('personnes')->where('email', 'zz.apporteur@example.invalid')->count())->toBe(0)
        ->and(DB::table('abonnements')->count())->toBe(0)
        ->and(pddEmpreinteActivites())->toBe($avant);

    // Rejouable : rien de plus.
    Artisan::call(CrmPersonnesDepuisDemandes::SIGNATURE, ['--appliquer' => true]);
    expect(DB::table('personnes')->count())->toBe(2)
        ->and(pddEmpreinteActivites())->toBe($avant);
});

test('rattrapage : une opposition ou une demande écartée par l’opérateur ne crée rien', function () {
    config(['crm.ingest.demandes_personne_enabled' => false]);
    $ecartee = pddIngerer(pddEvent())->activityId;
    pddIngerer(pddEvent([], 'zz.oppose@example.invalid'));

    // Écartée à la main dans la file d'arbitrage.
    DB::table('activities')->where('id', $ecartee)->update([
        'payload' => DB::raw("payload || '{\"arbitrage_dismissed\": {\"reason\": \"test\"}}'::jsonb"),
    ]);
    DB::table('opt_out')->insert([
        'email' => null,
        'email_hash' => hash('sha256', 'zz.oppose@example.invalid'),
        'scope' => 'business',
        'source' => 'test',
        'reason' => 'test',
        'created_at' => now(),
    ]);

    Artisan::call(CrmPersonnesDepuisDemandes::SIGNATURE, ['--appliquer' => true]);

    expect(DB::table('personnes')->count())->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// Corrections de l'audit : B1 (solde), B2 (noms, rattrapage), N1 (règle unique)
// ─────────────────────────────────────────────────────────────────────────────

/** Événement en attente inséré à la main, de la même personne. */
function pddEnAttente(string $workspaceId, string $kind, string $email = PDD_EMAIL): int
{
    return (int) DB::table('activities')->insertGetId([
        'workspace_id' => $workspaceId,
        'type' => $kind,
        'kind' => $kind,
        'occurred_at' => now(),
        'person_key' => hash('sha256', 'pdd|' . $email),
        'external_ref' => 'site:event:' . Str::uuid(),
        'title' => $kind,
        'payload' => json_encode(['pending_match' => ['email' => $email, 'last_name' => 'ZZ DEMANDE']]),
        'created_at' => now(),
    ]);
}

test('B1 : un newsletter_optin et un opt_out de la même personne ne sont jamais soldés', function () {
    $formulaire = pddIngerer(pddEvent())->activityId;
    $lettre = pddEnAttente((string) $this->workspace->id, 'newsletter_optin');
    $opposition = pddEnAttente((string) $this->workspace->id, 'opt_out');
    $companyId = pddEntreprise((string) $this->workspace->id);

    $this->postJson("/api/v1/crm/arbitrage/{$formulaire}/attach", ['company_id' => $companyId])
        ->assertOk()
        ->assertJsonPath('soldes', []);

    $apres = fn (int $id) => DB::table('activities')->where('id', $id)->value('subject_id');
    expect($apres($lettre))->toBeNull()->and($apres($opposition))->toBeNull();
});

test('B1 : drapeau fermé, rattacher ne solde aucun autre événement', function () {
    $formulaire = pddIngerer(pddEvent())->activityId;
    $rendezVous = pddIngerer(pddEvent(['event_type' => 'calendly_booked', 'form_type' => null]))->activityId;
    config(['crm.ingest.demandes_personne_enabled' => false]);
    $companyId = pddEntreprise((string) $this->workspace->id);

    $this->postJson("/api/v1/crm/arbitrage/{$formulaire}/attach", ['company_id' => $companyId])
        ->assertOk()
        ->assertJsonPath('soldes', []);

    expect(DB::table('activities')->where('id', $rendezVous)->value('subject_id'))->toBeNull();
});

test('B2 : le rattrapage ne remplace jamais un nom déjà connu, même rejoué dans le désordre', function () {
    config(['crm.ingest.demandes_personne_enabled' => false]);
    // Demande ancienne (nom A) puis récente (nom B), toutes deux sans personne.
    pddIngerer(pddEvent(['occurred_at' => '2026-10-01T10:00:00+01:00', 'person' => ['first_name' => 'Ancien', 'last_name' => 'ZZ NOM A']]));
    pddIngerer(pddEvent(['occurred_at' => '2026-10-20T10:00:00+01:00', 'person' => ['first_name' => 'Recent', 'last_name' => 'ZZ NOM B']]));
    expect(DB::table('personnes')->count())->toBe(0);

    // La personne existe déjà, avec un nom connu (saisi à la main, par exemple).
    $this->artisan(CrmPersonnesDepuisDemandes::SIGNATURE, ['--appliquer' => true])->assertExitCode(0);
    $nomApresPremier = pddPersonne()->last_name;
    DB::table('personnes')->update(['first_name' => 'Connu', 'last_name' => 'ZZ NOM CONNU']);

    // Rejeu (ordre chronologique du rattrapage) : le nom connu reste.
    $this->artisan(CrmPersonnesDepuisDemandes::SIGNATURE, ['--appliquer' => true])->assertExitCode(0);

    expect($nomApresPremier)->toBe('ZZ NOM A')
        ->and(pddPersonne()->first_name)->toBe('Connu')
        ->and(pddPersonne()->last_name)->toBe('ZZ NOM CONNU');
});

test('rattrapage : le type de formulaire est relu dans le titre, les types exclus ne créent rien, nature et langue recopiées', function () {
    config(['crm.ingest.demandes_personne_enabled' => false]);
    pddIngerer(pddEvent(['form_type' => 'presse'], 'zz.presse@example.invalid'));
    pddIngerer(pddEvent([
        'payload' => ['email_nature' => 'perso', 'locale' => 'en'],
    ]));

    $this->artisan(CrmPersonnesDepuisDemandes::SIGNATURE, ['--appliquer' => true])->assertExitCode(0);

    expect(DB::table('personnes')->where('email', 'zz.presse@example.invalid')->count())->toBe(0)
        ->and(DB::table('personnes')->count())->toBe(1)
        ->and(pddPersonne()->email_nature)->toBe('perso')
        ->and(pddPersonne()->locale)->toBe('en');
});

test('N1 : une seule règle de prospection, précontractuelle sans abonnement jamais prospectable', function () {
    expect(Abonnements::prospectionAutorisee('pro', null))->toBeTrue()
        ->and(Abonnements::prospectionAutorisee('pro', null, 'consent'))->toBeTrue()
        ->and(Abonnements::prospectionAutorisee('pro', null, 'precontractual'))->toBeFalse()
        ->and(Abonnements::prospectionAutorisee('pro', 'desabonne', 'precontractual'))->toBeFalse()
        ->and(Abonnements::prospectionAutorisee('pro', 'abonne', 'precontractual'))->toBeTrue()
        ->and(Abonnements::prospectionAutorisee('perso', 'abonne', 'precontractual'))->toBeTrue()
        ->and(Abonnements::prospectionAutorisee('perso', null, 'consent'))->toBeFalse();
});

test('N1 : la liste de la console dit « prospection non autorisée » pour une demande, et le rattachement humain reste possible', function () {
    pddIngerer(pddEvent());
    $id = (int) pddPersonne()->id;

    $liste = $this->getJson('/api/v1/crm/personnes')->assertOk()->json('data');
    $ligne = collect($liste)->firstWhere('id', $id);
    expect($ligne['prospection_autorisee'])->toBeFalse()
        ->and($ligne['phone'])->toBe('+33600000001')
        ->and($ligne['entreprise_declaree'])->toBe('ZZ SOCIETE DECLAREE');

    // Rattacher la demande à son entreprise est un geste humain : permis.
    $this->postJson("/api/v1/crm/personnes/{$id}/rattacher", ['company_id' => pddEntreprise((string) $this->workspace->id)])
        ->assertOk();
});
