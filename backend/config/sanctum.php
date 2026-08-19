<?php

use Laravel\Sanctum\Sanctum;

return [
    'stateful' => explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort()
    ))),

    'guard' => ['web'],

    // Duree de vie d'un jeton d'API, en minutes. Valait `null` : aucun jeton
    // n'expirait jamais, et `sanctum:prune-expired` n'avait rien a elaguer. Un
    // jeton qui fuit restait valable pour toujours, sauf revocation manuelle -
    // et aucun ecran n'en propose. Mesure (audit 360, F35-010).
    // 30 jours par defaut ; ajustable sans redeploiement.
    'expiration' => (int) env('SANCTUM_TOKEN_TTL_MINUTES', 43200),

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'middleware' => [
        'authenticate_session'      => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies'           => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token'       => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],
];
