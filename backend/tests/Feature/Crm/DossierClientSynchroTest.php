<?php

use App\Crm\Campagnes\EligibiliteAdresse;
use App\Crm\Campagnes\GardeConsoleClient;
use App\Crm\Console\FilesATraiter;
use App\Crm\Doublons\Rapprochement;
use App\Crm\FichesProtegees;
use App\Crm\Ingest\DossierClient;
use App\Crm\Ingest\SiteSyncEvent;
use App\Crm\Taxonomy;
use App\Models\User;
use App\Services\Audit\AuditHashChain;
use App\Support\WorkspaceContext;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * SYNCHRO AUTOMATIQUE du dossier client console du site → CRM (25/10/2026).
 *
 * Par le RÉSEAU (`POST /api/internal/site-sync`, signature comprise), comme
 * `SiteSyncIngestTest` : la preuve porte sur la chaîne entière. Un test par
 * garde-fou de `DossierClientIngest` : idempotence, version ancienne,
 * rapprochement (référence console puis SIREN, arbitrage sans SIREN ou en
 * désaccord, jamais de doublon), retouche manuelle plus récente, le plus
 * récent gagne avec l'ancienne valeur à l'historique, aucune suppression,
 * étape « client » à la facture derrière son drapeau, clés interdites, aucun
 * e-mail ni campagne, purges sans effet.
 */
function dcsSecret(): string
{
    return 'secret-de-test-dossier-' . str_repeat('c3d4', 10);
}

beforeEach(function () {
    config([
        'crm.ingest.enabled' => true,
        'crm.ingest.dossier_enabled' => true,
        'crm.ingest.dossier_client_stage' => false,
        'crm.ingest.hmac_secret' => dcsSecret(),
        'crm.ingest.business_workspace' => 'axion-ia',
        'crm.ingest.max_clock_skew_seconds' => 300,
    ]);

    $this->espace = dcsEspace();
});

function dcsEspace(): string
{
    $id = DB::table('workspaces')->where('slug', 'axion-ia')->value('id');
    if ($id !== null) {
        return (string) $id;
    }
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $id, 'slug' => 'axion-ia', 'name' => 'Axion-IA', 'settings' => '{}',
        'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

const DCS_CLIENT = '0b5c3a9e-1f2d-4c6b-8a7e-9d0c1b2a3f4e';

/**
 * @param  array<string, mixed>  $dossier  fusionné dans le dossier par défaut
 * @param  array<string, mixed>  $enveloppe  fusionnée dans l'enveloppe
 * @return array<string, mixed>
 */
function dcsEvenement(array $dossier = [], array $enveloppe = []): array
{
    $base = [
        'schema_version' => 1,
        'event_id' => (string) Str::uuid(),
        'event_type' => 'dossier_client_synchro',
        'occurred_at' => '2026-10-20T10:00:00+02:00',
        'subject_ref' => 'site:client:' . DCS_CLIENT,
        'company' => ['siren' => '900000777', 'name' => 'ZZ CLIENT CONSOLE SAS', 'postcode' => '75011', 'city' => 'Paris'],
        'payload' => ['dossier' => array_replace([
            'version' => 1,
            'statut' => 'prospect',
            'facture_emise' => false,
            'rue' => '12 rue des Tests',
            'telephone' => '+33140000001',
            'axi_cli' => 'AXI-CLI-004',
            'effectif_tranche' => '10-19',
            'url_console' => 'https://axion-ia.example.invalid/console/clients/' . DCS_CLIENT,
            'questionnaire' => ['envoye_le' => '2026-10-18T09:00:00+02:00', 'recu_le' => null, 'nb_reponses_equipe' => 2],
            'contacts' => [
                ['ref' => 'c-1', 'prenom' => 'Zoé', 'nom' => 'ZZ DIRIGEANTE', 'email' => 'zz.dirigeante@client.example.invalid', 'telephone' => '+33600000011', 'fonction' => 'Gérante'],
            ],
        ], $dossier)],
    ];

    return array_replace($base, $enveloppe);
}

/** @param  array<string, mixed>  $event */
function dcsPost(array $event): TestResponse
{
    $body = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ts = (string) time();

    return test()->call('POST', '/api/internal/site-sync', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SITE_TIMESTAMP' => $ts,
        'HTTP_X_SITE_SIGNATURE' => hash_hmac('sha256', $ts . '.' . $body, dcsSecret()),
    ], $body);
}

function dcsFiche(): ?stdClass
{
    return DB::table('companies')->where('console_client_ref', 'site:client:' . DCS_CLIENT)->first();
}

/** @return array<string, mixed> */
function dcsDernierJournal(): array
{
    $ligne = DB::table('activities')->where('kind', 'dossier_client_synchro')->orderByDesc('id')->first();

    return json_decode((string) $ligne->payload, true);
}

// ─────────────────────────────────────────────────────────────────────────────
// Inertie et contrat
// ─────────────────────────────────────────────────────────────────────────────

test('drapeau du dossier fermé : 503, la ligne reste en attente côté site, rien n est écrit', function () {
    config(['crm.ingest.dossier_enabled' => false]);

    dcsPost(dcsEvenement())->assertStatus(503)->assertJsonPath('error', 'dossier_ingest_disabled');

    expect(DB::table('companies')->count())->toBe(0)
        ->and(DB::table('activities')->count())->toBe(0);
});

test('le type et la timeline connaissent le dossier client (listes fermées)', function () {
    expect(DossierClient::EVENT_TYPE)->toBe('dossier_client_synchro')
        ->and(SiteSyncEvent::EVENT_TYPES)->toContain('dossier_client_synchro')
        ->and(Taxonomy::ACTIVITY_KINDS)->toContain('dossier_client_synchro')
        ->and(FichesProtegees::TAGS)->toContain('src:console-client');
});

test('les clés de réponse, de texte libre et de montant sont REFUSÉES, à toute profondeur', function (array $dossier, string $code) {
    dcsPost(dcsEvenement($dossier))->assertStatus(422)->assertJsonPath('error', $code);

    expect(DB::table('companies')->count())->toBe(0);
})->with([
    'réponses du questionnaire' => [['reponses' => ['q1' => 'oui']], 'forbidden_field'],
    'texte dans le questionnaire' => [['questionnaire' => ['envoye_le' => null, 'texte' => 'bla']], 'forbidden_field'],
    'montant' => [['montant_ht' => 1200], 'forbidden_field'],
    'numéro de facture' => [['numero_facture' => 'F-2026-001'], 'forbidden_field'],
    'commentaire dans un contact' => [['contacts' => [['ref' => 'c-1', 'nom' => 'ZZ', 'commentaire' => 'x']]], 'forbidden_field'],
    'clé inconnue' => [['outil_prefere' => 'x'], 'unknown_field'],
    'statut client sans facture' => [['statut' => 'client', 'facture_emise' => false], 'dossier_statut_incoherent'],
    'facture sans statut client' => [['statut' => 'prospect', 'facture_emise' => true], 'dossier_statut_incoherent'],
    'version absente' => [['version' => 0], 'invalid_dossier_version'],
]);

test('l enveloppe du dossier est fermée : ni personne, ni tags, ni site web', function (array $enveloppe, string $code) {
    dcsPost(dcsEvenement([], $enveloppe))->assertStatus(422)->assertJsonPath('error', $code);
})->with([
    'personne' => [['person' => ['person_key' => hash('sha256', 'x@y.invalid')]], 'invalid_dossier'],
    'tags' => [['tags' => ['src:calendly']], 'invalid_dossier'],
    'site web' => [['company' => ['siren' => '900000777', 'website' => 'https://x.invalid']], 'unknown_field'],
    'mauvaise référence' => [['subject_ref' => 'site:submission:' . DCS_CLIENT], 'invalid_subject_ref'],
]);

// ─────────────────────────────────────────────────────────────────────────────
// Création, rapprochement, idempotence
// ─────────────────────────────────────────────────────────────────────────────

test('création par SIREN : fiche, personne, tag protégé, timeline — prospect, jamais client', function () {
    $r = dcsPost(dcsEvenement())->assertOk()->assertJsonPath('result.status', 'created');

    $fiche = dcsFiche();
    expect($fiche)->not->toBeNull()
        ->and($fiche->siren)->toBe('900000777')
        ->and($fiche->address)->toBe('12 rue des Tests')
        ->and($fiche->phone)->toBe('+33140000001')
        ->and($fiche->relation_type)->toBe('prospect')
        ->and($fiche->lifecycle_stage)->toBe('qualifie')
        ->and($fiche->discovery_source)->toBe('console')
        ->and((int) $fiche->console_dossier_version)->toBe(1)
        ->and(json_decode($fiche->field_origins, true)['phone'])->toBe('console');

    $meta = json_decode($fiche->metadata, true)['console'];
    expect($meta['axi_cli'])->toBe('AXI-CLI-004')
        ->and($meta['effectif_tranche'])->toBe('10-19')
        ->and($meta['questionnaire']['nb_reponses_equipe'])->toBe(2);

    expect(DB::table('company_tag')->join('tags', 'tags.id', '=', 'company_tag.tag_id')
        ->where('company_tag.company_id', $fiche->id)->value('tags.slug'))->toBe('src:console-client');

    $contact = DB::table('contacts')->where('company_id', $fiche->id)->first();
    expect($contact->last_name)->toBe('ZZ DIRIGEANTE')
        ->and($contact->role)->toBe('Gérante')
        ->and($contact->discovery_source)->toBe('console')
        ->and($contact->legal_basis)->toBe('precontractual');

    expect($r->json('result.activity_id'))->toBeInt()
        ->and(DB::table('activities')->where('kind', 'dossier_client_synchro')->where('subject_id', $fiche->id)->count())->toBe(1);
});

test('IDEMPOTENCE : le même event_id rejoué ne crée ni ne modifie rien', function () {
    $evenement = dcsEvenement();
    dcsPost($evenement)->assertOk()->assertJsonPath('result.status', 'created');
    $avant = [DB::table('companies')->count(), DB::table('contacts')->count(), DB::table('activities')->count()];

    dcsPost($evenement)->assertOk()->assertJsonPath('result.status', 'noop_idempotent');

    expect([DB::table('companies')->count(), DB::table('contacts')->count(), DB::table('activities')->count()])->toBe($avant);
});

test('VERSION ANCIENNE : un message de version inférieure arrivé après coup ne touche à rien', function () {
    dcsPost(dcsEvenement(['version' => 2, 'telephone' => '+33140000002']))->assertOk();
    $journaux = DB::table('activities')->count();

    dcsPost(dcsEvenement(['version' => 1, 'telephone' => '+33149999999'], ['occurred_at' => '2026-10-21T10:00:00+02:00']))
        ->assertOk()->assertJsonPath('result.status', 'noop_idempotent');

    expect(dcsFiche()->phone)->toBe('+33140000002')
        ->and((int) dcsFiche()->console_dossier_version)->toBe(2)
        ->and(DB::table('activities')->count())->toBe($journaux);
});

test('rapprochement par la référence console : un message SANS SIREN met à jour la bonne fiche', function () {
    dcsPost(dcsEvenement())->assertOk();

    dcsPost(dcsEvenement(['version' => 2, 'rue' => '14 rue des Tests'], ['company' => ['name' => 'ZZ CLIENT CONSOLE SAS']]))
        ->assertOk()->assertJsonPath('result.status', 'updated');

    expect(DB::table('companies')->count())->toBe(1)
        ->and(dcsFiche()->address)->toBe('14 rue des Tests');
});

test('fiche EXISTANTE par SIREN (venue d un formulaire) : rattachée, jamais dupliquée, sa référence d origine gardée', function () {
    $id = DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'siren' => '900000777', 'denomination' => 'ZZ NOM INSEE',
        'external_ref' => 'site:submission:11111111-2222-3333-4444-555555555555',
        'relation_type' => 'prospect', 'lifecycle_stage' => 'nouveau',
        'signals' => '{}', 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);

    dcsPost(dcsEvenement())->assertOk()->assertJsonPath('result.status', 'updated');

    $fiche = DB::table('companies')->where('id', $id)->first();
    expect(DB::table('companies')->count())->toBe(1)
        ->and($fiche->console_client_ref)->toBe('site:client:' . DCS_CLIENT)
        ->and($fiche->external_ref)->toBe('site:submission:11111111-2222-3333-4444-555555555555')
        // La dénomination officielle n'est jamais remplacée par le nom d'usage.
        ->and($fiche->denomination)->toBe('ZZ NOM INSEE')
        // (f) ni le type ni l'étape ne bougent.
        ->and($fiche->relation_type)->toBe('prospect')
        ->and($fiche->lifecycle_stage)->toBe('nouveau');
});

test('SANS SIREN et client inconnu : arbitrage, aucune fiche créée', function () {
    $evenement = dcsEvenement([], ['company' => ['name' => 'ZZ SANS SIREN', 'city' => 'Lyon']]);

    dcsPost($evenement)->assertOk()->assertJsonPath('result.status', 'pending_match');

    expect(DB::table('companies')->count())->toBe(0)
        ->and(DB::table('contacts')->count())->toBe(0);
    $ligne = FilesATraiter::aRattacher($this->espace)->first();
    $pending = json_decode($ligne->payload, true)['pending_match'];
    expect($pending['motif'])->toBe('sans_siren')
        ->and($pending['subject_ref'])->toBe('site:client:' . DCS_CLIENT)
        ->and($pending['denomination'])->toBe('ZZ SANS SIREN');
});

test('DÉSACCORD : la référence console désigne une fiche d un autre SIREN → arbitrage, jamais de fusion ni de doublon', function () {
    dcsPost(dcsEvenement())->assertOk();
    $avant = (array) dcsFiche();

    dcsPost(dcsEvenement(['version' => 2, 'rue' => 'AUTRE RUE'], ['company' => ['siren' => '900000888']]))
        ->assertOk()->assertJsonPath('result.status', 'pending_match');

    expect(DB::table('companies')->count())->toBe(1)
        ->and(dcsFiche()->address)->toBe($avant['address'])
        ->and(json_decode(FilesATraiter::aRattacher($this->espace)->value('payload'), true)['pending_match']['motif'])->toBe('desaccord_siren');
});

test('un SIREN déjà rattaché à un AUTRE client de la console → arbitrage', function () {
    dcsPost(dcsEvenement())->assertOk();

    dcsPost(dcsEvenement([], ['subject_ref' => 'site:client:99999999-8888-4777-8666-555555555555']))
        ->assertOk()->assertJsonPath('result.status', 'pending_match');

    expect(DB::table('companies')->count())->toBe(1);
});

// ─────────────────────────────────────────────────────────────────────────────
// Conflits, historique, suppressions
// ─────────────────────────────────────────────────────────────────────────────

test('le plus RÉCENT gagne et l ANCIENNE valeur est gardée à l historique', function () {
    dcsPost(dcsEvenement())->assertOk();
    // Une retouche manuelle ANCIENNE (avant le prochain message).
    DB::table('companies')->where('id', dcsFiche()->id)->update([
        'phone' => '+33155555555',
        'field_origins' => json_encode(['phone' => 'manual']),
        'field_origins_at' => json_encode(['phone' => '2026-10-20T12:00:00+02:00']),
    ]);

    dcsPost(dcsEvenement(['version' => 2, 'telephone' => '+33140000002'], ['occurred_at' => '2026-10-21T10:00:00+02:00']))->assertOk();

    expect(dcsFiche()->phone)->toBe('+33140000002');
    $changement = collect(dcsDernierJournal()['changements'])->firstWhere('champ', 'phone');
    expect($changement['avant'])->toBe(['phone' => '+33155555555'])
        ->and($changement['apres'])->toBe(['phone' => '+33140000002'])
        ->and($changement['origine_avant'])->toBe('manual');
});

test('RETOUCHE MANUELLE récente (console du CRM) : jamais écrasée par une valeur console plus ancienne', function () {
    dcsPost(dcsEvenement())->assertOk();
    $fiche = dcsFiche();

    $this->seed(PermissionsAndRolesSeeder::class);
    setPermissionsTeamId($this->espace);
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'dcs-' . Str::random(8) . '@example.invalid', 'name' => 'ZZ owner',
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $this->espace, 'first_login_completed_at' => now(),
    ]);
    $user->assignRole('owner');
    $this->actingAs($user);

    // Étape 1 : la retouche manuelle note `manual` et sa date.
    $this->putJson("/api/v1/companies/{$fiche->id}", ['phone' => '+33177777777'])->assertOk();
    $apres = DB::table('companies')->where('id', $fiche->id)->first();
    expect(json_decode($apres->field_origins, true)['phone'])->toBe('manual')
        ->and(json_decode($apres->field_origins_at, true))->toHaveKey('phone')
        // Une colonne envoyée sans changement n'est pas marquée.
        ->and(json_decode($apres->field_origins, true))->not->toHaveKey('website');

    // Étape 2 : un message console daté d'AVANT la retouche.
    dcsPost(dcsEvenement(['version' => 2, 'telephone' => '+33140000009'], ['occurred_at' => '2026-10-22T10:00:00+02:00']))->assertOk();

    expect(dcsFiche()->phone)->toBe('+33177777777');
    $ignore = collect(dcsDernierJournal()['ignores'])->firstWhere('champ', 'phone');
    expect($ignore['raison'])->toBe('valeur_crm_plus_recente');
});

test('RETOUCHE MANUELLE puis message PLUS RÉCENT au même téléphone : la retouche reste (la console n a rien changé)', function () {
    dcsPost(dcsEvenement())->assertOk();
    $fiche = dcsFiche();
    $contact = DB::table('contacts')->where('company_id', $fiche->id)->first();
    // Retouches faites dans le CRM, APRÈS v1, sur la fiche et la personne.
    DB::table('companies')->where('id', $fiche->id)->update([
        'phone' => '+33188888888',
        'field_origins' => json_encode(['phone' => 'manual']),
        'field_origins_at' => json_encode(['phone' => '2026-10-20T11:00:00+02:00']),
    ]);
    DB::table('contacts')->where('id', $contact->id)->update(['role' => 'Présidente', 'field_origins' => json_encode(['role' => 'manual'])]);

    // v2, PLUS RÉCENTE que les retouches, ne change que la rue : téléphone et
    // fonction identiques à ceux de v1.
    dcsPost(dcsEvenement(['version' => 2, 'rue' => '13 rue des Tests'], ['occurred_at' => '2026-10-23T10:00:00+02:00']))->assertOk();

    expect(dcsFiche()->phone)->toBe('+33188888888')
        ->and(dcsFiche()->address)->toBe('13 rue des Tests')
        ->and(DB::table('contacts')->where('id', $contact->id)->value('role'))->toBe('Présidente');

    // v3 change VRAIMENT le téléphone : le plus récent gagne, l'ancien est à l'historique.
    dcsPost(dcsEvenement(['version' => 3, 'rue' => '13 rue des Tests', 'telephone' => '+33140000003'], ['occurred_at' => '2026-10-24T10:00:00+02:00']))->assertOk();
    expect(dcsFiche()->phone)->toBe('+33140000003')
        ->and(collect(dcsDernierJournal()['changements'])->firstWhere('champ', 'phone')['avant'])->toBe(['phone' => '+33188888888'])
        // Jamais la valeur en clair dans `metadata` : une empreinte.
        ->and(json_decode(dcsFiche()->metadata, true)['console_recu']['phone'])->not->toContain('3314');
});

test('ARBITRAGE en désaccord de SIREN : la décision humaine est retenue, le message suivant ne repart pas en arbitrage', function () {
    config(['crm.console_v2' => true]);
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);

    dcsPost(dcsEvenement())->assertOk();
    $fiche = dcsFiche();
    $autreSiren = ['company' => ['siren' => '900000888', 'name' => 'ZZ CLIENT CONSOLE SAS']];
    dcsPost(dcsEvenement(['version' => 2, 'rue' => 'RUE ARBITREE'], $autreSiren))->assertOk()->assertJsonPath('result.status', 'pending_match');

    setPermissionsTeamId($this->espace);
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'dcs-arb2-' . Str::random(8) . '@example.invalid', 'name' => 'ZZ admin',
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $this->espace, 'first_login_completed_at' => now(),
    ]);
    $user->assignRole('admin');
    $this->actingAs($user);

    $ligne = (int) FilesATraiter::aRattacher($this->espace)->value('id');
    $this->postJson("/api/v1/crm/arbitrage/{$ligne}/attach", ['company_id' => $fiche->id])->assertOk();
    expect(dcsFiche()->address)->toBe('RUE ARBITREE');

    dcsPost(dcsEvenement(['version' => 3, 'rue' => 'RUE SUIVANTE'], $autreSiren))->assertOk()->assertJsonPath('result.status', 'updated');
    expect(dcsFiche()->address)->toBe('RUE SUIVANTE')
        ->and(dcsFiche()->siren)->toBe('900000777')
        ->and(FilesATraiter::aRattacher($this->espace)->count())->toBe(0);
});

test('la fiche de la référence n a PAS de SIREN mais une AUTRE fiche porte celui envoyé → arbitrage', function () {
    $sansSiren = DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'siren' => null, 'denomination' => 'ZZ SANS SIREN',
        'country_code' => 'FR', 'foreign_id' => 'zz:sans-siren-console', 'entity_nature' => 'association',
        'console_client_ref' => 'site:client:' . DCS_CLIENT,
        'signals' => '{}', 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('companies')->insert([
        'workspace_id' => $this->espace, 'siren' => '900000777', 'denomination' => 'ZZ PORTEUSE DU SIREN',
        'signals' => '{}', 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);

    dcsPost(dcsEvenement(['version' => 2]))->assertOk()->assertJsonPath('result.status', 'pending_match');

    expect(DB::table('companies')->where('id', $sansSiren)->value('address'))->toBeNull()
        ->and(DB::table('companies')->count())->toBe(2);
});

test('FUSION humaine : la fiche console absorbée renvoie vers la fiche gardée, qui reçoit la référence', function () {
    dcsPost(dcsEvenement())->assertOk();
    $absorbee = dcsFiche();
    $garde = DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'siren' => '900000776', 'denomination' => 'ZZ GARDEE',
        'signals' => '{}', 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('companies')->where('id', $absorbee->id)->update(['deleted_at' => now()]);
    DB::table('fusions_fiches')->insert([
        'workspace_id' => $this->espace, 'garde_id' => $garde, 'absorbee_id' => $absorbee->id,
        'motif' => array_key_first(Rapprochement::MOTIFS), 'mode' => 'manuel',
        'journal' => '{}', 'absorbee_supprimee_le' => now(), 'created_at' => now(),
    ]);

    // Sans SIREN : seule la référence (et la fusion) désigne la fiche.
    dcsPost(dcsEvenement(['version' => 2, 'rue' => 'RUE APRES FUSION'], ['company' => ['name' => 'ZZ CLIENT CONSOLE SAS']]))
        ->assertOk()->assertJsonPath('result.status', 'updated');

    expect(DB::table('companies')->where('id', $garde)->value('console_client_ref'))->toBe('site:client:' . DCS_CLIENT)
        ->and(DB::table('companies')->where('id', $garde)->value('address'))->toBe('RUE APRES FUSION')
        ->and(DB::table('companies')->where('id', $absorbee->id)->value('console_client_ref'))->toBeNull()
        ->and(DB::table('companies')->where('id', $absorbee->id)->value('deleted_at'))->not->toBeNull();
});

test('un client parti en arbitrage SANS SIREN puis reçu AVEC : ses anciennes lignes quittent « Personnes à rattacher »', function () {
    dcsPost(dcsEvenement([], ['company' => ['name' => 'ZZ BIENTOT SIREN']]))->assertOk()->assertJsonPath('result.status', 'pending_match');
    expect(FilesATraiter::aRattacher($this->espace)->count())->toBe(1);

    dcsPost(dcsEvenement(['version' => 2]))->assertOk()->assertJsonPath('result.status', 'created');

    expect(FilesATraiter::aRattacher($this->espace)->count())->toBe(0)
        ->and(DB::table('activities')->where('kind', 'dossier_client_synchro')->where('subject_id', dcsFiche()->id)->count())->toBe(2);
});

test('AUCUNE suppression ne se propage : champ absent, contact absent, contact à la corbeille', function () {
    dcsPost(dcsEvenement(['contacts' => [
        ['ref' => 'c-1', 'nom' => 'ZZ UN', 'email' => 'zz.un@client.example.invalid'],
        ['ref' => 'c-2', 'nom' => 'ZZ DEUX', 'email' => 'zz.deux@client.example.invalid'],
    ]]))->assertOk();
    $fiche = dcsFiche();
    DB::table('contacts')->where('company_id', $fiche->id)->where('last_name', 'ZZ DEUX')->update(['deleted_at' => now()]);

    // v2 : plus de rue, plus de téléphone, plus que c-2 (à la corbeille dans le CRM).
    dcsPost(dcsEvenement(['version' => 2, 'rue' => null, 'telephone' => null, 'contacts' => [
        ['ref' => 'c-2', 'nom' => 'ZZ DEUX', 'email' => 'zz.deux@client.example.invalid', 'telephone' => '+33600000099'],
    ]]))->assertOk();

    $apres = dcsFiche();
    expect($apres->address)->toBe('12 rue des Tests')
        ->and($apres->phone)->toBe('+33140000001')
        ->and(DB::table('contacts')->where('company_id', $fiche->id)->whereNull('deleted_at')->pluck('last_name')->all())->toBe(['ZZ UN'])
        ->and(DB::table('contacts')->where('company_id', $fiche->id)->where('last_name', 'ZZ DEUX')->value('deleted_at'))->not->toBeNull()
        ->and(DB::table('contacts')->where('company_id', $fiche->id)->count())->toBe(2);
    expect(collect(dcsDernierJournal()['contacts']['ignores'])->firstWhere('ref', 'c-2')['raison'])->toBe('contact_a_la_corbeille');
});

test('une personne OPPOSÉE n est ni créée ni modifiée', function () {
    DB::table('opt_out')->insert([
        'email' => null, 'email_hash' => hash('sha256', 'zz.dirigeante@client.example.invalid'),
        'scope' => 'business', 'source' => 'test', 'created_at' => now(),
    ]);

    dcsPost(dcsEvenement())->assertOk();

    expect(DB::table('contacts')->count())->toBe(0);
});

test('une personne OPPOSÉE par son TÉLÉPHONE (sans e-mail) n est pas créée, quelle que soit l écriture du numéro', function () {
    DB::table('opt_out')->insert([
        'email' => null, 'email_hash' => null, 'phone' => '06 00 00 00 22',
        'scope' => 'business', 'source' => 'test', 'created_at' => now(),
    ]);

    dcsPost(dcsEvenement(['contacts' => [['ref' => 'c-9', 'nom' => 'ZZ SANS MAIL', 'telephone' => '+33600000022']]]))->assertOk();

    expect(DB::table('contacts')->where('last_name', 'ZZ SANS MAIL')->exists())->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// Étape « client » à la facture
// ─────────────────────────────────────────────────────────────────────────────

test('facture établie, drapeau FERMÉ : l étape ne bouge pas', function () {
    dcsPost(dcsEvenement())->assertOk();

    dcsPost(dcsEvenement(['version' => 2, 'statut' => 'client', 'facture_emise' => true]))->assertOk();

    expect(dcsFiche()->lifecycle_stage)->toBe('qualifie')
        ->and(dcsFiche()->relation_type)->toBe('prospect');
});

test('facture établie, drapeau OUVERT : étape « client », jamais de retour en arrière, type inchangé', function () {
    config(['crm.ingest.dossier_client_stage' => true]);
    dcsPost(dcsEvenement())->assertOk();
    expect(dcsFiche()->lifecycle_stage)->toBe('qualifie');

    dcsPost(dcsEvenement(['version' => 2, 'statut' => 'client', 'facture_emise' => true]))->assertOk();
    expect(dcsFiche()->lifecycle_stage)->toBe('client')
        ->and(dcsFiche()->relation_type)->toBe('prospect')
        ->and(dcsFiche()->legal_basis)->toBe('contract');

    dcsPost(dcsEvenement(['version' => 3, 'statut' => 'prospect', 'facture_emise' => false]))->assertOk();
    expect(dcsFiche()->lifecycle_stage)->toBe('client');
});

test('drapeau OUVERT mais relation posée À LA MAIN : l automatisme ne touche pas l étape', function () {
    config(['crm.ingest.dossier_client_stage' => true]);
    dcsPost(dcsEvenement())->assertOk();
    DB::table('companies')->where('id', dcsFiche()->id)->update(['relation_saisie_manuelle_at' => now()]);

    dcsPost(dcsEvenement(['version' => 2, 'statut' => 'client', 'facture_emise' => true]))->assertOk();

    expect(dcsFiche()->lifecycle_stage)->toBe('qualifie');
});

// ─────────────────────────────────────────────────────────────────────────────
// Aucun e-mail, aucune campagne, aucune purge
// ─────────────────────────────────────────────────────────────────────────────

test('AUCUN e-mail, aucune notification, aucune tâche en file', function () {
    Mail::fake();
    Notification::fake();
    Queue::fake();

    dcsPost(dcsEvenement())->assertOk();
    dcsPost(dcsEvenement(['version' => 2, 'statut' => 'client', 'facture_emise' => true]))->assertOk();

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
});

test('JAMAIS en campagne : la personne d un client de la console est écartée', function () {
    dcsPost(dcsEvenement())->assertOk();
    $fiche = dcsFiche();
    // Une personne collectée PLUS TARD sur la même fiche est écartée aussi.
    DB::table('contacts')->insert([
        'workspace_id' => $this->espace, 'company_id' => $fiche->id, 'last_name' => 'ZZ COLLECTEE',
        'email' => 'zz.collectee@client.example.invalid', 'discovery_source' => 'scraping',
        'sources' => '[]', 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $temoin = DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'siren' => '900000999', 'denomination' => 'ZZ TEMOIN',
        'signals' => '{}', 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('contacts')->insert([
        'workspace_id' => $this->espace, 'company_id' => $temoin, 'last_name' => 'ZZ TEMOIN',
        'email' => 'zz.temoin@autre.example.invalid', 'discovery_source' => 'scraping',
        'sources' => '[]', 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $exclues = WorkspaceContext::run($this->espace, fn (): array => GardeConsoleClient::exclues($this->espace, [
        'zz.dirigeante@client.example.invalid', 'zz.collectee@client.example.invalid', 'zz.temoin@autre.example.invalid',
    ]));

    expect(array_keys($exclues))->toEqualCanonicalizing(['zz.dirigeante@client.example.invalid', 'zz.collectee@client.example.invalid'])
        ->and(EligibiliteAdresse::MOTIFS)->toContain('fiche_console');
});

test('CRM_PURGE_ENABLED ouvert : ni la fiche ni les personnes d un client de la console ne sont purgées', function () {
    config(['crm.purges_enabled' => true]);
    dcsPost(dcsEvenement())->assertOk();
    $fiche = dcsFiche();
    // Le pire cas : une personne vieille de 4 ans, en base « intérêt légitime ».
    DB::table('contacts')->where('company_id', $fiche->id)->update([
        'legal_basis' => 'legitimate_interest_b2b', 'created_at' => now()->subYears(4), 'person_key' => null,
    ]);
    DB::table('companies')->where('id', $fiche->id)->update(['legal_form' => '1000']);
    // Témoin : une fiche ordinaire froide, qui DOIT partir — la purge a bien tourné.
    $temoin = DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'siren' => '900000998', 'denomination' => 'ZZ TEMOIN FROID', 'legal_form' => '5710',
        'signals' => '{}', 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $contactTemoin = DB::table('contacts')->insertGetId([
        'workspace_id' => $this->espace, 'company_id' => $temoin, 'last_name' => 'ZZ FROID',
        'legal_basis' => 'legitimate_interest_b2b', 'sources' => '[]', 'metadata' => '{}',
        'created_at' => now()->subYears(4), 'updated_at' => now(),
    ]);

    Artisan::call('rgpd:purge-business-prospects', ['--force' => true]);
    Artisan::call('prospection:purge-non-commercial', ['--force' => true]);

    expect(DB::table('contacts')->where('id', $contactTemoin)->exists())->toBeFalse()
        ->and(DB::table('companies')->where('id', $fiche->id)->exists())->toBeTrue()
        ->and(DB::table('contacts')->where('company_id', $fiche->id)->count())->toBe(1);
    // Et la base elle-même refuse la suppression physique de la fiche.
    expect(fn () => DB::transaction(fn () => DB::table('companies')->where('id', $fiche->id)->delete()))
        ->toThrow(QueryException::class, 'fiche_protegee');
});

// ─────────────────────────────────────────────────────────────────────────────
// Arbitrage humain
// ─────────────────────────────────────────────────────────────────────────────

test('ARBITRAGE : rattacher un dossier sans SIREN pose la référence et applique le dossier le plus récent', function () {
    config(['crm.console_v2' => true]);
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);

    $sansSiren = ['company' => ['name' => 'ZZ SANS SIREN']];
    dcsPost(dcsEvenement(['version' => 1], $sansSiren))->assertOk()->assertJsonPath('result.status', 'pending_match');
    dcsPost(dcsEvenement(['version' => 2, 'rue' => '99 rue Choisie'], $sansSiren))->assertOk()->assertJsonPath('result.status', 'pending_match');
    expect(FilesATraiter::aRattacher($this->espace)->count())->toBe(2);

    $cible = DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'siren' => '900000555', 'denomination' => 'ZZ CIBLE',
        'signals' => '{}', 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);

    setPermissionsTeamId($this->espace);
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'dcs-arb-' . Str::random(8) . '@example.invalid', 'name' => 'ZZ admin',
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $this->espace, 'first_login_completed_at' => now(),
    ]);
    $user->assignRole('admin');
    $this->actingAs($user);

    $premiere = (int) FilesATraiter::aRattacher($this->espace)->orderBy('id')->value('id');
    $this->postJson("/api/v1/crm/arbitrage/{$premiere}/attach", ['company_id' => $cible])->assertOk();

    $fiche = DB::table('companies')->where('id', $cible)->first();
    expect($fiche->console_client_ref)->toBe('site:client:' . DCS_CLIENT)
        ->and($fiche->address)->toBe('99 rue Choisie')
        ->and((int) $fiche->console_dossier_version)->toBe(2)
        ->and(FilesATraiter::aRattacher($this->espace)->count())->toBe(0)
        ->and(DB::table('companies')->count())->toBe(1);

    // Le message suivant, toujours sans SIREN, trouve sa fiche tout seul.
    dcsPost(dcsEvenement(['version' => 3, 'rue' => '100 rue Choisie'], $sansSiren))->assertOk()->assertJsonPath('result.status', 'updated');
    expect(DB::table('companies')->where('id', $cible)->value('address'))->toBe('100 rue Choisie');
});

// ─────────────────────────────────────────────────────────────────────────────
// Fiche complète (29/10/2026) : le CRM accepte d'abord, la console enverra ensuite
// ─────────────────────────────────────────────────────────────────────────────

/** @return array<string, mixed> */
function dcsComplements(): array
{
    return [
        'etape' => 'devis_envoye',
        'cloture_prevue' => '2026-12-15',
        'prochaine_action' => ['type' => 'relance', 'date' => '2026-11-05'],
        'taches_ouvertes' => ['nb' => 3, 'prochaine_le' => '2026-11-03'],
        'nb_emails_console' => 7,
        'nb_notes_console' => 4,
        'sante' => 'a_surveiller',
        'questionnaire' => ['envoye_le' => '2026-10-18T09:00:00+02:00', 'recu_le' => null, 'nb_reponses_equipe' => 2, 'attendus' => 5, 'date_limite' => '2026-11-10'],
        'contacts' => [
            ['ref' => 'c-1', 'prenom' => 'Zoé', 'nom' => 'ZZ DIRIGEANTE', 'email' => 'zz.dirigeante@client.example.invalid', 'fonction' => 'Gérante', 'role_decision' => 'decideur'],
        ],
    ];
}

test('fiche complète : chaque nouvelle clé est acceptée et gardée dans metadata.console', function () {
    dcsPost(dcsEvenement(dcsComplements()))->assertOk()->assertJsonPath('result.status', 'created');

    $fiche = dcsFiche();
    $meta = json_decode($fiche->metadata, true)['console'];
    expect($meta['etape'])->toBe('devis_envoye')
        ->and($meta['cloture_prevue'])->toBe('2026-12-15')
        ->and($meta['prochaine_action'])->toEqual(['type' => 'relance', 'date' => '2026-11-05'])
        ->and($meta['taches_ouvertes'])->toEqual(['nb' => 3, 'prochaine_le' => '2026-11-03'])
        ->and($meta['nb_emails_console'])->toBe(7)
        ->and($meta['nb_notes_console'])->toBe(4)
        ->and($meta['sante'])->toBe('a_surveiller')
        ->and($meta['questionnaire']['attendus'])->toBe(5)
        ->and($meta['questionnaire']['date_limite'])->toBe('2026-11-10')
        ->and($meta['questionnaire']['nb_reponses_equipe'])->toBe(2);

    // L'étape de la console ne touche ni le cycle de vie ni le motif d'archive.
    expect($fiche->lifecycle_stage)->toBe('qualifie')
        ->and($fiche->archive_reason)->toBeNull();

    $contact = DB::table('contacts')->where('company_id', $fiche->id)->first();
    expect(json_decode($contact->metadata, true)['role_decision'])->toBe('decideur');
});

test('fiche complète : clés absentes = aucun effet, ancienne version de la console toujours acceptée', function () {
    // Version ancienne de la console : aucune des nouvelles clés.
    dcsPost(dcsEvenement())->assertOk()->assertJsonPath('result.status', 'created');
    expect(json_decode(dcsFiche()->metadata, true)['console'])
        ->not->toHaveKeys(['etape', 'cloture_prevue', 'prochaine_action', 'taches_ouvertes', 'nb_emails_console', 'nb_notes_console', 'sante']);

    // Les valeurs reçues puis un message SANS ces clés : elles restent.
    dcsPost(dcsEvenement(['version' => 2] + dcsComplements()))->assertOk()->assertJsonPath('result.status', 'updated');
    dcsPost(dcsEvenement(['version' => 3, 'rue' => '13 rue des Tests']))->assertOk()->assertJsonPath('result.status', 'updated');

    $fiche = dcsFiche();
    $meta = json_decode($fiche->metadata, true)['console'];
    expect($fiche->address)->toBe('13 rue des Tests')
        ->and($meta['etape'])->toBe('devis_envoye')
        ->and($meta['sante'])->toBe('a_surveiller')
        ->and($meta['nb_notes_console'])->toBe(4)
        ->and($meta['prochaine_action']['type'])->toBe('relance')
        ->and($meta['questionnaire']['attendus'])->toBe(5)
        ->and($meta['questionnaire']['nb_reponses_equipe'])->toBe(2);

    $contact = DB::table('contacts')->where('company_id', $fiche->id)->first();
    expect(json_decode($contact->metadata, true)['role_decision'])->toBe('decideur');

    // `null` explicite = absence : rien n'est vidé.
    dcsPost(dcsEvenement(['version' => 4, 'etape' => null, 'sante' => null, 'prochaine_action' => null, 'taches_ouvertes' => null]))->assertOk();
    expect(json_decode(dcsFiche()->metadata, true)['console']['sante'])->toBe('a_surveiller');
});

test('fiche complète : une valeur hors liste fermée ou mal formée = 422 explicite pour cette clé', function (array $dossier, string $code) {
    dcsPost(dcsEvenement($dossier))->assertStatus(422)->assertJsonPath('error', $code);

    expect(DB::table('companies')->count())->toBe(0);
})->with([
    'étape inconnue' => [['etape' => 'perdu'], 'invalid_enum'],
    'santé inconnue' => [['sante' => 'excellente'], 'invalid_enum'],
    'type d action inconnu' => [['prochaine_action' => ['type' => 'telephoner', 'date' => '2026-11-05']], 'invalid_enum'],
    'action sans type' => [['prochaine_action' => ['date' => '2026-11-05']], 'missing_field'],
    'clé inconnue dans l action' => [['prochaine_action' => ['type' => 'rappel', 'objet' => 'x']], 'unknown_field'],
    'tâches sans nombre' => [['taches_ouvertes' => ['prochaine_le' => '2026-11-03']], 'missing_field'],
    'tâches négatives' => [['taches_ouvertes' => ['nb' => -1]], 'invalid_type'],
    'e-mails en texte' => [['nb_emails_console' => '7'], 'invalid_type'],
    'notes négatives' => [['nb_notes_console' => -2], 'invalid_type'],
    'clôture mal écrite' => [['cloture_prevue' => '15/12/2026'], 'invalid_date'],
    'clôture impossible' => [['cloture_prevue' => '2026-02-31'], 'invalid_date'],
    'attendus en texte' => [['questionnaire' => ['attendus' => 'cinq']], 'invalid_type'],
    'attendus à zéro' => [['questionnaire' => ['attendus' => 0]], 'invalid_type'],
    'attendus au-delà de 1000' => [['questionnaire' => ['attendus' => 1001]], 'invalid_type'],
    'attendus décimal' => [['questionnaire' => ['attendus' => 2.5]], 'invalid_type'],
    'attendus négatif' => [['questionnaire' => ['attendus' => -3]], 'invalid_type'],
    'attendus booléen' => [['questionnaire' => ['attendus' => true]], 'invalid_type'],
    'attendus chaîne numérique' => [['questionnaire' => ['attendus' => '5']], 'invalid_type'],
    'date limite fausse' => [['questionnaire' => ['date_limite' => 'bientôt']], 'invalid_date'],
    'rôle inconnu' => [['contacts' => [['ref' => 'c-1', 'nom' => 'ZZ', 'role_decision' => 'chef']]], 'invalid_enum'],
]);

test('fiche complète : les montants, notes et textes restent refusés', function (array $dossier) {
    dcsPost(dcsEvenement($dossier))->assertStatus(422)->assertJsonPath('error', 'forbidden_field');

    expect(DB::table('companies')->count())->toBe(0);
})->with([
    'notes' => [['notes' => 'texte de note']],
    'texte' => [['texte' => 'x']],
    'commentaire' => [['commentaire' => 'x']],
    'commentaires' => [['commentaires' => ['x']]],
    'montant dans l action' => [['prochaine_action' => ['type' => 'envoi_devis', 'montant' => 1200]]],
    'montant_ttc' => [['montant_ttc' => 1]],
    'prix dans les tâches' => [['taches_ouvertes' => ['nb' => 1, 'prix' => 10]]],
    'tarif' => [['tarif' => 10]],
    'total' => [['total' => 10]],
    'ca' => [['ca' => 10]],
    'devis' => [['devis' => 'D-1']],
    'outils' => [['outils' => ['x']]],
    'réponses' => [['reponses' => ['q' => 'oui']]],
    'réponse dans le questionnaire' => [['questionnaire' => ['attendus' => 2, 'reponse' => 'x']]],
    'note dans un contact' => [['contacts' => [['ref' => 'c-1', 'nom' => 'ZZ', 'role_decision' => 'decideur', 'notes' => 'x']]]],
]);

test('fiche complète : le contrat ne retire aucune clé interdite', function () {
    expect(DossierClient::CLES_INTERDITES)->toContain(
        'reponse',
        'reponses',
        'reponses_equipe',
        'reponses_dirigeant',
        'texte',
        'texte_libre',
        'commentaire',
        'commentaires',
        'notes',
        'donnees',
        'montant',
        'montant_ht',
        'montant_ttc',
        'prix',
        'tarif',
        'total',
        'ca',
        'chiffre_affaires',
        'numero_facture',
        'facture_numero',
        'numero_devis',
        'devis',
        'outils',
    );
});

test('fiche complète : un contact « parti » n est ni accepté ni créé', function (array $contact, string $code) {
    dcsPost(dcsEvenement(['contacts' => [$contact]]))->assertStatus(422)->assertJsonPath('error', $code);

    expect(DB::table('companies')->count())->toBe(0)
        ->and(DB::table('contacts')->count())->toBe(0);
})->with([
    'drapeau parti' => [['ref' => 'c-9', 'nom' => 'ZZ PARTI', 'parti' => true], 'unknown_field'],
    'statut parti' => [['ref' => 'c-9', 'nom' => 'ZZ PARTI', 'statut' => 'parti'], 'unknown_field'],
    'rôle parti' => [['ref' => 'c-9', 'nom' => 'ZZ PARTI', 'role_decision' => 'parti'], 'invalid_enum'],
]);

test('fiche complète : l étape « archive » pose seulement archive_reason, sans rien supprimer', function () {
    dcsPost(dcsEvenement())->assertOk();
    $avant = dcsFiche();

    dcsPost(dcsEvenement(['version' => 2, 'etape' => 'archive']))->assertOk()->assertJsonPath('result.status', 'updated');

    $fiche = dcsFiche();
    expect($fiche->archive_reason)->toBe('manual')
        ->and($fiche->deleted_at)->toBeNull()
        ->and($fiche->address)->toBe($avant->address)
        ->and($fiche->lifecycle_stage)->toBe($avant->lifecycle_stage)
        ->and(DB::table('contacts')->where('company_id', $fiche->id)->whereNull('deleted_at')->count())->toBe(1)
        ->and(json_decode($fiche->metadata, true)['console']['etape'])->toBe('archive');

    // Un motif déjà posé n'est jamais remplacé.
    DB::table('companies')->where('id', $fiche->id)->update(['archive_reason' => 'duplicate']);
    dcsPost(dcsEvenement(['version' => 3, 'etape' => 'archive']))->assertOk();
    expect(dcsFiche()->archive_reason)->toBe('duplicate');
});

test('fiche complète : l étape « client » de la console ne change jamais le cycle de vie, drapeau fermé', function () {
    dcsPost(dcsEvenement())->assertOk();

    dcsPost(dcsEvenement(['version' => 2, 'etape' => 'client']))->assertOk();
    expect(dcsFiche()->lifecycle_stage)->toBe('qualifie');

    // Facture émise mais drapeau FERMÉ (config de test) : toujours `qualifie`.
    dcsPost(dcsEvenement(['version' => 3, 'etape' => 'client', 'statut' => 'client', 'facture_emise' => true]))->assertOk();
    expect(dcsFiche()->lifecycle_stage)->toBe('qualifie');
});

test('questionnaire sans attendus (ancienne console) : accepté, la clé n\'est pas inventée', function () {
    dcsPost(dcsEvenement(['questionnaire' => ['envoye_le' => '2026-10-18T09:00:00+02:00', 'recu_le' => null, 'nb_reponses_equipe' => 1]]))
        ->assertOk()->assertJsonPath('result.status', 'created');

    $meta = json_decode(dcsFiche()->metadata, true)['console'];
    expect($meta['questionnaire']['nb_reponses_equipe'])->toBe(1)
        ->and($meta['questionnaire'])->not->toHaveKey('attendus');
});

test('questionnaire.attendus : les bornes 1 et 1000 sont acceptées', function (int $n) {
    dcsPost(dcsEvenement(['questionnaire' => ['nb_reponses_equipe' => 1, 'attendus' => $n]]))->assertOk();

    expect(json_decode(dcsFiche()->metadata, true)['console']['questionnaire']['attendus'])->toBe($n);
})->with([1, 1000]);
