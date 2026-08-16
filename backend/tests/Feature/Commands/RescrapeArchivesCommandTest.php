<?php

use App\Jobs\EnrichCompanyJob;
use App\Models\Company;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeRescrapeWorkspace(): Workspace
{
    return Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'rs-' . Str::random(6),
        'name' => 'WS Rescrape',
    ]);
}

/**
 * Crée une company archivée dont `updated_at` est réellement antidaté.
 *
 * DEUX pièges, tous deux silencieux, qui faisaient que la fixture était en
 * réalité datée de `now()` — donc jamais éligible au filtre d'âge, d'où le
 * « pushed 0 times » historique :
 *
 *  1. `updated_at` n'est PAS dans `Company::$fillable` (ni `id`, qui est un
 *     bigint auto-incrémenté) : passé à `Company::create()`, il est supprimé
 *     par la protection d'assignation de masse et Eloquent repose `now()`.
 *     → on le pose via `forceFill()`, ce qui le rend « dirty » et empêche
 *       `updateTimestamps()` de l'écraser.
 *  2. La table porte un trigger Postgres `companies_updated_at`
 *     (BEFORE UPDATE → `NEW.updated_at = now()`, inconditionnel) : antidater
 *     par un UPDATE est IMPOSSIBLE. Il n'existe pas de trigger équivalent sur
 *     INSERT → la valeur doit être posée au moment de l'insertion.
 */
function makeArchivedCompany(Workspace $ws, string $siren, string $denomination, int $ageDays): Company
{
    $company = new Company([
        'workspace_id' => $ws->id,
        'siren' => $siren,
        'denomination' => $denomination,
        'prospection_status' => 'archived_no_email',
        'archive_reason' => 'no_email',
    ]);

    $backdated = now()->subDays($ageDays);
    $company->forceFill(['created_at' => $backdated, 'updated_at' => $backdated]);
    $company->save();

    // Garde-fou : si l'antidatage venait à sauter (fillable élargi, trigger
    // ajouté sur INSERT…), le test doit le dire ici et pas via un « 0 job ».
    $persisted = DB::table('companies')->where('id', $company->id)->value('updated_at');
    expect(now()->diffInDays($persisted, true))->toBeGreaterThanOrEqual($ageDays - 1);

    return $company;
}

/**
 * Sprint H6 — RescrapeArchivesCommand.
 * Tests : dispatch correct, age filter, dry-run, invalid params.
 */
it('dispatches only companies older than --age-days threshold', function () {
    Queue::fake();
    $workspace = makeRescrapeWorkspace();

    // 5 companies "vieilles" (35j+) → doivent être dispatched
    for ($i = 0; $i < 5; $i++) {
        makeArchivedCompany(
            $workspace,
            str_pad((string) (100000000 + $i), 9, '0', STR_PAD_LEFT),
            'Old #' . $i,
            35,
        );
    }

    // 5 companies "récentes" (10j) → ne doivent PAS être dispatched
    for ($i = 0; $i < 5; $i++) {
        makeArchivedCompany(
            $workspace,
            str_pad((string) (200000000 + $i), 9, '0', STR_PAD_LEFT),
            'Young #' . $i,
            10,
        );
    }

    $exitCode = $this->artisan('companies:rescrape-archives', [
        '--limit' => 100,
        '--age-days' => 30,
    ])->run();

    expect($exitCode)->toBe(0);
    Queue::assertPushed(EnrichCompanyJob::class, 5);
});

it('respects --workspace filter', function () {
    Queue::fake();
    $wsA = makeRescrapeWorkspace();
    $wsB = makeRescrapeWorkspace();

    foreach ([$wsA, $wsB] as $ws) {
        makeArchivedCompany(
            $ws,
            str_pad((string) random_int(100000000, 999999999), 9, '0', STR_PAD_LEFT),
            'X',
            40,
        );
    }

    $this->artisan('companies:rescrape-archives', ['--workspace' => $wsA->id])->assertExitCode(0);
    Queue::assertPushed(EnrichCompanyJob::class, 1);
});

it('--dry-run does not push any job', function () {
    Queue::fake();
    $workspace = makeRescrapeWorkspace();
    makeArchivedCompany($workspace, '111111111', 'DryRun co', 40);

    // La company EST éligible (40 j > 30 j) : sans --dry-run elle serait
    // dispatchée. Le test ne vaut donc que parce que la sélection est non vide.
    $this->artisan('companies:rescrape-archives', ['--dry-run' => true])->assertExitCode(0);
    Queue::assertNothingPushed();
});

it('rejects invalid limit / reason', function () {
    $this->artisan('companies:rescrape-archives', ['--limit' => 999999])
        ->assertExitCode(2);  // self::INVALID
    $this->artisan('companies:rescrape-archives', ['--reason' => 'invalid-foo'])
        ->assertExitCode(2);
});
