<?php

return [
    'default' => env('MAIL_MAILER', 'log'),

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
