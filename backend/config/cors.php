<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth', 'docs', 'docs/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_filter(array_map('trim', explode(',', (string) env(
        'CORS_ALLOWED_ORIGINS',
        'https://app.localhost,https://app.axion-crm-pro.com',
    )))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['X-Request-Id', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'],
    'max_age' => 600,
    'supports_credentials' => true,
];
