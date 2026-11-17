<?php

use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Lot L0a (PLAN ADRESSES GÉNÉRALES, #680) — migration SEULE et INERTE : colonne `repli_generique`, CHECK « une seule étape »,
 * gardes en base. Chaque test de sécurité est rejoué SOUS LE RÔLE APPLICATIF ET SOUS LE PROPRIÉTAIRE. Données fictives (example.test).
 */
uses(TestCase::class, RefreshDatabase::class);

const RGS_MODES = ['app', 'proprio'];
const RGS_CLE = 'envoi.repli_generique';
const RGS_PLAFOND = 'envoi.repli_generique_plafond_jour';

function rgsEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Rgs', 'slug' => 'rgs-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function rgsSequence(string $ws): string
{
    $id = (string) Str::uuid();
    DB::table('envoi_sequences')->insert(['id' => $id, 'workspace_id' => $ws, 'nom' => 'Séquence fictive']);

    return $id;
}

/** Inscription « entreprises » ; $plus remplace ou ajoute des colonnes. */
function rgsLigne(string $ws, string $seq, array $plus = []): array
{
    return $plus + ['workspace_id' => $ws, 'sequence_id' => $seq, 'contact_id' => random_int(1, 10 ** 9), 'entreprise_id' => 7,
        'empreinte_adresse' => hash('sha256', Str::uuid()->toString()), 'prochaine_echeance' => now(), 'version_figee' => '{"etapes":[{"rang":1}]}'];
}

function rgsRepli(string $ws, string $seq, array $plus = []): array
{
    return rgsLigne($ws, $seq, $plus + ['repli_generique' => true]);
}

/** Le SQLSTATE de $f joué dans une transaction annulée ensuite (null = accepté). */
function rgsCode(Closure $f): ?string
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

function rgsSous(string $mode, string $ws, Closure $f): ?string
{
    return rgsCode(function () use ($mode, $ws, $f) {
        if ($mode === 'app') {
            WorkspaceContext::set($ws);
            DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
        }
        $f();
    });
}

/** Réglage posé par le propriétaire (le seul autorisé). */
function rgsPoser(string $ws, string $cle, string $valeur): void
{
    WorkspaceContext::set($ws);
    DB::table('envoi_reglages')->insert(['workspace_id' => $ws, 'cle' => $cle, 'valeur' => $valeur, 'auteur' => 'fondateur']);
}

test('structure : colonne à défaut false, CHECK validé, déclencheurs sans SECURITY DEFINER et search_path fixé', function () {
    $c = DB::selectOne("SELECT column_default, is_nullable FROM information_schema.columns WHERE table_name = 'envoi_inscriptions' AND column_name = 'repli_generique'");
    expect($c)->not->toBeNull()->and($c->column_default)->toContain('false')->and($c->is_nullable)->toBe('NO');
    expect(DB::selectOne("SELECT convalidated FROM pg_constraint WHERE conname = 'envoi_inscriptions_repli_une_etape'")->convalidated)->toBeTrue();
    foreach (['envoi_inscriptions_repli_garde' => 'envoi_inscriptions', 'envoi_reglages_repli_generique' => 'envoi_reglages'] as $t => $table) {
        expect(DB::selectOne('SELECT 1 AS e FROM pg_trigger WHERE tgname = ? AND tgrelid = ?::regclass', [$t, $table]))->not->toBeNull();
        $p = DB::selectOne('SELECT proconfig::text AS c, prosecdef FROM pg_proc WHERE proname = ?', [$t]);
        expect(str_ends_with($p->c, 'pg_temp}') || str_ends_with($p->c, 'pg_temp"}'))->toBeTrue()->and($p->prosecdef)->toBeFalse();
    }
});

test('insertion de repli : refusée sans interrupteur vrai dans l’espace, acceptée avec', function () {
    foreach (RGS_MODES as $mode) {
        $ws = rgsEspace();
        $autre = rgsEspace();
        $seq = rgsSequence($ws);
        $ins = fn () => DB::table('envoi_inscriptions')->insert(rgsRepli($ws, $seq));
        expect(rgsSous($mode, $ws, $ins))->toBe('P0001', "$mode / absent");
        rgsPoser($autre, RGS_CLE, 'true');
        expect(rgsSous($mode, $ws, $ins))->toBe('P0001', "$mode / réglage d'un autre espace");
        rgsPoser($ws, RGS_CLE, 'false');
        expect(rgsSous($mode, $ws, $ins))->toBe('P0001', "$mode / faux");
        DB::table('envoi_reglages')->where('workspace_id', $ws)->where('cle', RGS_CLE)->update(['valeur' => 'true']);
        expect(rgsSous($mode, $ws, $ins))->toBeNull("$mode / vrai");
    }
});

test('CHECK : un repli de 2 étapes, ou sans étapes, ou hors population entreprises → 23514', function () {
    foreach (RGS_MODES as $mode) {
        $ws = rgsEspace();
        $seq = rgsSequence($ws);
        rgsPoser($ws, RGS_CLE, 'true');
        $cas = [
            '2 étapes' => rgsRepli($ws, $seq, ['version_figee' => '{"etapes":[{"rang":1},{"rang":2}]}']),
            '0 étape' => rgsRepli($ws, $seq, ['version_figee' => '{"etapes":[]}']),
            'étapes absentes' => rgsRepli($ws, $seq, ['version_figee' => '{}']),
            'étapes non tableau' => rgsRepli($ws, $seq, ['version_figee' => '{"etapes":"x"}']),
            'population personnes' => rgsRepli($ws, $seq, ['population' => 'personnes', 'personne_id' => 5, 'contact_id' => null, 'entreprise_id' => null]),
        ];
        foreach ($cas as $nom => $ligne) {
            expect(rgsSous($mode, $ws, fn () => DB::table('envoi_inscriptions')->insert($ligne)))->toBeIn(['23514', 'P0001'], "$mode / $nom");
        }
        // Une inscription ordinaire de 2 étapes reste permise.
        expect(rgsSous($mode, $ws, fn () => DB::table('envoi_inscriptions')->insert(
            rgsLigne($ws, $seq, ['version_figee' => '{"etapes":[{"rang":1},{"rang":2}]}']),
        )))->toBeNull($mode);
    }
});

test('colonne figée : aucun UPDATE de repli_generique (true→false, false→true)', function () {
    foreach (RGS_MODES as $mode) {
        $ws = rgsEspace();
        $seq = rgsSequence($ws);
        rgsPoser($ws, RGS_CLE, 'true');
        $repli = (string) Str::uuid();
        $normal = (string) Str::uuid();
        DB::table('envoi_inscriptions')->insert(rgsRepli($ws, $seq, ['id' => $repli]));
        DB::table('envoi_inscriptions')->insert(rgsLigne($ws, $seq, ['id' => $normal]));
        expect(rgsSous($mode, $ws, fn () => DB::table('envoi_inscriptions')->where('id', $repli)->update(['repli_generique' => false])))->toBe('P0001', "$mode / true→false")
            ->and(rgsSous($mode, $ws, fn () => DB::table('envoi_inscriptions')->where('id', $normal)->update(['repli_generique' => true])))->toBe('P0001', "$mode / false→true")
            ->and(rgsSous($mode, $ws, fn () => DB::table('envoi_inscriptions')->where('id', $repli)->update(['statut' => 'terminee'])))->toBeNull("$mode / statut libre");
    }
});

test('garde des clés : le rôle applicatif ne pose pas l’interrupteur ni le plafond ; le propriétaire pose true/false et 0 à 5', function () {
    $ws = rgsEspace();
    $ins = fn (string $cle, string $valeur) => fn () => DB::table('envoi_reglages')->insert(['workspace_id' => $ws, 'cle' => $cle, 'valeur' => $valeur, 'auteur' => 'x']);
    expect(rgsSous('app', $ws, $ins(RGS_CLE, 'true')))->toBe('P0001')
        ->and(rgsSous('app', $ws, $ins(RGS_PLAFOND, '3')))->toBe('P0001');
    expect(rgsSous('proprio', $ws, $ins(RGS_CLE, 'true')))->toBeNull()
        ->and(rgsSous('proprio', $ws, $ins(RGS_CLE, 'false')))->toBeNull()
        ->and(rgsSous('proprio', $ws, $ins(RGS_CLE, 'oui')))->toBe('P0001')
        ->and(rgsSous('proprio', $ws, $ins(RGS_CLE, '1')))->toBe('P0001');
    foreach (['0', '3', '5'] as $ok) {
        expect(rgsSous('proprio', $ws, $ins(RGS_PLAFOND, $ok)))->toBeNull("plafond $ok");
    }
    foreach (['6', '-1', 'abc', '', '3.5', ' 3'] as $ko) {
        expect(rgsSous('proprio', $ws, $ins(RGS_PLAFOND, $ko)))->toBe('P0001', "plafond « $ko »");
    }
    // Poser puis modifier : le rôle applicatif ne le change pas, ni ne renomme une autre clé vers celle-ci.
    rgsPoser($ws, RGS_CLE, 'false');
    DB::table('envoi_reglages')->insert(['workspace_id' => $ws, 'cle' => 'regles.autre', 'valeur' => '1', 'auteur' => 'x']);
    expect(rgsSous('app', $ws, fn () => DB::table('envoi_reglages')->where('cle', RGS_CLE)->update(['valeur' => 'true'])))->toBe('P0001')
        ->and(rgsSous('app', $ws, fn () => DB::table('envoi_reglages')->where('cle', 'regles.autre')->update(['cle' => RGS_PLAFOND])))->toBe('P0001')
        ->and(rgsSous('app', $ws, fn () => DB::table('envoi_reglages')->where('cle', 'regles.autre')->update(['valeur' => '2'])))->toBeNull();
});

test('contexte d’espace absent : l’insertion de repli reste refusée (échec fermé)', function () {
    $ws = rgsEspace();
    $seq = rgsSequence($ws);
    rgsPoser($ws, RGS_CLE, 'true');
    $ligne = rgsRepli($ws, $seq);
    $app = (string) config('database.connections.pgsql_app.username');
    expect(rgsCode(function () use ($ligne, $app) {
        DB::statement("SET LOCAL ROLE \"{$app}\"");
        DB::statement("SELECT set_config('app.current_workspace_id', '', true)");
        DB::table('envoi_inscriptions')->insert($ligne);
    }))->toBeIn(['P0001', '42501']);
});

test('table temporaire homonyme envoi_reglages (rôle applicatif) : toujours refusé', function () {
    $ws = rgsEspace();
    $seq = rgsSequence($ws);
    $ligne = rgsRepli($ws, $seq);
    expect(rgsSous('app', $ws, function () use ($ws, $ligne) {
        DB::statement('CREATE TEMP TABLE envoi_reglages (workspace_id uuid, cle text, valeur text)');
        DB::statement('INSERT INTO pg_temp.envoi_reglages VALUES (?, ?, ?)', [$ws, RGS_CLE, 'true']);
        DB::table('envoi_inscriptions')->insert($ligne);
    }))->toBe('P0001');
});

test('inertie : toute ligne existante garde false, aucun réglage créé', function () {
    $ws = rgsEspace();
    $seq = rgsSequence($ws);
    DB::table('envoi_inscriptions')->insert(rgsLigne($ws, $seq));
    expect(DB::table('envoi_inscriptions')->where('repli_generique', true)->count())->toBe(0)
        ->and(DB::table('envoi_inscriptions')->where('repli_generique', false)->count())->toBe(1)
        ->and(DB::table('envoi_reglages')->whereIn('cle', [RGS_CLE, RGS_PLAFOND])->count())->toBe(0);
});

test('rejouable : la migration rejouée deux fois ne change rien, ne crée aucune ligne', function () {
    $ws = rgsEspace();
    $seq = rgsSequence($ws);
    DB::table('envoi_inscriptions')->insert(rgsLigne($ws, $seq));
    $avant = DB::table('envoi_inscriptions')->count();
    $migration = require database_path('migrations/2026_12_12_000100_envoi_repli_generique.php');
    $migration->up();
    $migration->up();
    $migration->down();
    expect(DB::table('envoi_inscriptions')->count())->toBe($avant)
        ->and(DB::selectOne("SELECT count(*) AS n FROM pg_constraint WHERE conname = 'envoi_inscriptions_repli_une_etape'")->n)->toBe(1)
        ->and(DB::selectOne("SELECT count(*) AS n FROM pg_trigger WHERE tgname IN ('envoi_inscriptions_repli_garde','envoi_reglages_repli_generique')")->n)->toBe(2);
});
