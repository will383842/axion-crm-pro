<?php

use App\Support\WorkspaceContext;
use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * LA TABLE `job_batches` ET LE CHEMIN « LOT » DES GROSSES AUDIENCES
 * (panne de production du 2026-10-02, 21 h 42).
 *
 *     audiences:full-refresh → vues : 3 · rafraîchies : 0 · en échec : 3
 *     SQLSTATE[42P01]: relation "job_batches" does not exist
 *
 * Au-delà de 5 000 entreprises, `AudienceBuilderService::refresh()` part en
 * `Bus::batch`, que Laravel range dans `job_batches` — table qu'aucune
 * migration ne créait. Les tests existants du chemin batch passaient par
 * `Bus::fake()`, qui n'écrit jamais dans la table : la panne était invisible.
 *
 * Ici, rien n'est simulé côté base : le seuil est abaissé à 1 fiche
 * (`crm.audiences.seuil_lot`), et tout tourne sous `axion_app` (le rôle de
 * production, soumis à la RLS), données semées et nettoyées par le
 * propriétaire. Fixtures FICTIVES (dépôt public).
 */
function jbProprio(): Connection
{
    return DB::connection('pgsql_owner');
}

/** Lots rangés sous le rôle applicatif, comme en production. */
function jbLotsSousRoleApplicatif(): void
{
    config([
        'queue.batching.database' => 'pgsql_app',
        'crm.audiences.seuil_lot' => 1,
        'crm.audiences.taille_lot' => 1,
    ]);
    app()->forgetInstance(BatchRepository::class);
    app()->forgetInstance(DatabaseBatchRepository::class);
}

/** @return array{0: string, 1: int} espace, audience */
function jbSemer(int $fiches): array
{
    $owner = jbProprio();
    $espace = (string) Str::uuid();
    $owner->table('workspaces')->insert([
        'id' => $espace, 'slug' => 'zz-jb-' . substr(str_replace('-', '', $espace), 0, 8), 'name' => 'ZZ job_batches',
        'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    for ($i = 1; $i <= $fiches; $i++) {
        $owner->table('companies')->insert([
            'workspace_id' => $espace,
            'siren' => '96' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
            'denomination' => 'ZZ JOB BATCHES ' . $i, 'region_code' => '84', 'postcode' => '38000',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $audience = (int) $owner->table('email_audiences')->insertGetId([
        'workspace_id' => $espace, 'name' => 'ZZ audience lot',
        'criteria' => json_encode(['all' => [['field' => 'region_code', 'op' => 'eq', 'value' => '84']]]),
        'is_active' => true, 'auto_refresh' => true, 'member_count' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$espace, $audience];
}

function jbNettoyer(string $espace, int $audience): void
{
    $owner = jbProprio();
    $owner->table('job_batches')->where('name', "audience-refresh-{$audience}")->delete();
    $owner->table('audience_members')->where('workspace_id', $espace)->delete();
    $owner->table('email_audiences')->where('workspace_id', $espace)->delete();
    $owner->table('business_events')->where('workspace_id', $espace)->delete();
    $owner->table('companies')->where('workspace_id', $espace)->delete();
    $owner->table('workspaces')->where('id', $espace)->delete();
}

afterEach(function () {
    DB::connection('pgsql_app')->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
    DB::connection('pgsql_app')->disconnect();
    jbProprio()->disconnect();
});

test('la table job_batches existe avec le schema Laravel 12 et le role applicatif peut y lire et ecrire', function () {
    expect(Schema::hasTable('job_batches'))->toBeTrue();

    $colonnes = collect(DB::select(<<<'SQL'
        SELECT column_name, data_type, is_nullable
          FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = 'job_batches'
    SQL))->keyBy('column_name');

    $attendu = [
        'id' => ['character varying', 'NO'],
        'name' => ['character varying', 'NO'],
        'total_jobs' => ['integer', 'NO'],
        'pending_jobs' => ['integer', 'NO'],
        'failed_jobs' => ['integer', 'NO'],
        'failed_job_ids' => ['text', 'NO'],
        'options' => ['text', 'YES'],
        'cancelled_at' => ['integer', 'YES'],
        'created_at' => ['integer', 'NO'],
        'finished_at' => ['integer', 'YES'],
    ];
    expect($colonnes->keys()->sort()->values()->all())->toBe(collect(array_keys($attendu))->sort()->values()->all());
    foreach ($attendu as $nom => [$type, $nullable]) {
        expect([$colonnes[$nom]->data_type, $colonnes[$nom]->is_nullable])->toBe([$type, $nullable], "colonne {$nom}");
    }

    $clePrimaire = DB::selectOne(<<<'SQL'
        SELECT a.attname AS colonne
          FROM pg_index i
          JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
         WHERE i.indrelid = 'public.job_batches'::regclass AND i.indisprimary
    SQL);
    expect($clePrimaire?->colonne)->toBe('id');

    $role = (string) config('database.connections.pgsql_app.username', 'axion_app');
    foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $droit) {
        $ok = DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS ok', [$role, 'public.job_batches', $droit]);
        expect($ok->ok)->toBeTrue("{$role} doit avoir {$droit} sur job_batches");
    }

    // Table technique, sans espace : aucune RLS qui filtrerait les lots.
    $rls = DB::selectOne("SELECT relrowsecurity AS active FROM pg_class WHERE oid = 'public.job_batches'::regclass");
    expect($rls->active)->toBeFalse();
});

test('sous axion_app, une audience au-dela du seuil part en lot, le lot va au bout et l audience est rafraichie', function () {
    [$espace, $audience] = jbSemer(3);

    try {
        jbLotsSousRoleApplicatif();
        config(['queue.default' => 'sync']);

        $precedente = DB::getDefaultConnection();
        try {
            DB::setDefaultConnection('pgsql_app');
            $code = Artisan::call('audiences:full-refresh', ['--workspace' => $espace]);
            $sortie = Artisan::output();
        } finally {
            DB::setDefaultConnection($precedente);
        }

        expect($code)->toBe(0)
            ->and($sortie)->toContain('en échec : 0')
            ->and($sortie)->not->toContain('job_batches');

        $lot = jbProprio()->table('job_batches')->where('name', "audience-refresh-{$audience}")->first();
        expect($lot)->not->toBeNull()
            ->and((int) $lot->total_jobs)->toBe(3)
            ->and((int) $lot->pending_jobs)->toBe(0)
            ->and((int) $lot->failed_jobs)->toBe(0)
            ->and($lot->finished_at)->not->toBeNull();

        $ligne = jbProprio()->table('email_audiences')->where('id', $audience)->first();
        expect($ligne->refreshed_at)->not->toBeNull()
            ->and((int) $ligne->member_count)->toBe(3)
            // Trois lots d'une fiche, en ordre stable : chacune une fois.
            ->and(jbProprio()->table('audience_members')->where('audience_id', $audience)->count())->toBe(3);
    } finally {
        jbNettoyer($espace, $audience);
    }
});

test('le rappel finally du lot survit a une lecture SANS contexte d espace, comme dans le worker', function () {
    [$espace, $audience] = jbSemer(2);

    try {
        jbLotsSousRoleApplicatif();
        // File qui garde les lots sans les exécuter : on rejoue ensuite, à la
        // main, ce que fait le worker Horizon — lire le lot hors contexte.
        config(['queue.connections.zz_nulle' => ['driver' => 'null'], 'queue.default' => 'zz_nulle']);

        $precedente = DB::getDefaultConnection();
        try {
            DB::setDefaultConnection('pgsql_app');
            Artisan::call('audiences:full-refresh', ['--workspace' => $espace]);

            $idLot = jbProprio()->table('job_batches')->where('name', "audience-refresh-{$audience}")->value('id');
            expect($idLot)->not->toBeNull();

            // Les lots auraient recalculé les membres ; on les pose.
            foreach (jbProprio()->table('companies')->where('workspace_id', $espace)->pluck('id') as $fiche) {
                jbProprio()->table('audience_members')->insert([
                    'audience_id' => $audience, 'company_id' => $fiche, 'workspace_id' => $espace, 'added_at' => now(),
                ]);
            }

            // Le worker : AUCUN contexte d'espace (Queue::looping l'efface).
            WorkspaceContext::clear();
            $lot = app(BatchRepository::class)->find($idLot);

            // Avant le correctif : le rappel capturait le modèle, son
            // rechargement échouait sous la RLS et Laravel rendait des
            // options VIDES — le rappel disparaissait sans bruit.
            expect($lot->options['finally'] ?? [])->toHaveCount(1);

            ($lot->options['finally'][0])($lot);
        } finally {
            DB::setDefaultConnection($precedente);
        }

        $ligne = jbProprio()->table('email_audiences')->where('id', $audience)->first();
        expect($ligne->refreshed_at)->not->toBeNull()
            ->and((int) $ligne->member_count)->toBe(2);
    } finally {
        jbNettoyer($espace, $audience);
    }
});
