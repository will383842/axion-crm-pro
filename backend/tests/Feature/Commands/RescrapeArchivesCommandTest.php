<?php

use App\Jobs\EnrichCompanyJob;
use App\Models\Company;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeRescrapeWorkspace(): Workspace
{
    return Workspace::create([
        'id'   => (string) Str::uuid(),
        'slug' => 'rs-' . Str::random(6),
        'name' => 'WS Rescrape',
    ]);
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
        Company::create([
            'id'                 => (string) Str::uuid(),
            'workspace_id'       => $workspace->id,
            'siren'              => str_pad((string) (100000000 + $i), 9, '0', STR_PAD_LEFT),
            'denomination'       => 'Old #' . $i,
            'prospection_status' => 'archived_no_email',
            'archive_reason'     => 'no_email',
            'updated_at'         => now()->subDays(35),
        ]);
    }

    // 5 companies "récentes" (10j) → ne doivent PAS être dispatched
    for ($i = 0; $i < 5; $i++) {
        Company::create([
            'id'                 => (string) Str::uuid(),
            'workspace_id'       => $workspace->id,
            'siren'              => str_pad((string) (200000000 + $i), 9, '0', STR_PAD_LEFT),
            'denomination'       => 'Young #' . $i,
            'prospection_status' => 'archived_no_email',
            'archive_reason'     => 'no_email',
            'updated_at'         => now()->subDays(10),
        ]);
    }

    $exitCode = $this->artisan('companies:rescrape-archives', [
        '--limit'    => 100,
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
        Company::create([
            'id'                 => (string) Str::uuid(),
            'workspace_id'       => $ws->id,
            'siren'              => str_pad((string) random_int(100000000, 999999999), 9, '0', STR_PAD_LEFT),
            'denomination'       => 'X',
            'prospection_status' => 'archived_no_email',
            'archive_reason'     => 'no_email',
            'updated_at'         => now()->subDays(40),
        ]);
    }

    $this->artisan('companies:rescrape-archives', ['--workspace' => $wsA->id])->assertExitCode(0);
    Queue::assertPushed(EnrichCompanyJob::class, 1);
});

it('--dry-run does not push any job', function () {
    Queue::fake();
    $workspace = makeRescrapeWorkspace();
    Company::create([
        'id'                 => (string) Str::uuid(),
        'workspace_id'       => $workspace->id,
        'siren'              => '111111111',
        'denomination'       => 'DryRun co',
        'prospection_status' => 'archived_no_email',
        'archive_reason'     => 'no_email',
        'updated_at'         => now()->subDays(40),
    ]);

    $this->artisan('companies:rescrape-archives', ['--dry-run' => true])->assertExitCode(0);
    Queue::assertNothingPushed();
});

it('rejects invalid limit / reason', function () {
    $this->artisan('companies:rescrape-archives', ['--limit' => 999999])
        ->assertExitCode(2);  // self::INVALID
    $this->artisan('companies:rescrape-archives', ['--reason' => 'invalid-foo'])
        ->assertExitCode(2);
});
