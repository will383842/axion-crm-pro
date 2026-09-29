<?php

/**
 * DOUBLONS, REJOUÉS SOUS LE RÔLE DE PRODUCTION (`axion_app`, RLS forcée).
 *
 * Les autres tests tournent sous le propriétaire (SUPERUSER, BYPASSRLS) : une
 * requête qui oublierait le contexte d'espace, ou un déclencheur qui lirait
 * mal, y resterait vert. Ici, la détection, la fusion et son annulation
 * passent par `pgsql_app` ; les effets sont lus par le propriétaire. Un
 * SECOND espace, avec les mêmes fiches, ne doit RIEN voir bouger.
 *
 * Ce test COMMIT (connexions hors transaction de test) : il nettoie tout.
 * Fixtures FICTIVES (dépôt public).
 */

use App\Crm\FichesProtegees;
use App\Services\Audit\AuditHashChain;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function drlsProprio(): Connection
{
    return DB::connection('pgsql_owner');
}

/** @return array{id: string, slug: string, garde: int, absorbee: int, contact: int} */
function drlsEspace(): array
{
    $owner = drlsProprio();
    $id = (string) Str::uuid();
    $marque = substr(str_replace('-', '', $id), 0, 8);
    $slug = 'zz-doublons-rls-' . $marque;
    $owner->table('workspaces')->insert([
        'id' => $id, 'slug' => $slug, 'name' => 'ZZ doublons RLS', 'settings' => '{}',
        'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $commun = ['workspace_id' => $id, 'signals' => '{}', 'metadata' => '{}', 'quality_score' => 0, 'relation_type' => 'prospect',
        'lifecycle_stage' => 'nouveau', 'denomination' => 'ZZ Rls Omega', 'postcode' => '69300',
        'website' => 'https://zz-rls.example.invalid', 'created_at' => now(), 'updated_at' => now()];
    $garde = (int) $owner->table('companies')->insertGetId($commun + ['siren' => '94' . random_int(1000000, 9999999), 'discovery_source' => 'insee']);
    $absorbee = (int) $owner->table('companies')->insertGetId($commun + [
        'siren' => null, 'country_code' => 'FR', 'foreign_id' => 'evt:zz-rls-' . $marque, 'discovery_source' => 'evenements-pro',
    ]);
    $tag = (int) $owner->table('tags')->insertGetId([
        'workspace_id' => $id, 'slug' => FichesProtegees::TAG_ORGANISATEURS, 'name' => 'Organisateurs', 'category' => 'intent',
        'kind' => 'auto', 'rules' => '{}', 'is_locked' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $owner->table('company_tag')->insert(['company_id' => $absorbee, 'tag_id' => $tag, 'workspace_id' => $id, 'assigned_at' => now(), 'assigned_by' => 'auto-rule']);
    $contact = (int) $owner->table('contacts')->insertGetId([
        'workspace_id' => $id, 'company_id' => $absorbee, 'first_name' => 'Zoe', 'last_name' => 'ZZRLS', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return ['id' => $id, 'slug' => $slug, 'garde' => $garde, 'absorbee' => $absorbee, 'contact' => $contact];
}

/** @param  array{id: string}  $e */
function drlsNettoyer(array $e): void
{
    $owner = drlsProprio();
    $owner->transaction(function () use ($owner, $e): void {
        $owner->statement("SET LOCAL app.autoriser_suppression_absorbee = 'on'");
        $owner->statement("SET LOCAL app.autoriser_suppression_protegee = 'on'");
        foreach (['fusions_fiches', 'duplicate_flags', 'adresses_partagees', 'company_tag', 'contacts'] as $table) {
            $owner->table($table)->where('workspace_id', $e['id'])->delete();
        }
        $owner->table('companies')->where('workspace_id', $e['id'])->delete();
        $owner->table('contacts_retires')->where('workspace_id', $e['id'])->delete();
        $owner->table('tags')->where('workspace_id', $e['id'])->delete();
        $owner->table('workspaces')->where('id', $e['id'])->delete();
    });
}

afterEach(function () {
    DB::connection('pgsql_app')->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
    DB::connection('pgsql_app')->disconnect();
    drlsProprio()->disconnect();
});

test('sous axion_app : détecter, fusionner, annuler — dans l espace visé seulement', function () {
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);
    $a = drlsEspace();
    $b = drlsEspace();
    $owner = drlsProprio();

    $precedente = DB::getDefaultConnection();
    try {
        DB::setDefaultConnection('pgsql_app');
        $role = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        expect($role->rolsuper)->toBeFalse()->and($role->rolbypassrls)->toBeFalse();

        $detecter = Artisan::call('crm:doublons:detecter', ['--workspace' => $a['slug']]);
        $fusionner = Artisan::call('crm:doublons:fusionner', ['--workspace' => $a['slug']]);
        $sortie = Artisan::output();
        DB::setDefaultConnection($precedente);

        $fusion = (int) $owner->table('fusions_fiches')->where('workspace_id', $a['id'])->value('id');
        $garde = $owner->table('companies')->where('id', $a['garde'])->first();
        expect($detecter)->toBe(0)
            ->and($fusionner)->toBe(0)
            ->and($fusion)->toBeGreaterThan(0)
            ->and($owner->table('companies')->where('id', $a['absorbee'])->value('deleted_at'))->not->toBeNull()
            ->and($owner->table('contacts')->where('id', $a['contact'])->value('company_id'))->toBe($a['garde'])
            // La protection a suivi (lue par le propriétaire).
            ->and($owner->table('company_tag')->where('company_id', $a['garde'])->count())->toBe(1)
            ->and($garde)->not->toBeNull()
            // L'autre espace : rien de détecté, rien de fusionné.
            ->and($owner->table('duplicate_flags')->where('workspace_id', $b['id'])->count())->toBe(0)
            ->and($owner->table('companies')->where('id', $b['absorbee'])->value('deleted_at'))->toBeNull()
            ->and($owner->table('contacts')->where('id', $b['contact'])->value('company_id'))->toBe($b['absorbee']);

        // Le verrou de la base tient aussi sous le rôle applicatif.
        $refus = null;
        DB::setDefaultConnection('pgsql_app');
        DB::connection('pgsql_app')->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $a['id']]);
        try {
            DB::connection('pgsql_app')->table('companies')->where('id', $a['absorbee'])->delete();
        } catch (Throwable $e) {
            $refus = $e->getMessage();
        }
        expect($refus)->toContain('fiche_absorbee');

        $annuler = Artisan::call('crm:doublons:fusionner', ['--workspace' => $a['slug'], '--annuler' => (string) $fusion]);
        DB::setDefaultConnection($precedente);

        expect($annuler)->toBe(0)
            ->and($owner->table('companies')->where('id', $a['absorbee'])->value('deleted_at'))->toBeNull()
            ->and($owner->table('contacts')->where('id', $a['contact'])->value('company_id'))->toBe($a['absorbee'])
            ->and($owner->table('company_tag')->where('company_id', $a['absorbee'])->count())->toBe(1)
            ->and($sortie)->toContain('fusionnees');
    } finally {
        DB::setDefaultConnection($precedente);
        drlsNettoyer($a);
        drlsNettoyer($b);
    }
});
