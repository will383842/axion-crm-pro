<?php

use App\Services\Audit\AuditHashChain;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * L'IMPORT DES ÉVÉNEMENTS, REJOUÉ SOUS LE RÔLE DE PRODUCTION.
 *
 * `ImportEvenementsTest` tourne sous `axion` (SUPERUSER, BYPASSRLS). En
 * production, la commande parle en `axion_app`, et `events` /
 * `event_organizers` / `companies` sont en RLS forcée qui échoue FERMÉE : un
 * contexte d'espace mal posé rendrait l'import inerte en restant vert là-bas
 * (cf. l'effacement RGPD du 2026-09-25). Données semées et nettoyées par le
 * propriétaire.
 */
function evtRlsProprio(): Connection
{
    return DB::connection('pgsql_owner');
}

afterEach(function () {
    DB::connection('pgsql_app')->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
    DB::connection('pgsql_app')->disconnect();
    evtRlsProprio()->disconnect();
});

test('TÉMOIN — la connexion de ce test est bien le rôle soumis à la RLS', function () {
    $role = DB::connection('pgsql_app')->selectOne(
        'SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user',
    );
    expect($role->rolsuper)->toBeFalse()
        ->and($role->rolbypassrls)->toBeFalse();
});

test('sous axion_app, l import cree l evenement et le relie a son organisateur', function () {
    // La chaîne d'audit est partagée : une écriture validée ici fausserait
    // les suites qui la vérifient.
    $this->mock(AuditHashChain::class)->shouldReceive('record')->once()->andReturn(1);

    $owner = evtRlsProprio();
    $espace = (string) Str::uuid();
    $slug = 'zz-evt-rls-' . substr($espace, 0, 8);
    $owner->table('workspaces')->insert([
        'id' => $espace, 'slug' => $slug, 'name' => 'ZZ événements RLS', 'settings' => '{}',
        'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $club = (int) $owner->table('companies')->insertGetId([
        'workspace_id' => $espace, 'siren' => null, 'country_code' => 'FR',
        'foreign_id' => 'evt:zz-club-rls-' . substr($espace, 0, 8), 'entity_nature' => 'reseau',
        'denomination' => 'ZZ club RLS', 'signals' => '{}', 'metadata' => '{}', 'quality_score' => 0,
        'relation_type' => 'prospect', 'lifecycle_stage' => 'nouveau',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    config(['crm.ingest.business_workspace' => $slug]);

    $fichier = tempnam(sys_get_temp_dir(), 'zz-evt-rls-');
    file_put_contents($fichier, json_encode([
        'external_ref' => 'zz-rls-1',
        'nom' => 'ZZ Événement RLS',
        'type' => 'salon',
        'organisateurs' => [['country' => 'FR', 'foreign_id' => 'evt:zz-club-rls-' . substr($espace, 0, 8)]],
    ]) . "\n");

    $precedente = DB::getDefaultConnection();
    try {
        DB::setDefaultConnection('pgsql_app');
        $code = Artisan::call('crm:import-evenements', ['file' => $fichier]);
        DB::setDefaultConnection($precedente);

        $event = $owner->table('events')->where('workspace_id', $espace)->where('external_ref', 'zz-rls-1')->first();
        expect($code)->toBe(0)
            ->and($event)->not->toBeNull()
            ->and($owner->table('event_organizers')->where('event_id', $event->id)->where('company_id', $club)->exists())->toBeTrue();
    } finally {
        DB::setDefaultConnection($precedente);
        @unlink($fichier);
        $owner->table('event_organizers')->where('workspace_id', $espace)->delete();
        $owner->table('events')->where('workspace_id', $espace)->delete();
        $owner->table('companies')->where('workspace_id', $espace)->delete();
        $owner->table('workspaces')->where('id', $espace)->delete();
    }
});
