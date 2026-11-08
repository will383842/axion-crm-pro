<?php

use App\Envoi\Depot\DepotSocle;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Réserves d'audit L0 : garde des drapeaux, « tout arrêter », compteur global, savepoint. */
function espaceDurci(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Test', 'slug' => 'durci-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/** Pose la seule autorisation locale (celle que le contournement posait), sans ligne de journal. */
function poserAutorisationSeule(): void
{
    DB::select("SELECT set_config('app.envoi_ecriture_gardee', 'oui', true)");
}

/** Chaque appel s'exécute dans un SAVEPOINT : la transaction du test survit au refus du trigger. */
function sousSavepoint(Closure $f): void
{
    DB::transaction($f);
}

test('réserve 1 : set_config seul ne contourne plus le trigger (journal temoins_off exigé)', function () {
    $ws = espaceDurci();
    WorkspaceContext::run($ws, function () use ($ws) {
        DB::table('envoi_drapeaux')->insert(['workspace_id' => $ws]);
        expect(fn () => sousSavepoint(function () {
            poserAutorisationSeule();
            DB::table('envoi_drapeaux')->update(['mode_temoins' => false]);
        }))->toThrow(QueryException::class, 'temoins_off');
        expect(fn () => sousSavepoint(function () {
            poserAutorisationSeule();
            DB::table('envoi_drapeaux')->update(['feu_vert_signature' => 'sig']);
        }))->toThrow(QueryException::class, 'temoins_off');
        // Le journal d'UN AUTRE espace ne vaut pas trace pour celui-ci.
        $autre = espaceDurci();
        app(DepotSocle::class)->journaliser($autre, 'temoins_off', 'test');
        expect(fn () => sousSavepoint(function () {
            poserAutorisationSeule();
            DB::table('envoi_drapeaux')->update(['mode_temoins' => false]);
        }))->toThrow(QueryException::class, 'temoins_off');
    });
    expect(app(DepotSocle::class)->modeTemoins($ws))->toBeTrue();
});

test('réserve 1 : la voie prévue (journal + garde, même transaction) passe encore', function () {
    $ws = espaceDurci();
    $depot = app(DepotSocle::class);
    $depot->eteindreTemoins($ws, 'will');
    expect($depot->modeTemoins($ws))->toBeFalse();
});

test('réserve 1 : aucun code applicatif ne pose app.envoi_ecriture_gardee hors du dépôt prévu', function () {
    $trouves = cherchePoseGardee(app_path());
    expect($trouves)->toBe(['Envoi/Depot/DepotSocleDb.php']);
});

test('réserve 1 : le détecteur de pose voit un fichier fautif (le test ne passe pas à vide)', function () {
    $dir = sys_get_temp_dir() . '/gardee-' . Str::random(8);
    mkdir($dir . '/Http', 0777, true);
    file_put_contents($dir . '/Http/Fautif.php', "<?php\nDB::select(\"SELECT set_config('app.envoi_ecriture_gardee', 'oui', true)\");\n");
    file_put_contents($dir . '/Http/Propre.php', "<?php\nreturn 1;\n");
    try {
        expect(cherchePoseGardee($dir))->toBe(['Http/Fautif.php']);
    } finally {
        unlink($dir . '/Http/Fautif.php');
        unlink($dir . '/Http/Propre.php');
        rmdir($dir . '/Http');
        rmdir($dir);
    }
});

/** @return list<string> chemins (relatifs) des fichiers PHP qui citent le réglage gardé */
function cherchePoseGardee(string $racine): array
{
    $trouves = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php' && str_contains((string) file_get_contents($f->getPathname()), 'envoi_ecriture_gardee')) {
            $trouves[] = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($racine))), '/');
        }
    }
    sort($trouves);

    return $trouves;
}

test('réserve 2 : « tout arrêter » ne se désarme ni par UPDATE, ni par DELETE, ni par set_config seul', function () {
    $ws = espaceDurci();
    $depot = app(DepotSocle::class);
    $depot->armerToutArreter($ws, 'will');
    WorkspaceContext::run($ws, function () {
        expect(fn () => sousSavepoint(fn () => DB::table('envoi_drapeaux')->update(['tout_arreter' => false])))->toThrow(QueryException::class, 'tout arrêter');
        expect(fn () => sousSavepoint(fn () => DB::table('envoi_drapeaux')->delete()))->toThrow(QueryException::class, 'tout arrêter');
        expect(fn () => sousSavepoint(function () {
            poserAutorisationSeule();
            DB::table('envoi_drapeaux')->update(['tout_arreter' => false]);
        }))->toThrow(QueryException::class, 'reprise');
        expect(fn () => sousSavepoint(function () {
            poserAutorisationSeule();
            DB::table('envoi_drapeaux')->delete();
        }))->toThrow(QueryException::class, 'reprise');
    });
    expect($depot->toutArreter($ws))->toBeTrue();
});

test('réserve 2 : la reprise prévue passe, avec une ligne de journal « reprise »', function () {
    $ws = espaceDurci();
    $depot = app(DepotSocle::class);
    $depot->armerToutArreter($ws, 'will');
    expect($depot->leverToutArreter($ws, 'will'))->toBeTrue()->and($depot->toutArreter($ws))->toBeFalse();
    $n = WorkspaceContext::run($ws, fn () => DB::table('envoi_journal_commandes')->where('commande', 'reprise')->count());
    expect($n)->toBe(1);
});

test('réserve 2 : le rôle applicatif n’a pas TRUNCATE sur envoi_drapeaux', function () {
    $role = (string) config('database.connections.pgsql_app.username');
    expect(DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS a', [$role, 'envoi_drapeaux', 'TRUNCATE'])->a)->toBeFalse();
});

test('réserve 3 : le compteur global ne s’écrit plus directement, seulement par la fonction', function () {
    $ws = espaceDurci();
    $role = (string) config('database.connections.pgsql_app.username');
    foreach (['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE'] as $privilege) {
        expect(DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS a', [$role, 'envoi_compteur_global', $privilege])->a)->toBeFalse("{$role} ne doit pas avoir {$privilege}");
    }
    $depot = app(DepotSocle::class);
    $j = new DateTimeImmutable('2031-05-04');
    $depot->noterInjection($ws, $j, 'cle-compteur');
    $avant = $depot->injectesLeGlobal($j);
    expect($avant)->toBe(1);
    // La connexion par défaut des tests est propriétaire : l'écriture directe se tente en rôle applicatif.
    $app = DB::connection('pgsql_app');
    expect(fn () => $app->table('envoi_compteur_global')->update(['n' => 0]))->toThrow(QueryException::class, 'permission denied');
    expect(fn () => $app->statement("INSERT INTO envoi_compteur_global (jour, n) VALUES ('2031-05-05', 0)"))->toThrow(QueryException::class, 'permission denied');
    expect($depot->injectesLeGlobal($j))->toBe($avant);
    $depot->noterInjection($ws, $j, 'cle-compteur'); // même clé : pas recomptée
    $depot->noterInjection($ws, $j, 'cle-compteur-2');
    expect($depot->injectesLeGlobal($j))->toBe(2);
});

test('réserve 4 : une écriture de drapeaux qui échoue remonte l’erreur réelle, pas 25P02', function () {
    $ws = espaceDurci();
    $owner = DB::connection('pgsql_owner');
    $owner->unprepared("
        CREATE OR REPLACE FUNCTION envoi_test_panne_drapeaux() RETURNS trigger AS \$f\$
        BEGIN RAISE EXCEPTION 'panne simulée des drapeaux'; END; \$f\$ LANGUAGE plpgsql;
        CREATE TRIGGER zz_envoi_test_panne_drapeaux BEFORE INSERT ON envoi_drapeaux
            FOR EACH ROW WHEN (NEW.workspace_id = '{$ws}') EXECUTE FUNCTION envoi_test_panne_drapeaux();
    ");
    try {
        $depot = app(DepotSocle::class);
        foreach (['eteindreTemoins', 'armerToutArreter'] as $methode) {
            try {
                $depot->{$methode}($ws, 'will');
                $this->fail("{$methode} aurait dû échouer");
            } catch (QueryException $e) {
                expect($e->getMessage())->toContain('panne simulée des drapeaux')->not->toContain('25P02');
            }
        }
        // La transaction du test reste saine (aucune session avortée) et le contexte est nettoyé.
        expect(DB::selectOne('SELECT 1 AS ok')->ok)->toBe(1);
    } finally {
        $owner->unprepared('DROP TRIGGER IF EXISTS zz_envoi_test_panne_drapeaux ON envoi_drapeaux; DROP FUNCTION IF EXISTS envoi_test_panne_drapeaux();');
    }
});
