<?php

use Illuminate\Support\Str;

return [
    'domain' => env('HORIZON_DOMAIN'),
    'path'   => env('HORIZON_PATH', 'horizon'),
    'use'    => 'default',
    'prefix' => env('HORIZON_PREFIX', Str::slug((string) env('APP_NAME', 'axion-crm'), '_') . '_horizon:'),
    'middleware' => ['web', 'auth'],
    'waits'   => ['redis:default' => 60, 'redis:scrape:google-maps' => 120, 'redis:scrape:google-search' => 120],
    'trim'    => ['recent' => 60, 'pending' => 60, 'completed' => 60, 'recent_failed' => 10080, 'failed' => 10080, 'monitored' => 10080],
    'silenced' => [],
    'metrics' => ['trim_snapshots' => ['job' => 24, 'queue' => 24]],
    'fast_termination' => false,
    'memory_limit' => 256,
    'defaults' => [
        'supervisor-default' => [
            'connection' => 'redis',
            'queue'      => ['default'],
            'balance'    => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 6,
            'maxTime'    => 0,
            'maxJobs'    => 0,
            'memory'     => 256,
            'tries'      => 3,
            'timeout'    => 600,
            'nice'       => 0,
        ],
        'supervisor-scraping' => [
            'connection' => 'redis',
            'queue'      => ['scrape:insee', 'scrape:annuaire-entreprises', 'scrape:bodacc'],
            'balance'    => 'auto',
            'maxProcesses' => 4,
            'tries'      => 3,
            'timeout'    => 900,
        ],
        // Sprint H5 — Supervisor dédié refresh audiences (Bus::batch parallèle)
        // Dimensionnement : 10 workers max prod = 50K companies / chunk × 10 / 60s ≈ 8M / heure
        'supervisor-audiences-refresh' => [
            'connection' => 'redis',
            'queue'      => ['audiences-refresh'],
            'balance'    => 'auto',
            'maxProcesses' => 4,
            'tries'      => 2,
            'timeout'    => 600,
        ],
    ],
    'environments' => [
        'production' => [
            'supervisor-default'             => ['maxProcesses' => 10, 'balanceMaxShift' => 1, 'balanceCooldown' => 3],
            'supervisor-scraping'            => ['maxProcesses' => 8],
            'supervisor-audiences-refresh'   => ['maxProcesses' => 10],
        ],
        'local' => [
            'supervisor-default'             => ['maxProcesses' => 3],
            'supervisor-scraping'            => ['maxProcesses' => 2],
            'supervisor-audiences-refresh'   => ['maxProcesses' => 2],
        ],
    ],
];
