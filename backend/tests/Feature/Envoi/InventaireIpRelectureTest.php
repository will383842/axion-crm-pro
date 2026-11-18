<?php

use App\Envoi\Chauffe\DepotIps;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * IP-INV — remarques de relecture de #699 : contrôle de l'auteur, retrait atomique, espace invalide.
 * Joué sous le rôle applicatif ET sous le propriétaire. Données fictives (IP RFC 5737).
 */
uses(TestCase::class, RefreshDatabase::class);

function iirEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Iir', 'slug' => 'iir-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function iirSous(string $mode, string $ws, Closure $f): mixed
{
    DB::beginTransaction();
    try {
        WorkspaceContext::set($ws);
        if ($mode === 'app') {
            DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
        }

        return $f();
    } finally {
        if ($mode === 'app') {
            DB::statement('RESET ROLE');
        }
        DB::commit();
    }
}

/** @return list<object> lignes de l'inventaire de $ws, lues sous le contexte de $ws */
function iirLignes(string $ws): array
{
    return WorkspaceContext::run($ws, fn () => DB::table('envoi_ips')->where('workspace_id', $ws)->orderBy('ref')->get(['ref', 'auteur', 'retiree_le'])->all());
}

test('ajouter refuse un auteur vide, trop long ou contenant @ : 0 ligne', function (string $mode, string $auteur) {
    $ws = iirEspace();
    $refus = iirSous($mode, $ws, function () use ($ws, $auteur) {
        try {
            app(DepotIps::class)->ajouter($ws, '192.0.2.10', 'prospection', $auteur);
        } catch (InvalidArgumentException) {
            return true;
        }

        return false;
    });
    expect($refus)->toBeTrue()->and(iirLignes($ws))->toBe([]);
})->with([['proprio'], ['app']])->with(['a@b.example.test', '', '   ', str_repeat('x', 101)]);

test('ajouter accepte un auteur de 100 caractères sans @', function (string $mode) {
    $ws = iirEspace();
    iirSous($mode, $ws, fn () => app(DepotIps::class)->ajouter($ws, '192.0.2.11', 'prospection', str_repeat('x', 100)));
    expect(iirLignes($ws))->toHaveCount(1);
})->with(['proprio', 'app']);

test('la commande refuse l’auteur avec @ : sortie 1, rien inscrit', function (string $mode) {
    $ws = iirEspace();
    $code = iirSous($mode, $ws, fn () => Artisan::call('envoi:ip-inventaire', ['workspace' => $ws, 'action' => 'ajouter', 'ip' => '192.0.2.12', '--flux' => 'prospection', '--auteur' => 'a@b.example.test']));
    expect($code)->toBe(1)->and(iirLignes($ws))->toBe([]);
})->with(['proprio', 'app']);

test('retirer joue son UPDATE dans une transaction', function (string $mode) {
    $ws = iirEspace();
    iirSous($mode, $ws, fn () => app(DepotIps::class)->ajouter($ws, '192.0.2.13', 'prospection', 'test'));
    $niveaux = [];
    DB::listen(function ($q) use (&$niveaux) {
        if (str_starts_with($q->sql, 'update "envoi_ips"')) {
            $niveaux[] = DB::transactionLevel();
        }
    });
    $avant = 0;
    $ok = iirSous($mode, $ws, function () use ($ws, &$avant) {
        $avant = DB::transactionLevel();

        return app(DepotIps::class)->retirer($ws, '192.0.2.13');
    });
    expect($ok)->toBeTrue()->and($niveaux)->toHaveCount(1)->and($niveaux[0])->toBeGreaterThan($avant);
})->with(['proprio', 'app']);

test('une erreur pendant retirer laisse retiree_le à NULL', function (string $mode) {
    $ws = iirEspace();
    iirSous($mode, $ws, fn () => app(DepotIps::class)->ajouter($ws, '192.0.2.14', 'prospection', 'test'));
    DB::unprepared("CREATE FUNCTION iir_echec() RETURNS trigger LANGUAGE plpgsql AS \$f\$ BEGIN RAISE EXCEPTION 'echec_test'; END \$f\$");
    DB::unprepared('CREATE TRIGGER iir_echec AFTER UPDATE ON envoi_ips FOR EACH ROW EXECUTE FUNCTION iir_echec()');
    $erreur = false;
    try {
        iirSous($mode, $ws, fn () => DB::transaction(fn () => app(DepotIps::class)->retirer($ws, '192.0.2.14')));
    } catch (QueryException) {
        $erreur = true;
    }
    DB::unprepared('DROP TRIGGER iir_echec ON envoi_ips');
    expect($erreur)->toBeTrue()->and(iirLignes($ws)[0]->retiree_le)->toBeNull();
})->with(['proprio', 'app']);

test('espace invalide : les deux commandes rendent 1 avec un message neutre, sans trace', function (string $commande, array $params) {
    $code = Artisan::call($commande, ['workspace' => 'pas-un-espace'] + $params + ['--auteur' => 'test']);
    $sortie = Artisan::output();
    expect($code)->toBe(1)
        ->and($sortie)->not->toContain('Exception')->not->toContain('#0')->not->toContain('.php');
})->with([
    'ip-inventaire ajouter' => ['envoi:ip-inventaire', ['action' => 'ajouter', 'ip' => '192.0.2.15', '--flux' => 'prospection']],
    'ip-inventaire retirer' => ['envoi:ip-inventaire', ['action' => 'retirer', 'ip' => '192.0.2.15']],
    'domaine-ip' => ['envoi:domaine-ip', ['domaine' => 'd.example.test', 'ip' => '192.0.2.15']],
]);

test('l’espace B ne voit rien de A et ne peut pas retirer son IP', function (string $mode) {
    $a = iirEspace();
    $b = iirEspace();
    iirSous($mode, $a, fn () => app(DepotIps::class)->ajouter($a, '192.0.2.16', 'prospection', 'test'));
    $retire = iirSous($mode, $b, fn () => app(DepotIps::class)->retirer($b, '192.0.2.16'));
    expect($retire)->toBeFalse()
        ->and(iirLignes($b))->toBe([])
        ->and(iirLignes($a)[0]->retiree_le)->toBeNull();
})->with(['proprio', 'app']);
