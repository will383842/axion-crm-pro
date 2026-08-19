<?php

return [
    'default' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Transport des courriels D'AUTHENTIFICATION
    |--------------------------------------------------------------------------
    |
    | Le lien magique et la reinitialisation de mot de passe ne sont pas du
    | courrier commercial : ce sont les deux seules portes de secours d'un compte.
    |
    | La decision « MAIL_MAILER reste `log` » est respectee et reste le defaut.
    | Mais elle coupait AUSSI ces deux portes - personne ne l'avait vu, et c'est
    | l'une des raisons pour lesquelles personne ne s'est jamais connecte au CRM
    | en production. Laravel permet de designer un transport par envoi : la
    | decision tient donc SANS exception, et sans qu'aucun courriel commercial ne
    | parte.
    |
    | Poser `MAIL_MAILER_AUTH=smtp` laisse sortir ces deux courriels, et EUX
    | SEULS. Tant que la variable n'est pas posee, le comportement est identique
    | a aujourd'hui.
    |
    */
    'auth_mailer' => env('MAIL_MAILER_AUTH', env('MAIL_MAILER', 'log')),

    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],
        'ses' => ['transport' => 'ses'],
        'postmark' => ['transport' => 'postmark'],
        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],
        'array' => ['transport' => 'array'],
        'failover' => [
            'transport' => 'failover',
            'mailers' => ['smtp', 'log'],
        ],
    ],

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'noreply@axion-crm.local'),
        'name' => env('MAIL_FROM_NAME', 'Axion CRM Pro'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks du service d'envoi (étape 0, ligne 3 ter — F18)
    |--------------------------------------------------------------------------
    | Jeton partagé attendu dans l'URL du webhook ZeptoMail (`?t=`). Absent ⇒
    | la route répond 503 et n'écrit rien (inertie). Voir
    | `App\Http\Controllers\Internal\ZeptoMailWebhookController`.
    */
    'webhooks' => [
        'zeptomail_token' => env('MAIL_WEBHOOK_TOKEN'),
    ],

];
