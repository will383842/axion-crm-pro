<?php

use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Lot PONT-LINKEDIN PL-M0B — tables pl_prospects, pl_journal, pl_plafonds, pl_arret. Schéma seul.
 * Chaque test de sécurité est rejoué SOUS LE RÔLE APPLICATIF ET SOUS LE PROPRIÉTAIRE.
 * Données fictives seulement (profil d'exemple exemple-prenom-nom-0a1b2c).
 */
uses(TestCase::class, RefreshDatabase::class);

const SPLI_MODES = ['app', 'proprio'];
const SPLI_TABLES = ['pl_prospects', 'pl_journal', 'pl_plafonds', 'pl_arret'];

function spliEspace(): array
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Spli', 'slug' => 'spli-'.substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
    $u = (string) Str::uuid();
    DB::table('users')->insert(['id' => $u, 'email' => 'u-'.substr($u, 0, 8).'@example.test', 'name' => 'Utilisateur fictif']);

    return [$id, $u];
}

function spliProspect(string $ws, string $u, array $plus = []): array
{
    return $plus + ['id' => (string) Str::uuid(), 'workspace_id' => $ws, 'expediteur_user_id' => $u, 'linkedin_slug' => 'exemple-prenom-nom-'.Str::lower(Str::random(6)),
        'source' => 'recherche', 'source_le' => '2026-12-09'];
}

function spliJournal(string $ws, array $plus = []): array
{
    return $plus + ['workspace_id' => $ws, 'evenement' => 'ajout', 'origine' => 'humain', 'auteur' => 'responsable', 'cle_idempotence' => 'cle-'.Str::random(20)];
}

function spliEchec(Closure $f): ?string
{
    try {
        DB::transaction(function () use ($f) {
            $f();
            throw new RuntimeException('__annule__');
        });
    } catch (QueryException $e) {
        return (string) $e->getCode().'|'.$e->getMessage();
    } catch (RuntimeException $e) {
        return $e->getMessage() === '__annule__' ? null : 'autre:'.$e->getMessage();
    }

    return 'inattendu';
}

/** Rend null (accepté, puis annulé) ou « SQLSTATE|message ». */
function spliCode(string $mode, string $ws, Closure $f): ?string
{
    return spliEchec(function () use ($mode, $ws, $f) {
        if ($mode === 'app') {
            WorkspaceContext::set($ws);
            DB::statement('SET LOCAL ROLE "'.str_replace('"', '""', (string) config('database.connections.pgsql_app.username')).'"');
        }
        $f();
    });
}

function spliSqlstate(?string $code): ?string
{
    return $code === null ? null : explode('|', $code)[0];
}

test('1 structure : tables, RLS forcée, droits, déclencheurs, unicités', function () {
    $app = (string) config('database.connections.pgsql_app.username');
    foreach (SPLI_TABLES as $t) {
        $r = DB::selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = to_regclass(?)', ['public.'.$t]);
        expect($r)->not->toBeNull("{$t} absente")->and((bool) $r->relrowsecurity)->toBeTrue($t)->and((bool) $r->relforcerowsecurity)->toBeTrue($t);
        $nn = DB::selectOne("SELECT is_nullable FROM information_schema.columns WHERE table_name = ? AND column_name = 'workspace_id'", [$t]);
        expect($nn->is_nullable)->toBe('NO');
        foreach (['SELECT' => true, 'INSERT' => true, 'DELETE' => false, 'TRUNCATE' => false] as $droit => $attendu) {
            expect((bool) DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS ok', [$app, 'public.'.$t, $droit])->ok)->toBe($attendu, "{$t} {$droit}");
        }
        // UPDATE au niveau table : jamais.
        expect((bool) DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS ok', [$app, 'public.'.$t, 'UPDATE'])->ok)->toBeFalse("{$t} UPDATE table");
    }
    foreach (['etat', 'score', 'variante', 'relance_le', 'contact_id', 'maj_le'] as $col) {
        expect((bool) DB::selectOne('SELECT has_column_privilege(?, ?, ?, ?) AS ok', [$app, 'public.pl_prospects', $col, 'UPDATE'])->ok)->toBeTrue($col);
    }
    foreach (['linkedin_slug', 'workspace_id', 'expediteur_user_id', 'source', 'id'] as $col) {
        expect((bool) DB::selectOne('SELECT has_column_privilege(?, ?, ?, ?) AS ok', [$app, 'public.pl_prospects', $col, 'UPDATE'])->ok)->toBeFalse($col);
    }
    foreach (['pl_journal_une_cle_par_espace', 'pl_prospects_un_profil_par_expediteur'] as $c) {
        expect(DB::selectOne('SELECT 1 AS e FROM pg_constraint WHERE conname = ?', [$c]))->not->toBeNull($c);
    }
    foreach (['pl_prospects_ecarte_definitif', 'pl_prospects_pas_de_suppression', 'pl_journal_ajout_seul', 'pl_plafonds_ajout_seul', 'pl_arret_ajout_seul'] as $trg) {
        expect(DB::selectOne('SELECT 1 AS e FROM pg_trigger WHERE tgname = ? AND NOT tgisinternal', [$trg]))->not->toBeNull($trg);
    }
});

test('2 prospect : ajout légal, valeurs refusées (23514), doublon (23505)', function () {
    foreach (SPLI_MODES as $mode) {
        [$ws, $u] = spliEspace();
        expect(spliCode($mode, $ws, fn () => DB::table('pl_prospects')->insert(spliProspect($ws, $u))))->toBeNull("{$mode} légal");
        foreach (['linkedin_slug' => 'Majuscule', 'source' => 'autre', 'score' => 101, 'etat' => 'inconnu', 'siren' => '12345', 'source_ref' => 'a@example.test'] as $col => $val) {
            expect(spliSqlstate(spliCode($mode, $ws, fn () => DB::table('pl_prospects')->insert(spliProspect($ws, $u, [$col => $val])))))->toBe('23514', "{$mode} {$col}");
        }
        $p = spliProspect($ws, $u);
        DB::table('pl_prospects')->insert($p);
        expect(spliSqlstate(spliCode($mode, $ws, fn () => DB::table('pl_prospects')->insert(spliProspect($ws, $u, ['linkedin_slug' => $p['linkedin_slug']])))))->toBe('23505', "{$mode} doublon");
    }
});

test('3 DELETE et TRUNCATE refusés sur les quatre tables', function () {
    foreach (SPLI_MODES as $mode) {
        [$ws, $u] = spliEspace();
        $p = spliProspect($ws, $u);
        DB::table('pl_prospects')->insert($p);
        DB::table('pl_journal')->insert(spliJournal($ws));
        DB::table('pl_plafonds')->insert(['workspace_id' => $ws, 'expediteur_user_id' => $u, 'auteur' => 'responsable']);
        DB::table('pl_arret')->insert(['workspace_id' => $ws, 'geste' => 'arret', 'motif' => 'arrêt de test fictif', 'auteur' => 'responsable', 'declencheur' => 'humain']);
        foreach (SPLI_TABLES as $t) {
            expect(spliCode($mode, $ws, fn () => DB::table($t)->where('workspace_id', $ws)->delete()))->not->toBeNull("{$mode} DELETE {$t}");
            expect(spliCode($mode, $ws, fn () => DB::statement("TRUNCATE {$t}")))->not->toBeNull("{$mode} TRUNCATE {$t}");
            expect(DB::table($t)->where('workspace_id', $ws)->count())->toBe(1, "{$t} intacte");
        }
        if ($mode === 'proprio') {
            expect(spliCode($mode, $ws, fn () => DB::table('pl_journal')->where('workspace_id', $ws)->delete()))->toContain('pl_journal :');
        }
    }
});

test('4 prospect écarté : définitif ; slug, espace, expéditeur jamais modifiés', function () {
    foreach (SPLI_MODES as $mode) {
        [$ws, $u] = spliEspace();
        $a = spliProspect($ws, $u, ['etat' => 'ecarte']);
        $b = spliProspect($ws, $u);
        DB::table('pl_prospects')->insert([$a]);
        DB::table('pl_prospects')->insert([$b]);
        // écarté : etat et autres colonnes figés, sauf maj_le
        expect(spliCode($mode, $ws, fn () => DB::table('pl_prospects')->where('id', $a['id'])->update(['etat' => 'invite'])))->not->toBeNull("{$mode} ecarte → invite");
        expect(spliCode($mode, $ws, fn () => DB::table('pl_prospects')->where('id', $a['id'])->update(['score' => 50])))->not->toBeNull("{$mode} ecarte score");
        expect(spliCode($mode, $ws, fn () => DB::table('pl_prospects')->where('id', $a['id'])->update(['maj_le' => now()])))->toBeNull("{$mode} ecarte maj_le");
        // non écarté : permis, y compris vers écarté
        expect(spliCode($mode, $ws, fn () => DB::table('pl_prospects')->where('id', $b['id'])->update(['etat' => 'qualifie', 'score' => 40])))->toBeNull("{$mode} candidat → qualifie");
        expect(spliCode($mode, $ws, fn () => DB::table('pl_prospects')->where('id', $b['id'])->update(['etat' => 'ecarte'])))->toBeNull("{$mode} → ecarte");
        // slug, espace, expéditeur (propriétaire seulement : le rôle applicatif a déjà 42501)
        if ($mode === 'proprio') {
            [$ws2, $u2] = spliEspace();
            expect(spliCode($mode, $ws, fn () => DB::table('pl_prospects')->where('id', $b['id'])->update(['linkedin_slug' => 'autre-slug-fictif'])))->not->toBeNull('slug')
                ->and(spliCode($mode, $ws, fn () => DB::table('pl_prospects')->where('id', $b['id'])->update(['workspace_id' => $ws2])))->not->toBeNull('espace')
                ->and(spliCode($mode, $ws, fn () => DB::table('pl_prospects')->where('id', $b['id'])->update(['expediteur_user_id' => $u2])))->not->toBeNull('expéditeur');
        }
        expect(DB::table('pl_prospects')->where('id', $a['id'])->value('etat'))->toBe('ecarte');
    }
});

test('5 rôle applicatif : UPDATE d\'une colonne non listée ⇒ 42501', function () {
    [$ws, $u] = spliEspace();
    $p = spliProspect($ws, $u);
    DB::table('pl_prospects')->insert($p);
    expect(spliSqlstate(spliCode('app', $ws, fn () => DB::table('pl_prospects')->where('id', $p['id'])->update(['linkedin_slug' => 'autre-slug-fictif']))))->toBe('42501')
        ->and(spliSqlstate(spliCode('app', $ws, fn () => DB::table('pl_prospects')->where('id', $p['id'])->update(['source' => 'evenement']))))->toBe('42501')
        ->and(spliCode('app', $ws, fn () => DB::table('pl_prospects')->where('id', $p['id'])->update(['score' => 10, 'maj_le' => now()])))->toBeNull();
});

test('6 journal : ajout seul, clé d\'idempotence unique, sans adresse, listes fermées', function () {
    foreach (SPLI_MODES as $mode) {
        [$ws, $u] = spliEspace();
        $p = spliProspect($ws, $u);
        DB::table('pl_prospects')->insert($p);
        $l = spliJournal($ws, ['prospect_id' => $p['id'], 'details' => '{"note":"ok"}']);
        expect(spliCode($mode, $ws, fn () => DB::table('pl_journal')->insert($l)))->toBeNull("{$mode} légal");
        DB::table('pl_journal')->insert($l);
        expect(spliSqlstate(spliCode($mode, $ws, fn () => DB::table('pl_journal')->insert(spliJournal($ws, ['cle_idempotence' => $l['cle_idempotence']])))))->toBe('23505', "{$mode} clé en double")
            ->and(spliSqlstate(spliCode($mode, $ws, fn () => DB::table('pl_journal')->insert(spliJournal($ws, ['details' => '{"a":"x@example.test"}'])))))->toBe('23514', "{$mode} adresse")
            ->and(spliSqlstate(spliCode($mode, $ws, fn () => DB::table('pl_journal')->insert(spliJournal($ws, ['details' => json_encode(['n' => str_repeat('a', 2100)])])))))->toBe('23514', "{$mode} taille")
            ->and(spliSqlstate(spliCode($mode, $ws, fn () => DB::table('pl_journal')->insert(spliJournal($ws, ['cle_idempotence' => 'courte'])))))->toBe('23514', "{$mode} clé courte")
            ->and(spliSqlstate(spliCode($mode, $ws, fn () => DB::table('pl_journal')->insert(spliJournal($ws, ['evenement' => 'inconnu'])))))->toBe('23514', "{$mode} événement")
            ->and(spliSqlstate(spliCode($mode, $ws, fn () => DB::table('pl_journal')->insert(spliJournal($ws, ['origine' => 'robot'])))))->toBe('23514', "{$mode} origine")
            ->and(spliSqlstate(spliCode($mode, $ws, fn () => DB::table('pl_journal')->insert(spliJournal($ws, ['prospect_id' => (string) Str::uuid()])))))->toBe('23503', "{$mode} prospect inconnu");
        foreach (['invitation_faite', 'opposition', 'controle_refuse', 'arret', 'reprise', 'plafonds', 'alerte'] as $e) {
            expect(spliCode($mode, $ws, fn () => DB::table('pl_journal')->insert(spliJournal($ws, ['evenement' => $e]))))->toBeNull("{$mode} {$e}");
        }
        expect(spliCode($mode, $ws, fn () => DB::table('pl_journal')->where('workspace_id', $ws)->update(['auteur' => 'x'])))->not->toBeNull("{$mode} UPDATE");
        if ($mode === 'proprio') {
            expect(spliCode($mode, $ws, fn () => DB::table('pl_journal')->where('workspace_id', $ws)->update(['auteur' => 'x'])))->toContain('pl_journal :');
        }
    }
});

test('7 plafonds et arrêt : bornes, ajout seul, dernière ligne fait foi', function () {
    foreach (SPLI_MODES as $mode) {
        [$ws, $u] = spliEspace();
        $base = ['workspace_id' => $ws, 'expediteur_user_id' => $u, 'auteur' => 'responsable'];
        expect(spliCode($mode, $ws, fn () => DB::table('pl_plafonds')->insert($base + ['invitations_jour' => 25, 'invitations_semaine' => 100, 'messages_jour' => 40, 'notes_mois' => 5])))->toBeNull("{$mode} bornes hautes");
        foreach (['invitations_jour' => 26, 'invitations_semaine' => 101, 'messages_jour' => 41, 'notes_mois' => 6, 'invitations_jour ' => -1] as $col => $val) {
            expect(spliSqlstate(spliCode($mode, $ws, fn () => DB::table('pl_plafonds')->insert($base + [trim($col) => $val]))))->toBe('23514', "{$mode} {$col}={$val}");
        }
        $arret = ['workspace_id' => $ws, 'geste' => 'arret', 'motif' => 'arrêt de test fictif', 'auteur' => 'responsable', 'declencheur' => 'humain'];
        expect(spliCode($mode, $ws, fn () => DB::table('pl_arret')->insert($arret)))->toBeNull("{$mode} arrêt légal")
            ->and(spliCode($mode, $ws, fn () => DB::table('pl_arret')->insert(['expediteur_user_id' => $u] + $arret)))->toBeNull("{$mode} arrêt ciblé")
            ->and(spliSqlstate(spliCode($mode, $ws, fn () => DB::table('pl_arret')->insert(['geste' => 'pause'] + $arret))))->toBe('23514', "{$mode} geste")
            ->and(spliSqlstate(spliCode($mode, $ws, fn () => DB::table('pl_arret')->insert(['motif' => 'court'] + $arret))))->toBe('23514', "{$mode} motif court")
            ->and(spliSqlstate(spliCode($mode, $ws, fn () => DB::table('pl_arret')->insert(['motif' => 'écrit à a@example.test ok'] + $arret))))->toBe('23514', "{$mode} motif @")
            ->and(spliSqlstate(spliCode($mode, $ws, fn () => DB::table('pl_arret')->insert(['declencheur' => 'autre'] + $arret))))->toBe('23514', "{$mode} déclencheur");
        DB::table('pl_plafonds')->insert($base);
        expect(spliCode($mode, $ws, fn () => DB::table('pl_plafonds')->where('workspace_id', $ws)->update(['messages_jour' => 1])))->not->toBeNull("{$mode} UPDATE plafonds")
            ->and(spliCode($mode, $ws, fn () => DB::table('pl_arret')->where('workspace_id', $ws)->update(['geste' => 'reprise'])))->not->toBeNull("{$mode} UPDATE arret");
        if ($mode === 'proprio') {
            expect(spliCode($mode, $ws, fn () => DB::table('pl_plafonds')->where('workspace_id', $ws)->update(['messages_jour' => 1])))->toContain('pl_plafonds :')
                ->and(spliCode($mode, $ws, fn () => DB::table('pl_arret')->where('workspace_id', $ws)->update(['geste' => 'reprise'])))->toContain('pl_arret :');
        }
    }
});

test('8 cloisonnement : l\'espace B ne voit rien de A et ne peut pas écrire pour A (rôle applicatif) ; filtre explicite en propriétaire', function () {
    [$a, $ua] = spliEspace();
    [$b] = spliEspace();
    DB::table('pl_prospects')->insert(spliProspect($a, $ua));
    DB::table('pl_journal')->insert(spliJournal($a));
    DB::table('pl_plafonds')->insert(['workspace_id' => $a, 'expediteur_user_id' => $ua, 'auteur' => 'responsable']);
    DB::table('pl_arret')->insert(['workspace_id' => $a, 'geste' => 'arret', 'motif' => 'arrêt de test fictif', 'auteur' => 'responsable', 'declencheur' => 'humain']);
    foreach (SPLI_TABLES as $t) {
        expect(DB::table($t)->where('workspace_id', $b)->count())->toBe(0, "{$t} propriétaire B")
            ->and(DB::table($t)->where('workspace_id', $a)->count())->toBe(1, "{$t} propriétaire A");
        $vu = null;
        spliCode('app', $b, function () use (&$vu, $t) {
            $vu = DB::table($t)->count();
        });
        expect($vu)->toBe(0, "{$t} RLS B");
    }
    $refus = spliCode('app', $b, fn () => DB::table('pl_prospects')->insert(spliProspect($a, $ua)));
    expect(spliSqlstate($refus))->toBe('42501');
    $refusJ = spliCode('app', $b, fn () => DB::table('pl_journal')->insert(spliJournal($a)));
    expect(spliSqlstate($refusJ))->toBe('42501');
});

test('9 additif : down() puis up() rejoués, aucune ligne perdue ni modifiée', function () {
    [$ws, $u] = spliEspace();
    $p = spliProspect($ws, $u, ['etat' => 'ecarte']);
    DB::table('pl_prospects')->insert($p);
    DB::table('pl_journal')->insert(spliJournal($ws, ['prospect_id' => $p['id']]));
    DB::table('pl_plafonds')->insert(['workspace_id' => $ws, 'expediteur_user_id' => $u, 'auteur' => 'responsable']);
    DB::table('pl_arret')->insert(['workspace_id' => $ws, 'geste' => 'arret', 'motif' => 'arrêt de test fictif', 'auteur' => 'responsable', 'declencheur' => 'humain']);
    $avant = array_map(fn ($t) => DB::table($t)->count(), SPLI_TABLES);
    foreach (['2026_12_09_000130_pl_prospects_journal.php', '2026_12_09_000140_pl_plafonds_arret.php'] as $f) {
        $m = require database_path('migrations/'.$f);
        $m->down();
        $m->up();
    }
    expect(array_map(fn ($t) => DB::table($t)->count(), SPLI_TABLES))->toBe($avant)
        ->and(DB::table('pl_prospects')->where('id', $p['id'])->value('linkedin_slug'))->toBe($p['linkedin_slug']);
});
