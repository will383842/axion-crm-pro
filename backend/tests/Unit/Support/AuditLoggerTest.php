<?php

use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

it('skips silently when workspace_id missing', function () {
    Log::shouldReceive('debug')->once();
    // DB::table should not be touched
    expect(fn () => AuditLogger::log('audience.refreshed', ['name' => 'foo']))
        ->not->toThrow(\Throwable::class);
});

it('fails open when DB insert throws (table missing)', function () {
    DB::shouldReceive('table')->andThrow(new \RuntimeException('relation missing'));
    Log::shouldReceive('warning')->once();
    expect(fn () => AuditLogger::log('audience.refreshed', [
        'workspace_id' => 'ws-1',
        'name' => 'foo',
    ]))->not->toThrow(\Throwable::class);
});
