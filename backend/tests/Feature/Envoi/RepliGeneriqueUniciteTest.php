<?php

use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Lot L0b (PLAN ADRESSES GÉNÉRALES, #680) — migration SEULE : index unique partiel « une inscription de repli par entreprise, à vie,
 * tous statuts ». Chaque test d'unicité est rejoué SOUS LE RÔLE APPLICATIF ET SOUS LE PROPRIÉTAIRE. Données fictives (example.test).
 */
uses(TestCase::class, RefreshDatabase::class);

const RGU_MODES = ['app', 'proprio'];
const RGU_INDEX = 'envoi_inscriptions_repli_entreprise_unique';
const RGU_FICHIER = __DIR__ . '/../../../database/migrations/2026_12_12_000110_envoi_inscriptions_repli_entreprise_unique.php';

function rguEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Rgu', 'slug' => 'rgu-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function rguSequence(string $ws): string
{
    $id = (string) Str::uuid();
    DB::table('envoi_sequences')->insert(['id' => $id, 'workspace_id' => $ws, 'nom' => 'Séquence fictive']);

    return $id;
}

/** Ouvre l'interrupteur de repli de l'espace (posé par le propriétaire, seul autorisé). */
function rguOuvrir(string $ws): void
{
    WorkspaceContext::set($ws);
    DB::table('envoi_reglages')->insert(['workspace_id' => $ws, 'cle' => 'envoi.repli_generique', 'valeur' => 'true', 'auteur' => 'fondateur']);
}

/** Inscription « entreprises » à une seule étape ; $plus remplace ou ajoute des colonnes. */
function rguLigne(string $ws, string $seq, int $entreprise, array $plus = []): array
{
    return $plus + ['workspace_id' => $ws, 'sequence_id' => $seq, 'contact_id' => random_int(1, 10 ** 9), 'entreprise_id' => $entreprise,
        'empreinte_adresse' => hash('sha256', Str::uuid()->toString()), 'prochaine_echeance' => now(), 'version_figee' => '{"etapes":[{"rang":1}]}'];
}

/** Le SQLSTATE de $f joué dans une transaction annulée ensuite (null = accepté). */
function rguCode(string $mode, string $ws, Closure $f): ?string
{
    try {
        DB::transaction(function () use ($mode, $ws, $f) {
            if ($mode === 'app') {
                WorkspaceContext::set($ws);
                DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
            }
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

function rguIndex(): ?object
{
    return DB::selectOne(
        "SELECT i.indisvalid, i.indisunique, pg_get_expr(i.indpred, i.indrelid) AS pred, pg_get_indexdef(i.indexrelid) AS def
         FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_namespace n ON n.oid = c.relnamespace
         WHERE c.relname = ? AND n.nspname = 'public'",
        [RGU_INDEX],
    );
}

function rguMigration(): object
{
    return require RGU_FICHIER;
}

test('structure : index unique, partiel sur repli_generique, valide, sur (workspace_id, entreprise_id), sans condition de statut', function () {
    $i = rguIndex();
    expect($i)->not->toBeNull()
        ->and($i->indisvalid)->toBeTrue()->and($i->indisunique)->toBeTrue()
        ->and($i->pred)->toContain('repli_generique')->not->toContain('statut')
        ->and($i->def)->toContain('(workspace_id, entreprise_id)');
});

test('une 2e inscription de repli pour la même entreprise (autre séquence, tout statut) → 23505 ; non-repli et autres entreprises permis', function () {
    foreach (RGU_MODES as $mode) {
        $ws = rguEspace();
        rguOuvrir($ws);
        $s1 = rguSequence($ws);
        $s2 = rguSequence($ws);
        DB::table('envoi_inscriptions')->insert(rguLigne($ws, $s1, 42, ['repli_generique' => true, 'statut' => 'terminee']));

        foreach (['active', 'terminee', 'arretee'] as $statut) {
            $plus = ['repli_generique' => true, 'statut' => $statut] + ($statut === 'arretee' ? ['motif_arret' => 'test'] : []);
            expect(rguCode($mode, $ws, fn () => DB::table('envoi_inscriptions')->insert(rguLigne($ws, $s2, 42, $plus))))->toBe('23505', "$mode / $statut");
        }
        // Même séquence, autre contact : toujours refusé (l'unicité est par entreprise, pas par séquence).
        expect(rguCode($mode, $ws, fn () => DB::table('envoi_inscriptions')->insert(rguLigne($ws, $s1, 42, ['repli_generique' => true]))))->toBe('23505', "$mode / même séquence");

        // Permis : inscription ordinaire de la même entreprise, autre entreprise en repli, autre espace.
        expect(rguCode($mode, $ws, fn () => DB::table('envoi_inscriptions')->insert([rguLigne($ws, $s2, 42), rguLigne($ws, $s2, 42)])))->toBeNull("$mode / non-repli")
            ->and(rguCode($mode, $ws, fn () => DB::table('envoi_inscriptions')->insert(rguLigne($ws, $s2, 43, ['repli_generique' => true]))))->toBeNull("$mode / autre entreprise");
        $ws2 = rguEspace();
        rguOuvrir($ws2);
        $autre = rguSequence($ws2);
        expect(rguCode($mode, $ws2, fn () => DB::table('envoi_inscriptions')->insert(rguLigne($ws2, $autre, 42, ['repli_generique' => true]))))->toBeNull("$mode / autre espace");
    }
});

test('doublons existants : la migration S\'ARRÊTE, message sans donnée, rien modifié ni supprimé, row_security remis', function () {
    $ws = rguEspace();
    rguOuvrir($ws);
    $s1 = rguSequence($ws);
    $s2 = rguSequence($ws);
    DB::statement('DROP INDEX ' . RGU_INDEX);
    DB::table('envoi_inscriptions')->insert([
        rguLigne($ws, $s1, 4242, ['repli_generique' => true, 'statut' => 'terminee']),
        rguLigne($ws, $s2, 4242, ['repli_generique' => true, 'statut' => 'active']),
    ]);
    $avant = DB::selectOne('SELECT count(*) AS n, string_agg(id::text || statut, \',\' ORDER BY id) AS s FROM envoi_inscriptions');
    $rls = DB::selectOne("SELECT current_setting('row_security') AS v")->v;

    $message = null;
    try {
        rguMigration()->up();
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }

    $apres = DB::selectOne('SELECT count(*) AS n, string_agg(id::text || statut, \',\' ORDER BY id) AS s FROM envoi_inscriptions');
    expect($message)->not->toBeNull()->toContain('1 entreprise(s)')
        ->and($message)->not->toContain('4242')->not->toContain($ws)->not->toContain('example')
        ->and($apres->n)->toBe($avant->n)->and($apres->s)->toBe($avant->s)
        ->and(rguIndex())->toBeNull()
        ->and(DB::selectOne("SELECT current_setting('row_security') AS v")->v)->toBe($rls);
});

test('rejouable : up() rejoué ne change rien ; un index invalide de ce nom est remplacé ; down() inerte', function () {
    $avant = rguIndex();
    rguMigration()->up();
    rguMigration()->up();
    rguMigration()->down();
    $apres = rguIndex();
    expect($apres)->not->toBeNull()->and($apres->def)->toBe($avant->def)->and($apres->indisvalid)->toBeTrue();

    // Index invalide de ce nom (création interrompue) : remplacé par un index valide.
    DB::statement("UPDATE pg_index SET indisvalid = false WHERE indexrelid = 'public." . RGU_INDEX . "'::regclass");
    expect(rguIndex()->indisvalid)->toBeFalse();
    rguMigration()->up();
    expect(rguIndex()->indisvalid)->toBeTrue()->and(rguIndex()->def)->toBe($avant->def);
});
