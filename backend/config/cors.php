<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth', 'docs', 'docs/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    // ⚠️ CONSTAT F37-005 (S3) — ce défaut vaut pour la PRODUCTION, et pour elle seule.
    //
    // Il portait `https://app.localhost,https://app.axion-crm-pro.com`. Or, mesure
    // du 2026-08-22 : `CORS_ALLOWED_ORIGINS` n'était posée NULLE PART dans le dépôt
    // — ni `.env.example`, ni `docker-compose.prod.yml`, ni
    // `infra/scripts/configure-prod-env.sh`, ni le workflow de déploiement. Une
    // recherche sur tout l'arbre ne rendait que cette ligne-ci. Rien ne fournissait
    // donc la variable au conteneur : c'est le défaut qui s'appliquait, et la
    // production répondait `Access-Control-Allow-Origin: https://app.localhost`
    // avec `supports_credentials` à `true` (ligne plus bas).
    //
    // `app.localhost` est une origine que N'IMPORTE QUI peut faire résoudre chez
    // lui vers sa propre machine. Couplée aux cookies, elle fait d'un poste
    // attaquant une origine de confiance de l'API de production.
    //
    // Le développement local reçoit la variable par `docker-compose.local.yml`
    // (bloc `x-socle-local`) et par `.env.example` : la retirer d'ici SANS l'y
    // poser casserait la console locale, qui repose dessus.
    'allowed_origins' => array_filter(array_map('trim', explode(',', (string) env(
        'CORS_ALLOWED_ORIGINS',
        'https://app.axion-crm-pro.com',
    )))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['X-Request-Id', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'],
    'max_age' => 600,
    'supports_credentials' => true,
];
