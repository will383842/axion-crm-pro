<?php

use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Lot P2-B — « une inscription active par adresse et par espace » (index unique partiel, migration SEULE).
 * Chaque test de sécurité est rejoué SOUS LE RÔLE APPLICATIF (SET LOCAL ROLE + WorkspaceContext) ET SOUS LE PROPRIÉTAIRE.
 * Données fictives (example.test) ; aucune adresse ni empreinte dans les messages attendus.
 */
uses(TestCase::class, RefreshDatabase::class);

const IAA_MODES = ['app', 'proprio'];
const IAA_INDEX = 'envoi_inscriptions_adresse_active_unique';
const IAA_FICHIER = __DIR__ . '/../../../database/migrations/2026_12_05_000180_envoi_inscriptions_adresse_active_unique.php';

function iaaEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Iaa', 'slug' => 'iaa-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function iaaSequence(string $ws): string
{
    $id = (string) Str::uuid();
    DB::table('envoi_sequences')->insert(['id' => $id, 'workspace_id' => $ws, 'nom' => 'Séquence fictive']);

    return $id;
}

function iaaLigne(string $ws, string $seq, string $empreinte, array $plus = []): array
{
    return $plus + ['workspace_id' => $ws, 'sequence_id' => $seq, 'contact_id' => random_int(1, 10 ** 9), 'entreprise_id' => 7,
        'empreinte_adresse' => $empreinte, 'prochaine_echeance' => now(), 'version_figee' => '{}'];
}

/** Le SQLSTATE de $f joué dans un point de sauvegarde, annulé ensuite (null = accepté). */
function iaaCode(string $mode, string $ws, Closure $f): ?string
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

function iaaIndex(): ?object
{
    return DB::selectOne(
        "SELECT i.indisvalid, i.indisunique, pg_get_expr(i.indpred, i.indrelid) AS pred, pg_get_indexdef(i.indexrelid) AS def
         FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_namespace n ON n.oid = c.relnamespace
         WHERE c.relname = ? AND n.nspname = 'public'",
        [IAA_INDEX],
    );
}

function iaaMigration(): object
{
    return require IAA_FICHIER;
}

test('structure : index unique, partiel (statut active), valide, sur (workspace_id, empreinte_adresse)', function () {
    $i = iaaIndex();
    expect($i)->not->toBeNull()
        ->and($i->indisvalid)->toBeTrue()->and($i->indisunique)->toBeTrue()
        ->and($i->pred)->toContain('active')
        ->and($i->def)->toContain('(workspace_id, empreinte_adresse)');
});

test('unicité : deux actives de même adresse → 23505 ; active + terminée, autre espace, ou après arrêt → accepté (rôle applicatif ET propriétaire)', function () {
    foreach (IAA_MODES as $mode) {
        $ws = iaaEspace();
        $s1 = iaaSequence($ws);
        $s2 = iaaSequence($ws);
        $h = hash('sha256', 'adresse-' . $mode);
        DB::table('envoi_inscriptions')->insert(iaaLigne($ws, $s1, $h));

        expect(iaaCode($mode, $ws, fn () => DB::table('envoi_inscriptions')->insert(iaaLigne($ws, $s2, $h))))->toBe('23505', $mode)
            ->and(iaaCode($mode, $ws, fn () => DB::table('envoi_inscriptions')->insert(iaaLigne($ws, $s2, $h, ['statut' => 'terminee']))))->toBeNull($mode)
            ->and(iaaCode($mode, $ws, function () use ($ws, $s1, $s2, $h) {
                DB::table('envoi_inscriptions')->where(['workspace_id' => $ws, 'sequence_id' => $s1, 'empreinte_adresse' => $h])->update(['statut' => 'arretee', 'motif_arret' => 'test']);
                DB::table('envoi_inscriptions')->insert(iaaLigne($ws, $s2, $h));
            }))->toBeNull($mode);

        $ws2 = iaaEspace();
        $autre = iaaSequence($ws2);
        $ligne = iaaLigne($ws2, $autre, $h);
        expect(iaaCode($mode, $ws2, fn () => DB::table('envoi_inscriptions')->insert($ligne)))->toBeNull($mode);
    }
});

test('doublons existants : la migration S\'ARRÊTE, message sans adresse ni empreinte, rien modifié, row_security remis', function () {
    $ws = iaaEspace();
    $s1 = iaaSequence($ws);
    $s2 = iaaSequence($ws);
    $h = hash('sha256', 'doublon-secret-iaa');
    DB::statement('DROP INDEX ' . IAA_INDEX);
    DB::table('envoi_inscriptions')->insert([iaaLigne($ws, $s1, $h), iaaLigne($ws, $s2, $h)]);
    $avant = DB::selectOne('SELECT count(*) AS n, string_agg(id::text || statut, \',\' ORDER BY id) AS s FROM envoi_inscriptions');
    $rls = DB::selectOne("SELECT current_setting('row_security') AS v")->v;

    $message = null;
    try {
        iaaMigration()->up();
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }

    $apres = DB::selectOne('SELECT count(*) AS n, string_agg(id::text || statut, \',\' ORDER BY id) AS s FROM envoi_inscriptions');
    expect($message)->not->toBeNull()->toContain('1 adresse(s)')
        ->and($message)->not->toContain($h)->not->toContain('example')->not->toContain($ws)
        ->and($apres->n)->toBe($avant->n)->and($apres->s)->toBe($avant->s)
        ->and(iaaIndex())->toBeNull()
        ->and(DB::selectOne("SELECT current_setting('row_security') AS v")->v)->toBe($rls);
});

test('rejouable : up() rejoué sans doublon ne change rien ; un index invalide de ce nom serait remplacé ; down() inerte', function () {
    $avant = iaaIndex();
    iaaMigration()->up();
    iaaMigration()->up();
    iaaMigration()->down();
    $apres = iaaIndex();
    expect($apres)->not->toBeNull()->and($apres->def)->toBe($avant->def)->and($apres->indisvalid)->toBeTrue();
});
