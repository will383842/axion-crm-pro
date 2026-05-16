<?php

return [
    'default' => env('CACHE_STORE', 'redis'),
    'stores' => [
        'array'   => ['driver' => 'array', 'serialize' => false],
        'database'=> ['driver' => 'database', 'connection' => env('DB_CACHE_CONNECTION'), 'table' => 'cache', 'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'), 'lock_table' => env('DB_CACHE_LOCK_TABLE')],
        'file'    => ['driver' => 'file', 'path' => storage_path('framework/cache/data')],
        'redis'   => ['driver' => 'redis', 'connection' => env('REDIS_CACHE_CONNECTION', 'cache'), 'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default')],
    ],
    'prefix' => env('CACHE_PREFIX', str_replace([' ', '/'], '_', strtolower((string) env('APP_NAME', 'axion-crm'))) . '_cache_'),
];
