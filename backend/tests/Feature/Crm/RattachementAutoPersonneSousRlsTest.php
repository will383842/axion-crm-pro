<?php

use App\Crm\Ingest\IngestOutcome;
use App\Crm\Ingest\SiteSyncEvent;
use App\Crm\Ingest\SiteSyncIngestService;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * C1 (#784) REJOUÉ SOUS LE RÔLE DE PRODUCTION. `RattachementAutoPersonneTest`
 * tourne sous le propriétaire (la RLS n'y mord pas) : ici l'ingestion passe par
 * `pgsql_app`. Données semées et contrôlées par le propriétaire (patron de
 * `DossierClientSousRlsTest`).
 */
function rarProprio(): Connection
{
    return DB::connection('pgsql_owner');
}

function rarNettoyer(string $espace): void
{
    $owner = rarProprio();
    $owner->transaction(function () use ($owner, $espace): void {
        $owner->statement("SET LOCAL app.autoriser_suppression_protegee = 'on'");
        foreach (['activities', 'abonnements', 'personnes', 'contacts', 'company_tag', 'companies', 'tags'] as $table) {
            $owner->table($table)->where('workspace_id', $espace)->delete();
        }
        $owner->table('workspaces')->where('id', $espace)->delete();
    });
}

afterEach(function () {
    DB::connection('pgsql_app')->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
    DB::connection('pgsql_app')->disconnect();
    rarProprio()->disconnect();
});

test('TÉMOIN — la connexion de ce test est bien le rôle soumis à la RLS', function () {
    $role = DB::connection('pgsql_app')->selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
    expect($role->rolsuper)->toBeFalse()
        ->and($role->rolbypassrls)->toBeFalse();
});

test('sous axion_app : le dossier lie la personne et solde la demande en attente', function () {
    config([
        'crm.ingest.enabled' => true,
        'crm.ingest.dossier_enabled' => true,
        'crm.ingest.personnes_enabled' => false,
        'crm.ingest.demandes_personne_enabled' => true,
        'crm.ingest.dossier_client_stage' => false,
    ]);
    $owner = rarProprio();
    $espace = (string) Str::uuid();
    $owner->table('workspaces')->insert([
        'id' => $espace, 'slug' => 'zz-rar-' . substr($espace, 0, 8), 'name' => 'ZZ RAR RLS',
        'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    config(['crm.ingest.business_workspace' => $owner->table('workspaces')->where('id', $espace)->value('slug')]);

    $email = 'zz.rar.' . Str::lower(Str::random(8)) . '@example.invalid';
    $cle = hash('sha256', 'zz-c1|' . $email);
    $personne = $owner->table('personnes')->insertGetId([
        'workspace_id' => $espace, 'person_key' => $cle, 'email' => $email, 'email_hash' => hash('sha256', $email),
        'first_name' => 'Jeanne', 'last_name' => 'ZZ RAR', 'legal_basis' => 'precontractual',
        'premiere_source' => 'formulaire', 'premiere_source_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $demande = $owner->table('activities')->insertGetId([
        'workspace_id' => $espace, 'contact_id' => null, 'type' => 'form_submission', 'kind' => 'form_submission',
        'occurred_at' => now(), 'person_key' => $cle, 'external_ref' => 'site:event:' . Str::uuid(),
        'subject_type' => null, 'subject_id' => null, 'title' => 'ZZ demande',
        'payload' => json_encode(['pending_match' => ['email' => $email]]), 'created_at' => now(),
    ]);
    $siren = '9' . random_int(10000000, 99999999);
    $evenement = [
        'schema_version' => 1, 'event_id' => (string) Str::uuid(), 'event_type' => 'dossier_client_synchro',
        'occurred_at' => now()->subMinute()->toAtomString(), 'subject_ref' => 'site:client:' . Str::uuid(),
        'company' => ['siren' => $siren, 'name' => 'ZZ CLIENT RAR SAS', 'postcode' => '75011', 'city' => 'Paris'],
        'payload' => ['dossier' => ['version' => 1, 'statut' => 'prospect', 'facture_emise' => false, 'contacts' => [
            ['ref' => 'c-1', 'prenom' => 'Jeanne', 'nom' => 'ZZ RAR', 'email' => $email, 'person_key' => $cle],
        ]]],
    ];

    $precedente = DB::getDefaultConnection();
    try {
        DB::setDefaultConnection('pgsql_app');
        $issue = app(SiteSyncIngestService::class)->ingest(SiteSyncEvent::fromArray($evenement));
    } finally {
        DB::connection('pgsql_app')->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
        DB::setDefaultConnection($precedente);
    }

    try {
        $fiche = $owner->table('companies')->where('workspace_id', $espace)->where('siren', $siren)->first();
        $contact = $owner->table('contacts')->where('workspace_id', $espace)->where('company_id', $fiche->id)->first();
        $ligne = $owner->table('activities')->where('id', $demande)->first();
        $liee = $owner->table('personnes')->where('id', $personne)->first();
        expect($issue->status)->toBe(IngestOutcome::CREATED)
            ->and($liee->contact_id)->toBe($contact->id)
            ->and($liee->company_id)->toBe($fiche->id)
            ->and($ligne->subject_id)->toBe($fiche->id)
            ->and($ligne->contact_id)->toBe($contact->id)
            ->and(json_decode($ligne->payload, true)['arbitrage']['attached_by'])->toBe('automatique:dossier_client')
            ->and(json_decode($ligne->payload, true))->toHaveKey('pending_match');
    } finally {
        rarNettoyer($espace);
    }
});

test('C2 sous axion_app : la demande sans SIREN est rattachée à l entreprise unique du contact, fiche intacte', function () {
    config([
        'crm.ingest.enabled' => true,
        'crm.ingest.personnes_enabled' => false,
        'crm.ingest.demandes_personne_enabled' => true,
    ]);
    $owner = rarProprio();
    $espace = (string) Str::uuid();
    $slug = 'zz-rar2-' . substr($espace, 0, 8);
    $owner->table('workspaces')->insert([
        'id' => $espace, 'slug' => $slug, 'name' => 'ZZ RAR2 RLS',
        'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    config(['crm.ingest.business_workspace' => $slug]);

    $email = 'zz.rar2.' . Str::lower(Str::random(8)) . '@example.invalid';
    $cle = hash('sha256', 'zz-c2|' . $email);
    $fiche = $owner->table('companies')->insertGetId([
        'workspace_id' => $espace, 'siren' => '9' . random_int(10000000, 99999999), 'denomination' => 'ZZ FICHE RAR2',
        'postcode' => '75011', 'discovery_source' => 'site', 'quality_score' => 0, 'signals' => '{}', 'metadata' => '{}',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $contact = $owner->table('contacts')->insertGetId([
        'workspace_id' => $espace, 'company_id' => $fiche, 'first_name' => 'Jeanne', 'last_name' => 'ZZ RAR2',
        'person_key' => $cle, 'discovery_source' => 'site', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $evenement = [
        'schema_version' => 1, 'event_id' => (string) Str::uuid(), 'event_type' => 'form_submission', 'form_type' => 'audit',
        'occurred_at' => now()->subMinute()->toAtomString(), 'subject_ref' => 'site:submission:' . Str::uuid(),
        'person' => ['person_key' => $cle, 'email' => $email, 'first_name' => 'Zoe', 'last_name' => 'ZZ AUTRE', 'phone' => '0102030405'],
        'company' => ['name' => 'ZZ AUTRE NOM', 'postcode' => '99999'],
        'consent' => ['version' => 'v1-2026-05-24', 'at' => now()->subMinutes(2)->toAtomString(), 'text_ref' => 'unified-contact-form'],
        'tags' => [], 'payload' => ['page' => '/fr/contact'],
    ];

    $precedente = DB::getDefaultConnection();
    try {
        DB::setDefaultConnection('pgsql_app');
        $issue = app(SiteSyncIngestService::class)->ingest(SiteSyncEvent::fromArray($evenement));
    } finally {
        DB::connection('pgsql_app')->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
        DB::setDefaultConnection($precedente);
    }

    try {
        $ligne = $owner->table('activities')->where('id', $issue->activityId)->first();
        $personne = $owner->table('personnes')->where('workspace_id', $espace)->where('person_key', $cle)->first();
        $payload = json_decode($ligne->payload, true);
        expect($issue->status)->toBe(IngestOutcome::UPDATED)
            ->and($ligne->subject_type)->toBe('company')
            ->and($ligne->subject_id)->toBe($fiche)
            ->and($ligne->contact_id)->toBe($contact)
            ->and($payload['arbitrage']['attached_by'])->toBe('automatique:personne_connue')
            ->and($payload)->toHaveKey('pending_match')
            ->and($personne->contact_id)->toBe($contact)
            ->and($personne->company_id)->toBe($fiche)
            ->and($owner->table('companies')->where('id', $fiche)->value('denomination'))->toBe('ZZ FICHE RAR2')
            ->and($owner->table('companies')->where('id', $fiche)->value('postcode'))->toBe('75011')
            ->and($owner->table('contacts')->where('id', $contact)->value('first_name'))->toBe('Jeanne')
            ->and($owner->table('contacts')->where('id', $contact)->value('phone'))->toBeNull();
    } finally {
        rarNettoyer($espace);
    }
});
