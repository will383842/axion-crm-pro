<?php

/**
 * Lot G2-C (cahier #619, § 10) — migration SEULE `2026_12_08_000150` : colonnes `etape`, `raison_perte`, `titre` sur
 * `deals` et deux contraintes CHECK. Rien ne lit ces colonnes dans ce lot. Données fictives (example.test).
 */

use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const SA_FICHIER = '2026_12_08_000150_deals_etape_affaires.php';

function saMigration(): object
{
    return require database_path('migrations/' . SA_FICHIER);
}

function saEntreprise(string $espaceId, string $siren): int
{
    return (int) DB::table('companies')->insertGetId([
        'workspace_id' => $espaceId, 'siren' => $siren, 'denomination' => 'ZZ TEST ' . $siren,
        'discovery_source' => 'site', 'quality_score' => 0, 'signals' => '{}', 'metadata' => '{}',
        'relation_type' => 'prospect', 'lifecycle_stage' => 'nouveau',
        'legal_basis' => 'legitimate_interest_b2b', 'field_origins' => '{}',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function saAffaire(string $espaceId, int $entrepriseId, array $en = []): int
{
    return (int) DB::table('deals')->insertGetId(array_merge([
        'workspace_id' => $espaceId, 'company_id' => $entrepriseId, 'status' => 'open',
        'created_at' => now(), 'updated_at' => now(),
    ], $en));
}

function saColonnes(): array
{
    return collect(DB::select(
        "SELECT column_name, is_nullable, column_default FROM information_schema.columns
         WHERE table_name = 'deals' AND column_name IN ('etape','raison_perte','titre')",
    ))->keyBy('column_name')->all();
}

function saContraintes(): array
{
    return collect(DB::select(
        "SELECT conname, convalidated FROM pg_constraint
         WHERE conrelid = 'deals'::regclass AND conname IN ('deals_etape_valeur','deals_raison_perte_longueur')",
    ))->keyBy('conname')->all();
}

/** Joue $f sous le rôle applicatif (RLS réelle) ou sous le propriétaire, dans la transaction du test. */
function saJoue(string $mode, string $ws, callable $f): mixed
{
    DB::statement('SAVEPOINT sa');
    try {
        if ($mode === 'app') {
            WorkspaceContext::set($ws);
            DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
        }

        return $f();
    } finally {
        DB::statement('ROLLBACK TO SAVEPOINT sa');
    }
}

beforeEach(function () {
    $this->espace = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'ws-sa-a', 'name' => 'Espace A', 'settings' => []]);
    $this->entreprise = saEntreprise($this->espace->id, '900000701');
});

test('1. colonnes et contraintes présentes, contraintes validées', function () {
    $c = saColonnes();
    expect(array_keys($c))->toEqualCanonicalizing(['etape', 'raison_perte', 'titre'])
        ->and($c['etape']->is_nullable)->toBe('NO')
        ->and($c['etape']->column_default)->toContain('a_contacter')
        ->and($c['raison_perte']->is_nullable)->toBe('YES')
        ->and($c['titre']->is_nullable)->toBe('YES');

    $k = saContraintes();
    expect(array_keys($k))->toEqualCanonicalizing(['deals_etape_valeur', 'deals_raison_perte_longueur'])
        ->and((bool) $k['deals_etape_valeur']->convalidated)->toBeTrue()
        ->and((bool) $k['deals_raison_perte_longueur']->convalidated)->toBeTrue();
});

test('2. etape inconnue refusée (23514) sous rôle applicatif ET propriétaire, valeurs valides acceptées', function (string $mode) {
    $ws = $this->espace->id;
    $r = saJoue($mode, $ws, function () use ($ws) {
        $sortie = [];
        foreach (['inconnue', 'A_CONTACTER', ''] as $mauvaise) {
            DB::statement('SAVEPOINT s1');
            try {
                saAffaire($ws, $this->entreprise, ['etape' => $mauvaise]);
                $sortie[$mauvaise] = 'accepte';
            } catch (Throwable $e) {
                $sortie[$mauvaise] = (string) ($e->getPrevious()?->getCode() ?? $e->getCode());
            }
            DB::statement('ROLLBACK TO SAVEPOINT s1');
        }
        foreach (['a_contacter', 'contacte', 'repondu', 'rendez_vous', 'devis', 'gagne', 'perdu'] as $bonne) {
            $sortie[$bonne] = saAffaire($ws, $this->entreprise, ['etape' => $bonne]) > 0 ? 'ok' : 'ko';
        }
        DB::statement('SAVEPOINT s2');
        try {
            saAffaire($ws, $this->entreprise, ['raison_perte' => str_repeat('x', 501)]);
            $sortie['longue'] = 'accepte';
        } catch (Throwable $e) {
            $sortie['longue'] = (string) ($e->getPrevious()?->getCode() ?? $e->getCode());
        }
        DB::statement('ROLLBACK TO SAVEPOINT s2');
        $sortie['500'] = saAffaire($ws, $this->entreprise, ['raison_perte' => str_repeat('x', 500), 'titre' => 'Contrat']) > 0 ? 'ok' : 'ko';

        return $sortie;
    });

    expect($r['inconnue'])->toBe('23514')->and($r['A_CONTACTER'])->toBe('23514')->and($r[''])->toBe('23514')
        ->and($r['longue'])->toBe('23514')->and($r['500'])->toBe('ok')
        ->and(collect(['a_contacter', 'contacte', 'repondu', 'rendez_vous', 'devis', 'gagne', 'perdu'])->map(fn ($e) => $r[$e])->unique()->all())->toBe(['ok']);
})->with(['app', 'proprietaire']);

test('3. une ligne existante garde ses valeurs ; elle reçoit etape = a_contacter, titre et raison_perte nuls', function () {
    $m = saMigration();
    $m->down();                      // table sans les colonnes (aucune ligne : retour réel)
    $id = saAffaire($this->espace->id, $this->entreprise, ['value_eur' => 1234.50, 'probability' => 40]);
    $m->up();

    $l = DB::table('deals')->where('id', $id)->first();
    expect($l->etape)->toBe('a_contacter')->and($l->raison_perte)->toBeNull()->and($l->titre)->toBeNull()
        ->and((float) $l->value_eur)->toBe(1234.5)->and((int) $l->probability)->toBe(40)->and($l->status)->toBe('open')
        ->and(DB::table('deals')->count())->toBe(1);
});

test('4. up() rejouable : deux fois de suite, rien ne change', function () {
    $id = saAffaire($this->espace->id, $this->entreprise, ['etape' => 'devis', 'titre' => 'Offre', 'raison_perte' => null]);
    $avant = DB::table('deals')->where('id', $id)->first();
    $m = saMigration();
    $m->up();
    $m->up();

    expect(DB::table('deals')->where('id', $id)->first())->toEqual($avant)
        ->and(array_keys(saColonnes()))->toEqualCanonicalizing(['etape', 'raison_perte', 'titre'])
        ->and(array_keys(saContraintes()))->toHaveCount(2);
});

test('5. down() ne retire rien si une affaire a une étape avancée, un titre ou une raison', function (array $en) {
    $id = saAffaire($this->espace->id, $this->entreprise, $en);
    saMigration()->down();

    expect(array_keys(saColonnes()))->toHaveCount(3)->and(array_keys(saContraintes()))->toHaveCount(2)
        ->and(DB::table('deals')->where('id', $id)->exists())->toBeTrue();
})->with([
    'étape avancée' => [['etape' => 'devis']],
    'titre' => [['titre' => 'Offre']],
    'raison' => [['etape' => 'a_contacter', 'raison_perte' => 'prix']],
]);

test('6. down() sur une table sans donnée neuve retire contraintes et colonnes, puis up() les remet', function () {
    saAffaire($this->espace->id, $this->entreprise);
    $m = saMigration();
    $m->down();

    expect(saColonnes())->toBe([])->and(saContraintes())->toBe([])->and(DB::table('deals')->count())->toBe(1);
    $m->up();
    expect(saColonnes())->toHaveCount(3)->and(saContraintes())->toHaveCount(2);
});

test('7. le rôle applicatif lit et écrit les nouvelles colonnes, la RLS reste active', function () {
    $ws = $this->espace->id;
    $autre = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'ws-sa-b', 'name' => 'Espace B', 'settings' => []]);
    $idB = saAffaire($autre->id, saEntreprise($autre->id, '900000702'), ['titre' => 'Autre']);
    $idA = saAffaire($ws, $this->entreprise);

    $r = saJoue('app', $ws, function () use ($idA, $idB) {
        $maj = DB::table('deals')->where('id', $idA)->update(['etape' => 'perdu', 'raison_perte' => 'prix', 'titre' => 'Offre']);
        $vu = DB::table('deals')->pluck('id')->all();
        $autreMaj = DB::table('deals')->where('id', $idB)->update(['titre' => 'pirate']);

        return compact('maj', 'vu', 'autreMaj');
    });

    expect($r['maj'])->toBe(1)->and($r['vu'])->toBe([$idA])->and($r['autreMaj'])->toBe(0)
        ->and(DB::table('deals')->where('id', $idB)->value('titre'))->toBe('Autre');
    expect((bool) DB::selectOne("SELECT relrowsecurity FROM pg_class WHERE relname = 'deals'")->relrowsecurity)->toBeTrue();
});

test('8. la migration est additive : aucun DROP TABLE/COLUMN, DELETE ni TRUNCATE dans up()', function () {
    $src = (string) file_get_contents(database_path('migrations/' . SA_FICHIER));
    $up = substr($src, (int) strpos($src, 'function up'), (int) strpos($src, 'function down') - (int) strpos($src, 'function up'));

    expect($up)->not->toMatch('/DROP\s+(TABLE|COLUMN)|DELETE\s+FROM|TRUNCATE/i')
        ->and($up)->toContain("lock_timeout = '5s'");
});
