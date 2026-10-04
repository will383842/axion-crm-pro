<?php

use App\Crm\Ingest\SiteSyncEvent;
use App\Crm\Ingest\SiteSyncIngestService;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * ÉTIQUETTE DE RENDEZ-VOUS AU RATTACHEMENT (constat prod du 2026-10-04).
 *
 * Un rendez-vous pris par un prospect INCONNU (pas de SIREN) n'est rattaché à
 * aucune entreprise : l'ingestion le range dans « Personnes à rattacher »
 * (`pending_match`) et ne pose, à juste titre, aucune étiquette — il n'y a pas
 * encore de fiche où la poser. Mais quand Will rattache ensuite l'événement à
 * une entreprise, l'étiquette `rdv:*` n'était posée NULLE PART : le cas le plus
 * courant (nouveau prospect) sortait des segments « rendez-vous ».
 *
 * Les événements sont produits par le VRAI service d'ingestion (même chemin
 * que `POST /api/internal/site-sync`), puis rattachés par la VRAIE route de la
 * console : aucune activité n'est fabriquée à la main.
 */
beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);

    config([
        'crm.console_v2' => true,
        'crm.ingest.enabled' => true,
        'crm.ingest.candidates_enabled' => false,
        'crm.ingest.business_workspace' => 'ws-rdv-rattachement',
    ]);

    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'ws-rdv-rattachement',
        'name' => 'Rendez-vous à rattacher',
        'settings' => [],
    ]);

    $user = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'rdv-rattachement@example.invalid',
        'name' => 'Opérateur',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $this->workspace->id,
        'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($user->current_workspace_id);
    $user->assignRole('admin');
    DB::table('user_workspaces')->insertOrIgnore([
        'user_id' => $user->id,
        'workspace_id' => $this->workspace->id,
        'role_slug' => 'owner',
        'invited_at' => now(),
        'joined_at' => now(),
    ]);

    $this->actingAs($user);
});

/**
 * Rendez-vous d'un prospect INCONNU (aucun SIREN) passé par l'ingestion réelle.
 * Rend l'identifiant de l'activité restée dans la file d'arbitrage.
 *
 * @param  array<string, mixed>  $payload
 */
function rdvRattachementEnAttente(array $payload, string $eventType = 'calendly_booked'): int
{
    $email = 'prospect-' . Str::random(8) . '@example.invalid';

    $event = [
        'schema_version' => 1,
        'event_id' => (string) Str::uuid(),
        'event_type' => $eventType,
        'occurred_at' => '2026-10-04T09:30:00+02:00',
        'subject_ref' => 'site:calendly_event:' . Str::uuid(),
        'source_slug' => 'calendly',
        'person' => [
            'person_key' => hash('sha256', $email),
            'email' => $email,
            'first_name' => 'Jeanne',
            'last_name' => 'ZZ PROSPECT',
        ],
        'company' => [
            'name' => 'ZZ NOUVEAU PROSPECT',
            'postcode' => '38000',
            'city' => 'Grenoble',
        ],
        'consent' => [
            'version' => 'v1-2026-05-24',
            'at' => '2026-10-04T09:29:00+02:00',
            'text_ref' => 'calendly',
        ],
        'tags' => [],
        'payload' => $payload,
    ];
    if ($eventType === 'form_submission') {
        $event['form_type'] = 'audit';
    }

    $outcome = app(SiteSyncIngestService::class)->ingest(SiteSyncEvent::fromArray($event));

    expect($outcome->status)->toBe('pending_match');

    return (int) $outcome->activityId;
}

function rdvRattachementEntreprise(string $workspaceId, string $siren): int
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

/** @return list<string> */
function rdvRattachementEtiquettes(int $companyId): array
{
    return DB::table('company_tag')
        ->join('tags', 'tags.id', '=', 'company_tag.tag_id')
        ->where('company_tag.company_id', $companyId)
        ->where('tags.slug', 'like', 'rdv:%')
        ->orderBy('tags.slug')
        ->pluck('tags.slug')
        ->all();
}

test('rendez-vous pending_match : aucune étiquette tant qu’il n’est pas rattaché', function () {
    rdvRattachementEnAttente(['typeRendezVous' => 'diagnostic']);

    expect(DB::table('company_tag')->count())->toBe(0);
});

test('rendez-vous pending_match puis rattaché → étiquette rdv: posée sur l’entreprise', function (string $type, string $etiquette) {
    $activityId = rdvRattachementEnAttente(['typeRendezVous' => $type, 'besoin' => 'audit']);
    $companyId = rdvRattachementEntreprise($this->workspace->id, '900000301');

    $this->postJson("/api/v1/crm/arbitrage/{$activityId}/attach", ['company_id' => $companyId])->assertOk();

    expect(rdvRattachementEtiquettes($companyId))->toBe([$etiquette]);
})->with([
    ['diagnostic', 'rdv:diagnostic'],
    ['echange_projet', 'rdv:echange-projet'],
    ['salon', 'rdv:salon'],
]);

test('rendez-vous rattaché deux fois à la même entreprise → une seule étiquette', function () {
    $companyId = rdvRattachementEntreprise($this->workspace->id, '900000302');
    $premier = rdvRattachementEnAttente(['typeRendezVous' => 'diagnostic']);
    $second = rdvRattachementEnAttente(['typeRendezVous' => 'diagnostic']);

    $this->postJson("/api/v1/crm/arbitrage/{$premier}/attach", ['company_id' => $companyId])->assertOk();
    $this->postJson("/api/v1/crm/arbitrage/{$second}/attach", ['company_id' => $companyId])->assertOk();
    // Le même événement une seconde fois : refusé, et rien de plus posé.
    $this->postJson("/api/v1/crm/arbitrage/{$premier}/attach", ['company_id' => $companyId])->assertStatus(409);

    expect(rdvRattachementEtiquettes($companyId))->toBe(['rdv:diagnostic'])
        ->and(DB::table('company_tag')
            ->join('tags', 'tags.id', '=', 'company_tag.tag_id')
            ->where('company_tag.company_id', $companyId)
            ->where('tags.slug', 'rdv:diagnostic')
            ->count())->toBe(1);
});

test('type absent, « autre », apporteur ou inconnu : rattaché sans aucune étiquette rdv:', function (array $payload) {
    $activityId = rdvRattachementEnAttente($payload);
    $companyId = rdvRattachementEntreprise($this->workspace->id, '900000303');

    $this->postJson("/api/v1/crm/arbitrage/{$activityId}/attach", ['company_id' => $companyId])->assertOk();

    expect(rdvRattachementEtiquettes($companyId))->toBe([])
        ->and((int) DB::table('activities')->where('id', $activityId)->value('subject_id'))->toBe($companyId);
})->with([
    'absent' => [['page' => '/fr/appel']],
    'autre' => [['typeRendezVous' => 'autre']],
    'apporteur' => [['typeRendezVous' => 'apporteur']],
    'inconnu' => [['typeRendezVous' => 'DIAGNOSTIC']],
]);

test('un événement qui n’est pas un rendez-vous ne pose aucune étiquette rdv: au rattachement', function () {
    $activityId = rdvRattachementEnAttente(['typeRendezVous' => 'diagnostic'], 'form_submission');
    $companyId = rdvRattachementEntreprise($this->workspace->id, '900000304');

    $this->postJson("/api/v1/crm/arbitrage/{$activityId}/attach", ['company_id' => $companyId])->assertOk();

    expect(rdvRattachementEtiquettes($companyId))->toBe([]);
});
