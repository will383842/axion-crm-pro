<?php

use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LienCampagneSequence;
use Tests\TestCase;

/*
 * Lot X1-L2b-A — colonne envoi_campagnes.filtre_origine (schéma seul). Chaque test de sécurité est rejoué SOUS LE RÔLE APPLICATIF
 * (SET LOCAL ROLE, WorkspaceContext) ET SOUS LE PROPRIÉTAIRE. Données fictives seulement.
 */
uses(TestCase::class, RefreshDatabase::class);

const SFC_MODES = ['app', 'proprio'];
const SFC_FONCTION = 'envoi_campagnes_filtre_origine_fige';

function sfcEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Sfc', 'slug' => 'sfc-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/** Campagne non reliée au statut demandé ; rend [id, version]. */
function sfcCampagne(string $ws, string $statut = 'brouillon'): array
{
    $id = (string) Str::uuid();
    DB::table('envoi_campagnes')->insert(['id' => $id, 'workspace_id' => $ws, 'nom' => 'Campagne fictive', 'cible' => json_encode(['type' => 'liste', 'reference' => 'liste-fictive', 'libelle' => 'Liste fictive']),
        'boites' => '{' . (string) Str::uuid() . '}', 'nb_etapes' => 2, 'saisie' => json_encode(['objet' => 'Bonjour']), 'cree_par' => 'responsable']);
    $v = 1;
    foreach (['brouillon' => [], 'programmee' => ['a_valider', 'programmee']][$statut] as $s) {
        DB::table('envoi_campagnes')->where('id', $id)->update(['statut' => $s, 'version' => ++$v]);
    }

    return [$id, $v];
}

function sfcEchec(Closure $f): ?string
{
    try {
        DB::transaction(function () use ($f) {
            $f();
            throw new RuntimeException('__annule__');
        });
    } catch (QueryException $e) {
        return (string) $e->getCode();
    } catch (RuntimeException $e) {
        return $e->getMessage() === '__annule__' ? null : 'autre:' . $e->getMessage();
    }

    return 'inattendu';
}

function sfcCode(string $mode, string $ws, Closure $f): ?string
{
    return sfcEchec(function () use ($mode, $ws, $f) {
        if ($mode === 'app') {
            WorkspaceContext::set($ws);
            DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
        }
        $f();
    });
}

/** UPDATE du filtre (version + 1, sauf demande contraire). */
function sfcMaj(string $c, int $v, ?string $filtre, array $plus = []): Closure
{
    return fn () => DB::table('envoi_campagnes')->where('id', $c)->update(['filtre_origine' => $filtre, 'version' => $v + 1] + $plus);
}

function sfcJson(array $reseaux): string
{
    return json_encode(['reseaux' => $reseaux]);
}

test('1 structure : colonne jsonb NULL, contrainte VALIDE, déclencheur, search_path, droits', function () {
    $col = DB::selectOne("SELECT data_type, is_nullable FROM information_schema.columns WHERE table_name = 'envoi_campagnes' AND column_name = 'filtre_origine'");
    expect($col)->not->toBeNull()->and($col->data_type)->toBe('jsonb')->and($col->is_nullable)->toBe('YES');
    $k = DB::selectOne("SELECT convalidated FROM pg_constraint WHERE conname = 'envoi_campagnes_filtre_origine_forme' AND conrelid = 'public.envoi_campagnes'::regclass");
    expect($k)->not->toBeNull()->and((bool) $k->convalidated)->toBeTrue();
    $t = DB::selectOne("SELECT 1 AS e FROM pg_trigger WHERE tgname = 'envoi_campagnes_filtre_origine_fige' AND tgrelid = 'public.envoi_campagnes'::regclass AND NOT tgisinternal");
    expect($t)->not->toBeNull();
    $p = DB::selectOne('SELECT proconfig, prosecdef FROM pg_proc WHERE proname = ?', [SFC_FONCTION]);
    expect($p)->not->toBeNull()->and((string) $p->proconfig)->toContain('search_path=pg_catalog, public, pg_temp')->and($p->prosecdef)->toBeFalse();
    $app = (string) config('database.connections.pgsql_app.username');
    foreach (['UPDATE', 'INSERT'] as $droit) {
        expect((bool) DB::selectOne('SELECT has_column_privilege(?, ?, ?, ?) AS ok', [$app, 'public.envoi_campagnes', 'filtre_origine', $droit])->ok)->toBeTrue($droit);
    }
});

test('2 formes acceptées (rôle applicatif ET propriétaire)', function () {
    foreach (SFC_MODES as $mode) {
        $ws = sfcEspace();
        [$c, $v] = sfcCampagne($ws);
        foreach ([null, sfcJson(['linkedin']), sfcJson(array_map(fn ($i) => "reseau-{$i}", range(1, 10)))] as $i => $f) {
            expect(sfcCode($mode, $ws, sfcMaj($c, $v, $f)))->toBeNull("{$mode} forme acceptée #{$i}");
        }
    }
});

test('3 formes refusées par le CHECK (23514)', function () {
    $refus = ['[]', '{}', sfcJson([]), sfcJson(array_map(fn ($i) => "r{$i}", range(1, 11))), sfcJson(['LinkedIn']), sfcJson(['a b']), sfcJson([1]),
        sfcJson(['x@example.test']), '{"reseaux":["x"],"canal":"y"}', sfcJson(array_map(fn ($i) => 'r' . str_repeat('x', 200) . $i, range(1, 10)))];
    foreach (SFC_MODES as $mode) {
        $ws = sfcEspace();
        [$c, $v] = sfcCampagne($ws);
        foreach ($refus as $i => $f) {
            expect(sfcCode($mode, $ws, sfcMaj($c, $v, $f)))->toBe('23514', "{$mode} forme refusée #{$i}");
        }
    }
});

test('4 gel une fois reliée : valeur → autre, valeur → NULL, NULL → valeur, et liaison + changement ensemble', function () {
    foreach (SFC_MODES as $mode) {
        $ws = sfcEspace();
        // non reliée : passe
        [$n, $vn] = sfcCampagne($ws, 'programmee');
        expect(sfcCode($mode, $ws, sfcMaj($n, $vn, sfcJson(['linkedin']))))->toBeNull("{$mode} non reliée");
        // reliée avec un filtre posé avant la liaison
        [$a, $va] = sfcCampagne($ws, 'programmee');
        DB::table('envoi_campagnes')->where('id', $a)->update(['filtre_origine' => sfcJson(['linkedin']), 'version' => $va + 1]);
        $r = LienCampagneSequence::poser(DB::connection(), $ws, $a, $va + 1, 2);
        expect(sfcCode($mode, $ws, sfcMaj($a, $r['version'], sfcJson(['facebook']))))->toBe('P0001', "{$mode} valeur → autre")
            ->and(sfcCode($mode, $ws, sfcMaj($a, $r['version'], null)))->toBe('P0001', "{$mode} valeur → NULL");
        // reliée sans filtre
        [$b, $vb] = sfcCampagne($ws, 'programmee');
        $rb = LienCampagneSequence::poser(DB::connection(), $ws, $b, $vb, 2);
        expect(sfcCode($mode, $ws, sfcMaj($b, $rb['version'], sfcJson(['linkedin']))))->toBe('P0001', "{$mode} NULL → valeur");
        // même UPDATE : pose de sequence_id ET changement du filtre
        [$d, $vd] = sfcCampagne($ws, 'programmee');
        $s = LienCampagneSequence::creerSequence(DB::connection(), $ws, $d, 2);
        expect(sfcCode($mode, $ws, sfcMaj($d, $vd, sfcJson(['linkedin']), ['sequence_id' => $s])))->toBe('P0001', "{$mode} liaison + filtre");
    }
});

test('5 une table temporaire homonyme ne change pas le gel (pg_temp en dernier)', function () {
    foreach (SFC_MODES as $mode) {
        $ws = sfcEspace();
        [$c, $v] = sfcCampagne($ws, 'programmee');
        $r = LienCampagneSequence::poser(DB::connection(), $ws, $c, $v, 2);
        expect(sfcCode($mode, $ws, function () use ($c, $r) {
            DB::statement('CREATE TEMP TABLE envoi_campagnes (id uuid, sequence_id uuid)');
            DB::statement('UPDATE public.envoi_campagnes SET filtre_origine = ?::jsonb, version = ? WHERE id = ?', [sfcJson(['linkedin']), $r['version'] + 1, $c]);
        }))->toBe('P0001', "{$mode} table temporaire");
    }
});

test('6 cloisonnement : le rôle applicatif ne touche pas le filtre d\'un autre espace', function () {
    $a = sfcEspace();
    $b = sfcEspace();
    [$c, $v] = sfcCampagne($b);
    $n = null;
    expect(sfcCode('app', $a, function () use ($c, $v, &$n) {
        $n = DB::table('envoi_campagnes')->where('id', $c)->update(['filtre_origine' => sfcJson(['linkedin']), 'version' => $v + 1]);
    }))->toBeNull();
    expect($n)->toBe(0);
    expect(DB::table('envoi_campagnes')->where('id', $c)->value('filtre_origine'))->toBeNull();
});

test('7 additif : aller-retour de la migration, mêmes lignes, filtre NULL partout', function () {
    $ws = sfcEspace();
    sfcCampagne($ws);
    [$c, $v] = sfcCampagne($ws, 'programmee');
    LienCampagneSequence::poser(DB::connection(), $ws, $c, $v, 2);
    $avant = DB::table('envoi_campagnes')->orderBy('id')->get(['id', 'workspace_id', 'nom', 'statut', 'version', 'sequence_id', 'cible', 'saisie'])->map(fn ($l) => (array) $l)->all();
    $migration = 'database/migrations/2026_12_10_000100_envoi_campagnes_filtre_origine.php';
    // down() inerte puis up() rejoué : rien ne bouge
    $m = require base_path($migration);
    $m->down();
    $m->up();
    $m->up();
    $apres = DB::table('envoi_campagnes')->orderBy('id')->get(['id', 'workspace_id', 'nom', 'statut', 'version', 'sequence_id', 'cible', 'saisie'])->map(fn ($l) => (array) $l)->all();
    expect($apres)->toBe($avant)->and(DB::table('envoi_campagnes')->whereNotNull('filtre_origine')->count())->toBe(0);
    expect(DB::selectOne("SELECT 1 AS e FROM information_schema.columns WHERE table_name = 'envoi_campagnes' AND column_name = 'filtre_origine'"))->not->toBeNull();
});

test('8 la garde existante tient : modifier le filtre sans version + 1 est refusé', function () {
    foreach (SFC_MODES as $mode) {
        $ws = sfcEspace();
        [$c] = sfcCampagne($ws);
        expect(sfcCode($mode, $ws, fn () => DB::table('envoi_campagnes')->where('id', $c)->update(['filtre_origine' => sfcJson(['linkedin'])])))->toBe('P0001', $mode);
    }
});
