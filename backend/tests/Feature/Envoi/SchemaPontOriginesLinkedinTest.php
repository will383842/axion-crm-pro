<?php

use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LienCampagneSequence;
use Tests\TestCase;

/*
 * Lot PONT-LINKEDIN PL-M0A — colonne envoi_campagnes.filtre_origine_contenu (mots / guides) et registre d'opposition LinkedIn
 * (envoi_oppositions_linkedin). Schéma seul. Chaque test de sécurité est rejoué SOUS LE RÔLE APPLICATIF ET SOUS LE PROPRIÉTAIRE.
 * Données fictives seulement (profil d'exemple exemple-prenom-nom-0a1b2c).
 */
uses(TestCase::class, RefreshDatabase::class);

const SPL_MODES = ['app', 'proprio'];

function splEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Spl', 'slug' => 'spl-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function splCampagne(string $ws, bool $programmee = false): array
{
    $id = (string) Str::uuid();
    DB::table('envoi_campagnes')->insert(['id' => $id, 'workspace_id' => $ws, 'nom' => 'Campagne fictive', 'cible' => json_encode(['type' => 'liste', 'reference' => 'liste-fictive', 'libelle' => 'Liste fictive']),
        'boites' => '{' . (string) Str::uuid() . '}', 'nb_etapes' => 2, 'saisie' => json_encode(['objet' => 'Bonjour']), 'cree_par' => 'responsable']);
    $v = 1;
    foreach ($programmee ? ['a_valider', 'programmee'] : [] as $s) {
        DB::table('envoi_campagnes')->where('id', $id)->update(['statut' => $s, 'version' => ++$v]);
    }

    return [$id, $v];
}

function splEchec(Closure $f): ?string
{
    try {
        DB::transaction(function () use ($f) {
            $f();
            throw new RuntimeException('__annule__');
        });
    } catch (QueryException $e) {
        return (string) $e->getCode() . '|' . $e->getMessage();
    } catch (RuntimeException $e) {
        return $e->getMessage() === '__annule__' ? null : 'autre:' . $e->getMessage();
    }

    return 'inattendu';
}

/** Rend null (accepté, puis annulé) ou « SQLSTATE|message ». */
function splCode(string $mode, string $ws, Closure $f): ?string
{
    return splEchec(function () use ($mode, $ws, $f) {
        if ($mode === 'app') {
            WorkspaceContext::set($ws);
            DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
        }
        $f();
    });
}

function splSqlstate(?string $code): ?string
{
    return $code === null ? null : explode('|', $code)[0];
}

function splMaj(string $c, int $v, ?string $filtre, array $plus = []): Closure
{
    return fn () => DB::table('envoi_campagnes')->where('id', $c)->update(['filtre_origine_contenu' => $filtre, 'version' => $v + 1] + $plus);
}

function splLigne(string $ws, array $plus = []): array
{
    return $plus + ['id' => (string) Str::uuid(), 'workspace_id' => $ws, 'empreinte' => hash('sha256', 'exemple-prenom-nom-0a1b2c' . Str::random(6)), 'valeur_chiffree' => 'chiffre-fictif',
        'source' => 'manuel', 'motif' => 'opposition fictive', 'auteur' => 'responsable'];
}

test('1 structure : colonne, contrainte VALIDE, déclencheur, table, RLS forcée, droits', function () {
    $col = DB::selectOne("SELECT data_type, is_nullable FROM information_schema.columns WHERE table_name = 'envoi_campagnes' AND column_name = 'filtre_origine_contenu'");
    expect($col)->not->toBeNull()->and($col->data_type)->toBe('jsonb')->and($col->is_nullable)->toBe('YES');
    $k = DB::selectOne("SELECT convalidated FROM pg_constraint WHERE conname = 'envoi_campagnes_filtre_origine_contenu_forme' AND conrelid = 'public.envoi_campagnes'::regclass");
    expect($k)->not->toBeNull()->and((bool) $k->convalidated)->toBeTrue();
    expect(DB::selectOne("SELECT 1 AS e FROM pg_trigger WHERE tgname = 'envoi_campagnes_filtre_origine_contenu_fige' AND tgrelid = 'public.envoi_campagnes'::regclass AND NOT tgisinternal"))->not->toBeNull();
    $p = DB::selectOne("SELECT proconfig, prosecdef FROM pg_proc WHERE proname = 'envoi_campagnes_filtre_origine_contenu_fige'");
    expect($p)->not->toBeNull()->and((string) $p->proconfig)->toContain('search_path=pg_catalog, public, pg_temp')->and($p->prosecdef)->toBeFalse();
    $app = (string) config('database.connections.pgsql_app.username');
    foreach (['UPDATE', 'INSERT'] as $droit) {
        expect((bool) DB::selectOne('SELECT has_column_privilege(?, ?, ?, ?) AS ok', [$app, 'public.envoi_campagnes', 'filtre_origine_contenu', $droit])->ok)->toBeTrue($droit);
    }
    $t = DB::selectOne("SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = to_regclass('public.envoi_oppositions_linkedin')");
    expect($t)->not->toBeNull()->and((bool) $t->relrowsecurity)->toBeTrue()->and((bool) $t->relforcerowsecurity)->toBeTrue();
    $tbl = 'public.envoi_oppositions_linkedin';
    expect((bool) DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS ok', [$app, $tbl, 'SELECT'])->ok)->toBeTrue()
        ->and((bool) DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS ok', [$app, $tbl, 'INSERT'])->ok)->toBeTrue()
        ->and((bool) DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS ok', [$app, $tbl, 'UPDATE'])->ok)->toBeFalse()
        ->and((bool) DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS ok', [$app, $tbl, 'DELETE'])->ok)->toBeFalse();
});

test('2 formes acceptées (rôle applicatif ET propriétaire)', function () {
    $ok = [null, '{"mots":["a"]}', '{"guides":["g-1"]}', '{"mots":["a","b.c"],"guides":["g_1"]}', json_encode(['mots' => array_map(fn ($i) => "m{$i}", range(1, 10))])];
    foreach (SPL_MODES as $mode) {
        $ws = splEspace();
        [$c, $v] = splCampagne($ws);
        foreach ($ok as $i => $f) {
            expect(splCode($mode, $ws, splMaj($c, $v, $f)))->toBeNull("{$mode} acceptée #{$i}");
        }
    }
});

test('2b formes refusées par le CHECK (23514)', function () {
    $refus = ['{}', '[]', '{"inconnue":["a"]}', '{"mots":[]}', '{"guides":[]}', json_encode(['mots' => array_map(fn ($i) => "m{$i}", range(1, 11))]), '{"mots":["Majuscule"]}',
        '{"mots":["' . str_repeat('a', 65) . '"]}', '{"reseaux":["linkedin"]}', '{"mots":["a"],"reseaux":["x"]}', '{"mots":[1]}', '{"mots":"a"}', '{"mots":["x@example.test"]}'];
    foreach (SPL_MODES as $mode) {
        $ws = splEspace();
        [$c, $v] = splCampagne($ws);
        foreach ($refus as $i => $f) {
            expect(splSqlstate(splCode($mode, $ws, splMaj($c, $v, $f))))->toBe('23514', "{$mode} refusée #{$i}");
        }
    }
});

test('3 gel : campagne reliée ⇒ UPDATE refusé ; non reliée ⇒ permis', function () {
    foreach (SPL_MODES as $mode) {
        $ws = splEspace();
        [$n, $vn] = splCampagne($ws, true);
        expect(splCode($mode, $ws, splMaj($n, $vn, '{"mots":["a"]}')))->toBeNull("{$mode} non reliée");
        [$a, $va] = splCampagne($ws, true);
        DB::table('envoi_campagnes')->where('id', $a)->update(['filtre_origine_contenu' => '{"mots":["a"]}', 'version' => $va + 1]);
        $seq = (string) Str::uuid();
        $liaison = LienCampagneSequence::poser(DB::connection(), $ws, $a, $va + 1, 2);
        expect(splSqlstate(splCode($mode, $ws, splMaj($a, $liaison['version'], '{"mots":["b"]}'))))->toBe('P0001', "{$mode} valeur → autre")
            ->and(splSqlstate(splCode($mode, $ws, splMaj($a, $liaison['version'], null))))->toBe('P0001', "{$mode} valeur → NULL");
        [$b, $vb] = splCampagne($ws, true);
        $lb = LienCampagneSequence::poser(DB::connection(), $ws, $b, $vb, 2);
        expect(splSqlstate(splCode($mode, $ws, splMaj($b, $lb['version'], '{"guides":["g"]}'))))->toBe('P0001', "{$mode} NULL → valeur");
        unset($seq);
    }
});

test('4 registre LinkedIn : ajout seul (UPDATE, DELETE, TRUNCATE refusés), motif sans @, doublon refusé', function () {
    foreach (SPL_MODES as $mode) {
        $ws = splEspace();
        $ligne = splLigne($ws);
        expect(splCode($mode, $ws, fn () => DB::table('envoi_oppositions_linkedin')->insert($ligne)))->toBeNull("{$mode} insertion légale");
        DB::table('envoi_oppositions_linkedin')->insert($ligne);
        expect(splCode($mode, $ws, fn () => DB::table('envoi_oppositions_linkedin')->where('id', $ligne['id'])->update(['motif' => 'autre motif'])))->not->toBeNull("{$mode} UPDATE");
        expect(splCode($mode, $ws, fn () => DB::table('envoi_oppositions_linkedin')->where('id', $ligne['id'])->delete()))->not->toBeNull("{$mode} DELETE");
        expect(splCode($mode, $ws, fn () => DB::statement('TRUNCATE envoi_oppositions_linkedin')))->not->toBeNull("{$mode} TRUNCATE");
        if ($mode === 'proprio') {
            expect(splCode($mode, $ws, fn () => DB::table('envoi_oppositions_linkedin')->where('id', $ligne['id'])->update(['motif' => 'autre motif'])))->toContain('envoi_oppositions_linkedin :');
        }
        expect(splSqlstate(splCode($mode, $ws, fn () => DB::table('envoi_oppositions_linkedin')->insert(splLigne($ws, ['motif' => 'écrit à a@example.test'])))))->toBe('23514', "{$mode} motif avec @");
        expect(splSqlstate(splCode($mode, $ws, fn () => DB::table('envoi_oppositions_linkedin')->insert(splLigne($ws, ['empreinte' => $ligne['empreinte']])))))->toBe('23505', "{$mode} doublon");
        expect(splSqlstate(splCode($mode, $ws, fn () => DB::table('envoi_oppositions_linkedin')->insert(splLigne($ws, ['source' => 'autre'])))))->toBe('23514', "{$mode} source");
        expect(splSqlstate(splCode($mode, $ws, fn () => DB::table('envoi_oppositions_linkedin')->insert(splLigne($ws, ['empreinte' => 'pas-une-empreinte'])))))->toBe('23514', "{$mode} empreinte");
        expect(DB::table('envoi_oppositions_linkedin')->where('id', $ligne['id'])->count())->toBe(1);
    }
});

test('5 cloisonnement : l\'espace B ne voit rien de A et ne peut pas écrire pour A (rôle applicatif) ; filtre explicite en propriétaire', function () {
    $a = splEspace();
    $b = splEspace();
    $ligne = splLigne($a);
    DB::table('envoi_oppositions_linkedin')->insert($ligne);
    // propriétaire : le filtre workspace_id explicite suffit
    expect(DB::table('envoi_oppositions_linkedin')->where('workspace_id', $b)->count())->toBe(0)
        ->and(DB::table('envoi_oppositions_linkedin')->where('workspace_id', $a)->count())->toBe(1);
    // rôle applicatif sous RLS
    $vu = null;
    $refus = splCode('app', $b, function () use (&$vu, $a) {
        $vu = DB::table('envoi_oppositions_linkedin')->count();
        DB::table('envoi_oppositions_linkedin')->insert(splLigne($a));
    });
    expect($vu)->toBe(0)->and(splSqlstate($refus))->toBe('42501');
});

test('6 additif : down() puis up() rejoués, aucune ligne perdue ni modifiée', function () {
    $ws = splEspace();
    [$c, $v] = splCampagne($ws);
    DB::table('envoi_campagnes')->where('id', $c)->update(['filtre_origine_contenu' => '{"mots":["a"]}', 'version' => $v + 1]);
    $ligne = splLigne($ws);
    DB::table('envoi_oppositions_linkedin')->insert($ligne);
    $avant = [DB::table('envoi_campagnes')->count(), DB::table('envoi_oppositions_linkedin')->count()];
    foreach (['2026_12_09_000110_envoi_campagnes_filtre_origine_contenu.php', '2026_12_09_000120_envoi_oppositions_linkedin.php'] as $f) {
        $m = require database_path('migrations/' . $f);
        $m->down();
        $m->up();
    }
    expect([DB::table('envoi_campagnes')->count(), DB::table('envoi_oppositions_linkedin')->count()])->toBe($avant)
        ->and(DB::table('envoi_campagnes')->where('id', $c)->value('filtre_origine_contenu'))->toBe('{"mots": ["a"]}')
        ->and(DB::table('envoi_oppositions_linkedin')->where('id', $ligne['id'])->value('valeur_chiffree'))->toBe('chiffre-fictif');
});
