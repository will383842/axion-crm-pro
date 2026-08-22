<?php

return [
    'defaults' => [
        'guard'     => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    'guards' => [
        'web' => [
            'driver'   => 'session',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model'  => env('AUTH_MODEL', App\Models\User::class),
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table'    => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire'   => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

    /**
     * P5-35-010 — porte de sortie du contrôle HaveIBeenPwned.
     *
     * F35-004 avait fermé un fail-open silencieux : quand l'API HIBP est
     * injoignable, `NotPwnedPassword` refuse le mot de passe. C'est le bon
     * défaut, et il le reste. Mais la règle n'est branchée qu'à UN endroit —
     * `PasswordResetController` — qui est aussi le SEUL point de choix d'un mot
     * de passe du produit (vérifié le 2026-08-22 : `Hash::make` n'apparaît
     * ailleurs que sur les codes de secours 2FA et le haché factice
     * anti-énumération). Une panne DNS ou un pare-feu sortant suspend donc, à
     * lui seul, toute possibilité de reprendre la main sur un compte — y
     * compris pendant l'incident où l'on en aurait le plus besoin.
     *
     * Deux valeurs, et deux seulement :
     *  · `closed`      — DÉFAUT. Service indisponible ⇒ refus. Rien ne change.
     *  · `open-audited` — bascule MANUELLE, temporaire, d'exploitation : le mot
     *    de passe est accepté ET une ligne `auth.hibp.fail_open` part au niveau
     *    `alert`. Jamais silencieux : c'est précisément le silence qui faisait
     *    de F35-004 un défaut.
     *
     * ⚠️ Toute autre valeur (typo, `open`, chaîne vide) est traitée comme
     * `closed`. Un drapeau mal orthographié ne doit pas rouvrir le contrôle.
     */
    'hibp' => [
        'fail_mode' => env('HIBP_FAIL_MODE', 'closed'),
    ],
];
