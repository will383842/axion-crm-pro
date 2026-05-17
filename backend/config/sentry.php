<?php

/**
 * Sprint 18.8 — Sentry SDK config (compatible GlitchTip self-hosted).
 *
 * Activé seulement si SENTRY_LARAVEL_DSN est défini.
 * GlitchTip est 100% compatible avec le SDK Sentry officiel (cf. _AUDIT/MONITORING.md).
 *
 * Coût : 0€/mois (open source, self-hostable sur le même Hetzner CPX22 ou CX22 dédié).
 */
return [
    'dsn' => env('SENTRY_LARAVEL_DSN'),

    'release' => env('SENTRY_RELEASE'),

    // Environnement (sera utilisé comme filtre dans GlitchTip)
    'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV', 'production')),

    'breadcrumbs' => [
        'logs'        => true,
        'cache'       => false,
        'livewire'    => false,
        'sql_queries' => false,
        'sql_bindings'=> false,
        'queue_info'  => true,
        'command_info'=> true,
        'http_client_requests' => true,
    ],

    'tracing' => [
        'enabled' => env('SENTRY_TRACING_ENABLED', false),  // tracing désactivé par défaut (perf)
        'sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.0),
        'queue_job_transactions' => false,
        'queue_jobs' => false,
        'sql_queries' => false,
        'http_client_requests' => true,
    ],

    'send_default_pii' => false,  // RGPD : pas de PII par défaut

    // Filter known noise
    'ignore_exceptions' => [
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Validation\ValidationException::class,
        \Illuminate\Http\Exceptions\HttpResponseException::class,
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class,
    ],
];
