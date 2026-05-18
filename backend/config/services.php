<?php

/*
|--------------------------------------------------------------------------
| Third Party Services — Axion CRM Pro
|--------------------------------------------------------------------------
|
| Created by Sprint Hardening Pipeline 360° (H1-H6, 2026-05-17).
| Auparavant les services lisaient env() directement ; ce fichier les
| consolide pour permettre cache de config en prod + tests injection.
|
*/

return [

    /* ---------------------------------------------------------------------
     | Brave Search API (Sprint H1)
     | https://api.search.brave.com/app/keys — 2000 req/mois free, puis $5/1K
     | --------------------------------------------------------------------- */
    'brave' => [
        'api_key' => env('BRAVE_SEARCH_API_KEY'),
    ],

    /* ---------------------------------------------------------------------
     | Hunter.io email verification (Sprint H2)
     | https://hunter.io/api-keys — 25 vérifs/mois free, $34/mo 1K vérifs
     | --------------------------------------------------------------------- */
    'hunter' => [
        'api_key' => env('HUNTER_API_KEY'),
    ],

    /* ---------------------------------------------------------------------
     | Webshare proxies (Sprint H1 — Phase B Pages Jaunes scraping)
     | Désactivé par défaut. À activer quand Will valide Phase A.
     | --------------------------------------------------------------------- */
    'webshare' => [
        'enabled'  => env('WEBSHARE_ENABLED', false),
        'username' => env('WEBSHARE_USERNAME'),
        'password' => env('WEBSHARE_PASSWORD'),
        'endpoint' => env('WEBSHARE_ENDPOINT', 'p.webshare.io:80'),
    ],

    /* ---------------------------------------------------------------------
     | France Travail (déjà utilisé par FranceTravailDiscoveryClient)
     | --------------------------------------------------------------------- */
    'france_travail' => [
        'client_id'     => env('FRANCE_TRAVAIL_CLIENT_ID'),
        'client_secret' => env('FRANCE_TRAVAIL_CLIENT_SECRET'),
    ],

    /* ---------------------------------------------------------------------
     | INSEE Sirene
     | --------------------------------------------------------------------- */
    'insee' => [
        'base_url'      => env('INSEE_API_BASE_URL', 'https://api.insee.fr'),
        'api_key'       => env('INSEE_API_KEY'),
        'client_id'     => env('INSEE_CLIENT_ID'),
        'client_secret' => env('INSEE_CLIENT_SECRET'),
    ],

    /* ---------------------------------------------------------------------
     | Flags scrapers (cf .env.example MOCK_SCRAPERS)
     | Quand MOCK_SCRAPERS=true → DomainFinderService skip Pages Jaunes
     | --------------------------------------------------------------------- */
    'scrapers' => [
        'mock' => env('MOCK_SCRAPERS', true),
    ],

    /* ---------------------------------------------------------------------
     | EmailFinder spéculatif (Sprint H7 — 2026-05-18)
     | Quand FALSE (défaut) : EmailFinderService::find() ne génère plus les 18
     | patterns spéculatifs. Seuls les emails RÉELS scrapés depuis le HTML
     | (mentions-légales) sont stockés, validés via MxEmailValidator.
     |
     | À activer UNIQUEMENT quand un vérificateur SMTP externe (Hunter,
     | ZeroBounce, NeverBounce) est wired pour trancher entre les candidats.
     | --------------------------------------------------------------------- */
    'email_finder' => [
        'speculative_enabled' => env('EMAIL_FINDER_SPECULATIVE_ENABLED', false),
    ],

];
