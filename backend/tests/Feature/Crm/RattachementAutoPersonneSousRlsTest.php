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
