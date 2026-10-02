<?php

return [
    'driver' => env('SESSION_DRIVER', 'redis'),
    // 12 h par défaut (était 120 min) — constat prod du 2026-10-02 : le
    // propriétaire, entré par lien magique, était déconnecté toutes les deux
    // heures. Une journée de travail tient désormais dans une session ; le
    // « se souvenir de moi » prend le relais au-delà.
    'lifetime' => (int) env('SESSION_LIFETIME', 720),
    'expire_on_close' => false,
    'encrypt' => true,
    'files' => storage_path('framework/sessions'),
    'connection' => env('SESSION_CONNECTION', 'default'),
    'table' => env('SESSION_TABLE', 'sessions'),
    'store' => env('SESSION_STORE'),
    'lottery' => [2, 100],
    'cookie' => env('SESSION_COOKIE', 'axion_crm_session'),
    'path' => '/',
    'domain' => env('SESSION_DOMAIN'),
    'secure' => env('SESSION_SECURE_COOKIE', false),
    'http_only' => true,
    'same_site' => env('SESSION_SAME_SITE', 'lax'),
    'partitioned' => false,
];
