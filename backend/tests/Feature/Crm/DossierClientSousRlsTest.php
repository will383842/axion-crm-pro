<?php

use App\Crm\Campagnes\GardeConsoleClient;
use App\Crm\FichesProtegees;
use App\Crm\Ingest\IngestOutcome;
use App\Crm\Ingest\SiteSyncEvent;
use App\Crm\Ingest\SiteSyncIngestService;
use App\Support\WorkspaceContext;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * SYNCHRO DU DOSSIER CLIENT, REJOUÉE SOUS LE RÔLE DE PRODUCTION (relecture
 * #341). `DossierClientSynchroTest` tourne sous `axion` (SUPERUSER,
 * BYPASSRLS) : la RLS n'y mord pas. Ici, l'ingestion (fiche, personne, tag
 * protégé, `company_tag`, timeline) et la garde de campagne
 * (`GardeConsoleClient`) passent par `pgsql_app` — le rôle `axion_app`.
 * Données semées et nettoyées par le propriétaire (patron de
 * `SiteGdprSousRlsTest`).
 */
function dcrProprio(): Connection
{
    return DB::connection('pgsql_owner');
}

function dcrSemer(): string
{
    $espace = (string) Str::uuid();
    dcrProprio()->table('workspaces')->insert([
        'id' => $espace, 'slug' => 'zz-dossier-rls-' . substr($espace, 0, 8), 'name' => 'ZZ dossier RLS',
        'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $espace;
}

function dcrNettoyer(string $espace): void
{
    $owner = dcrProprio();
    $owner->transaction(function () use ($owner, $espace): void {
        $owner->statement("SET LOCAL app.autoriser_suppression_protegee = 'on'");
        $owner->table('activities')->where('workspace_id', $espace)->delete();
        $owner->table('contacts')->where('workspace_id', $espace)->delete();
        $owner->table('company_tag')->where('workspace_id', $espace)->delete();
        $owner->table('companies')->where('workspace_id', $espace)->delete();
        $owner->table('tags')->where('workspace_id', $espace)->delete();
        $owner->table('workspaces')->where('id', $espace)->delete();
    });
}

/** @return array<string, mixed> */
function dcrEvenement(string $client, string $siren, string $email): array
{
    return [
        'schema_version' => 1,
        'event_id' => (string) Str::uuid(),
        'event_type' => 'dossier_client_synchro',
        'occurred_at' => now()->subMinute()->toAtomString(),
        'subject_ref' => 'site:client:' . $client,
        'company' => ['siren' => $siren, 'name' => 'ZZ CLIENT RLS SAS', 'postcode' => '75011', 'city' => 'Paris'],
        'payload' => ['dossier' => [
            'version' => 1,
            'statut' => 'prospect',
            'facture_emise' => false,
            'rue' => '1 rue RLS',
            'contacts' => [['ref' => 'c-1', 'prenom' => 'Zoé', 'nom' => 'ZZ RLS', 'email' => $email]],
        ]],
    ];
}

afterEach(function () {
    DB::connection('pgsql_app')->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
    DB::connection('pgsql_app')->disconnect();
    dcrProprio()->disconnect();
});

test('TÉMOIN — la connexion de ce test est bien le rôle soumis à la RLS', function () {
    $role = DB::connection('pgsql_app')->selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
    expect($role->rolsuper)->toBeFalse()
        ->and($role->rolbypassrls)->toBeFalse();
});

test('sous axion_app : la synchro crée fiche, personne, tag protégé et timeline, et la garde de campagne écarte la personne', function () {
    config([
        'crm.ingest.enabled' => true,
        'crm.ingest.dossier_enabled' => true,
        'crm.ingest.dossier_client_stage' => false,
    ]);
    $espace = dcrSemer();
    config(['crm.ingest.business_workspace' => dcrProprio()->table('workspaces')->where('id', $espace)->value('slug')]);
    $client = (string) Str::uuid();
    $siren = '9' . random_int(10000000, 99999999);
    $email = 'zz.rls.' . Str::lower(Str::random(8)) . '@client.example.invalid';

    $precedente = DB::getDefaultConnection();
    try {
        DB::setDefaultConnection('pgsql_app');
        $issue = app(SiteSyncIngestService::class)->ingest(SiteSyncEvent::fromArray(dcrEvenement($client, $siren, $email)));
        $exclues = WorkspaceContext::run($espace, fn (): array => GardeConsoleClient::exclues($espace, [$email, 'zz.temoin@autre.example.invalid']));
    } finally {
        DB::connection('pgsql_app')->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
        DB::setDefaultConnection($precedente);
    }

    try {
        $owner = dcrProprio();
        $fiche = $owner->table('companies')->where('workspace_id', $espace)->where('console_client_ref', 'site:client:' . $client)->first();
        expect($issue->status)->toBe(IngestOutcome::CREATED)
            ->and($fiche)->not->toBeNull()
            ->and($owner->table('contacts')->where('workspace_id', $espace)->where('company_id', $fiche->id)->count())->toBe(1)
            ->and($owner->table('company_tag')->join('tags', 'tags.id', '=', 'company_tag.tag_id')
                ->where('company_tag.company_id', $fiche->id)->where('tags.slug', FichesProtegees::TAG_CONSOLE_CLIENT)->exists())->toBeTrue()
            ->and($owner->table('activities')->where('workspace_id', $espace)->where('subject_id', $fiche->id)->where('kind', 'dossier_client_synchro')->count())->toBe(1)
            ->and(array_keys($exclues))->toBe([$email]);
    } finally {
        dcrNettoyer($espace);
    }
});
