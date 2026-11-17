<?php

use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
 * Lot L6-0 — index d'expression de `envoi_evenements` (migration SEULE, cahier L5-L6 § 5).
 * Lecture rejouée SOUS LE RÔLE APPLICATIF (SET LOCAL ROLE + WorkspaceContext) ET SOUS LE PROPRIÉTAIRE.
 * Aucune donnée de personne ; aucune ligne écrite.
 */
uses(TestCase::class, RefreshDatabase::class);

const IEI_NOM = 'envoi_evenements_injection_texte';
const IEI_FICHIER = __DIR__ . '/../../../database/migrations/2026_12_09_000100_envoi_evenements_index_injection.php';

function ieiIndex(): array
{
    return DB::select(
        "SELECT i.indisvalid, pg_get_expr(i.indpred, i.indrelid) AS pred, pg_get_indexdef(i.indexrelid) AS def
         FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_namespace n ON n.oid = c.relnamespace
         WHERE c.relname = ? AND n.nspname = 'public'",
        [IEI_NOM],
    );
}

test('structure : index présent, valide, partiel, expression (id_injection)::text, première colonne workspace_id', function () {
    $l = ieiIndex();
    expect($l)->toHaveCount(1);
    $i = $l[0];
    expect($i->indisvalid)->toBeTrue()
        ->and($i->pred)->toContain('id_injection IS NOT NULL')
        ->and($i->def)->toContain('ON public.envoi_evenements')
        ->and($i->def)->toContain('(workspace_id, ((id_injection)::text))');
});

test('up() rejouable : deux appels, un seul index, aucune ligne touchée ; down() inerte', function () {
    $avant = DB::table('envoi_evenements')->count();
    $m = require IEI_FICHIER;
    $m->up();
    $m->up();
    $m->down();
    expect(ieiIndex())->toHaveCount(1)
        ->and(ieiIndex()[0]->indisvalid)->toBeTrue()
        ->and(DB::table('envoi_evenements')->count())->toBe($avant);
});

test('le planificateur peut utiliser l\'index sous le rôle applicatif ET sous le propriétaire', function () {
    foreach (['app', 'proprio'] as $mode) {
        $plan = DB::transaction(function () use ($mode) {
            if ($mode === 'app') {
                WorkspaceContext::set('00000000-0000-4000-8000-000000000000');
                DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
            }
            DB::statement('SET LOCAL enable_seqscan = off');
            $lignes = DB::select(
                "EXPLAIN SELECT 1 FROM envoi_evenements WHERE workspace_id = '00000000-0000-4000-8000-000000000000' AND id_injection::text = 'x.example.test'",
            );

            return implode("\n", array_map(fn ($l) => (string) array_values((array) $l)[0], $lignes));
        });
        expect($plan)->toContain(IEI_NOM);
    }
});
