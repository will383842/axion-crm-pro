<?php

use App\Crm\FichesProtegees;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * FICHES PROTÉGÉES, REJOUÉES SOUS LE RÔLE DE PRODUCTION.
 *
 * `FichesProtegeesTest` tourne sous `axion` (SUPERUSER, BYPASSRLS) : la RLS
 * n'y mord pas. En production, l'application parle en `axion_app`, et une
 * lecture de `company_tag` sans contexte d'espace rend ZÉRO ligne — une garde
 * qui lirait mal y deviendrait inerte en restant verte ici (cf. l'effacement
 * RGPD du 2026-09-25, `SiteGdprSousRlsTest`). Ce fichier sème par le
 * propriétaire et mesure sur `pgsql_app`.
 */
function protegeesRlsProprio(): Connection
{
    return DB::connection('pgsql_owner');
}

/** @return array{espace: string, protegee: int, temoin: int} */
function protegeesRlsSemer(): array
{
    $owner = protegeesRlsProprio();
    $espace = (string) Str::uuid();
    $owner->table('workspaces')->insert([
        'id' => $espace,
        'slug' => 'zz-protegees-rls-' . substr($espace, 0, 8),
        'name' => 'ZZ fiches protegees RLS',
        'settings' => '{}',
        'cost_cap_eur' => 100,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $commun = [
        'workspace_id' => $espace,
        'signals' => '{}',
        'metadata' => '{}',
        'quality_score' => 0,
        'relation_type' => 'prospect',
        'lifecycle_stage' => 'nouveau',
        'created_at' => now(),
        'updated_at' => now(),
    ];
    $protegee = (int) $owner->table('companies')->insertGetId($commun + [
        'siren' => null,
        'country_code' => 'FR',
        'foreign_id' => 'evt:zz-club-rls-' . substr($espace, 0, 8),
        'entity_nature' => 'association',
        'denomination' => 'ZZ club RLS',
    ]);
    $temoin = (int) $owner->table('companies')->insertGetId($commun + [
        'siren' => '9' . random_int(10000000, 99999999),
        'denomination' => 'ZZ temoin RLS SAS',
    ]);

    $tagId = (int) $owner->table('tags')->insertGetId([
        'workspace_id' => $espace,
        'slug' => FichesProtegees::TAGS[0],
        'name' => 'Collecte — organisateurs',
        'category' => 'intent',
        'kind' => 'auto',
        'rules' => '{}',
        'is_locked' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $owner->table('company_tag')->insert([
        'company_id' => $protegee,
        'tag_id' => $tagId,
        'workspace_id' => $espace,
        'assigned_at' => now(),
        'assigned_by' => 'auto-rule',
    ]);

    return ['espace' => $espace, 'protegee' => $protegee, 'temoin' => $temoin];
}

/** @param array{espace: string, protegee: int, temoin: int} $s */
function protegeesRlsNettoyer(array $s): void
{
    $owner = protegeesRlsProprio();
    $owner->transaction(function () use ($owner, $s): void {
        // La levée volontaire, exactement comme l'annulation d'un import.
        $owner->statement("SET LOCAL app.autoriser_suppression_protegee = 'on'");
        $owner->table('company_tag')->where('workspace_id', $s['espace'])->delete();
        $owner->table('companies')->where('workspace_id', $s['espace'])->delete();
        $owner->table('tags')->where('workspace_id', $s['espace'])->delete();
        $owner->table('workspaces')->where('id', $s['espace'])->delete();
    });
}

function protegeesRlsApp(string $espace): Connection
{
    $app = DB::connection('pgsql_app');
    $app->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $espace]);

    return $app;
}

afterEach(function () {
    DB::connection('pgsql_app')->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
    DB::connection('pgsql_app')->disconnect();
    protegeesRlsProprio()->disconnect();
});

test('TÉMOIN — la connexion de ce test est bien le rôle soumis à la RLS', function () {
    $role = DB::connection('pgsql_app')->selectOne(
        'SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user',
    );
    expect($role->rolsuper)->toBeFalse()
        ->and($role->rolbypassrls)->toBeFalse();
});

test('sous axion_app, le predicat reconnait la fiche protegee et pas le temoin', function () {
    $s = protegeesRlsSemer();
    $precedente = DB::getDefaultConnection();

    try {
        protegeesRlsApp($s['espace']);
        DB::setDefaultConnection('pgsql_app');

        expect(FichesProtegees::estProtegee($s['protegee']))->toBeTrue()
            ->and(FichesProtegees::estProtegee($s['temoin']))->toBeFalse();
    } finally {
        DB::setDefaultConnection($precedente);
        protegeesRlsNettoyer($s);
    }
});

test('sous axion_app, la base refuse la suppression physique de la fiche protegee et laisse passer le temoin', function () {
    $s = protegeesRlsSemer();

    try {
        $app = protegeesRlsApp($s['espace']);

        expect(fn () => $app->table('companies')->where('id', $s['protegee'])->delete())
            ->toThrow(QueryException::class, 'fiche_protegee');
        expect($app->table('companies')->where('id', $s['temoin'])->delete())->toBe(1);

        expect(protegeesRlsProprio()->table('companies')->where('id', $s['protegee'])->exists())->toBeTrue()
            ->and(protegeesRlsProprio()->table('companies')->where('id', $s['temoin'])->exists())->toBeFalse();
    } finally {
        protegeesRlsNettoyer($s);
    }
});
