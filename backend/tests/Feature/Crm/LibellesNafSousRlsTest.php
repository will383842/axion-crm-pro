<?php

/**
 * LIBELLÉS NAF SOUS LE RÔLE DE PRODUCTION (`axion_app`, RLS forcée) — lot N7.
 *
 * Les tables de référence NAF sont GLOBALES : aucune colonne `workspace_id`,
 * donc aucune policy RLS. Les autres tests tournent sous le propriétaire
 * (superuser) et ne verraient pas une table de référence rendue illisible au
 * rôle applicatif (RLS posée par erreur, droit SELECT manquant) : la liste
 * rendrait alors `naf_label = null` partout, sans erreur. Ici :
 *   - `naf_subclasses` n'est pas sous RLS et se lit sous `axion_app` ;
 *   - l'expression de l'API rend le libellé d'une fiche de l'espace courant ;
 *   - et RIEN pour une fiche d'un autre espace (la RLS de `companies` tient).
 *
 * Ce test COMMIT (connexions hors transaction de test) : il retire ce qu'il a
 * créé, et seulement cela. Fixtures FICTIVES (dépôt public).
 */

use App\Crm\Referentiels\LibellesNaf;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function lnrProprio(): Connection
{
    return DB::connection('pgsql_owner');
}

function lnrApp(): Connection
{
    return DB::connection('pgsql_app');
}

/**
 * Pose la chaîne J > 62 > 620 > 6201 > 6201Z si elle manque.
 *
 * @return list<array{0: string, 1: string}> [table, code] des lignes CRÉÉES ici
 */
function lnrReferentiel(): array
{
    $chaine = [
        ['naf_sections', ['code' => 'J', 'label' => 'Information et communication']],
        ['naf_divisions', ['code' => '62', 'section_code' => 'J', 'label' => 'Programmation, conseil et autres activités informatiques']],
        ['naf_groups', ['code' => '620', 'division_code' => '62', 'label' => 'Programmation, conseil et autres activités informatiques']],
        ['naf_classes', ['code' => '6201', 'group_code' => '620', 'label' => 'Programmation informatique']],
        ['naf_subclasses', ['code' => '6201Z', 'class_code' => '6201', 'label' => 'Programmation informatique']],
    ];
    $crees = [];
    foreach ($chaine as [$table, $ligne]) {
        if (lnrProprio()->table($table)->insertOrIgnore($ligne) === 1) {
            $crees[] = [$table, $ligne['code']];
        }
    }

    return $crees;
}

function lnrEspace(): string
{
    $id = (string) Str::uuid();
    lnrProprio()->table('workspaces')->insert([
        'id' => $id, 'slug' => 'zz-naf-rls-' . substr(str_replace('-', '', $id), 0, 8), 'name' => 'ZZ NAF RLS',
        'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function lnrFiche(string $espace): int
{
    return (int) lnrProprio()->table('companies')->insertGetId([
        'workspace_id' => $espace, 'siren' => (string) random_int(100000000, 999999999), 'denomination' => 'ZZ NAF RLS',
        'naf' => '62.01Z', 'discovery_source' => 'site', 'quality_score' => 0, 'signals' => '{}', 'metadata' => '{}',
        'relation_type' => 'prospect', 'lifecycle_stage' => 'nouveau', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function lnrContexte(?string $espace): void
{
    lnrApp()->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $espace ?? '']);
}

afterEach(function () {
    lnrContexte(null);
    lnrApp()->disconnect();
    lnrProprio()->disconnect();
});

test('sous axion_app : la table NAF est hors RLS, lisible, et le libellé d une fiche de l espace sort ; rien hors espace', function () {
    $crees = lnrReferentiel();
    $a = lnrEspace();
    $b = lnrEspace();

    try {
        $ficheA = lnrFiche($a);
        $ficheB = lnrFiche($b);

        $role = lnrApp()->selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        expect($role->rolsuper)->toBeFalse()->and($role->rolbypassrls)->toBeFalse();

        foreach (['naf_sections', 'naf_divisions', 'naf_groups', 'naf_classes', 'naf_subclasses'] as $table) {
            $rls = lnrProprio()->selectOne('SELECT relrowsecurity FROM pg_class WHERE oid = ?::regclass', [$table]);
            expect($rls->relrowsecurity)->toBeFalse("{$table} ne doit pas être sous RLS : c'est une table de référence globale");
        }

        // Lecture directe, même sans contexte d'espace.
        lnrContexte(null);
        expect(lnrApp()->table('naf_subclasses')->where('code', '6201Z')->value('label'))->toBe('Programmation informatique');

        // L'expression même de l'API, sous le rôle applicatif.
        $sql = 'SELECT id, ' . LibellesNaf::sqlLibelleSousClasse('companies') . ' AS naf_label FROM companies WHERE id IN (?, ?)';
        lnrContexte($a);
        $lignes = lnrApp()->select($sql, [$ficheA, $ficheB]);

        expect($lignes)->toHaveCount(1)
            ->and((int) $lignes[0]->id)->toBe($ficheA)
            ->and($lignes[0]->naf_label)->toBe('Programmation informatique');
    } finally {
        lnrProprio()->table('companies')->whereIn('workspace_id', [$a, $b])->delete();
        lnrProprio()->table('workspaces')->whereIn('id', [$a, $b])->delete();
        foreach (array_reverse($crees) as [$table, $code]) {
            lnrProprio()->table($table)->where('code', $code)->delete();
        }
    }
});
