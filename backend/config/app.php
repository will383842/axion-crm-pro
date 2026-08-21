<?php

/*
|------------------------------------------------------------------------------
| LE DEBOGAGE NE SORT PLUS DU POSTE DE DEVELOPPEMENT — constat F37-003 (S1)
|------------------------------------------------------------------------------
|
| Cette ligne valait `(bool) env('APP_DEBUG', false)`. Elle laissait donc
| `APP_DEBUG=true` prendre effet PARTOUT, y compris en production. Mesure du
| defaut, dans un processus neuf, le 2026-08-20 :
|
|     $ env -u APP_DEBUG -u APP_ENV APP_ENV=production APP_DEBUG=true php sonde.php
|     debug=true
|
| Et ce n'etait pas theorique : `docker-compose.staging.yml:125` posait
| `APP_DEBUG: 'true'` sur la preproduction, avec le commentaire « Elle ne sert
| aucun public ». Le Caddyfile dit l'inverse — `staging.axion-crm-pro.com` et
| `staging-api.axion-crm-pro.com` (lignes 244 et 279) sont servis par le Caddy de
| PRODUCTION, sur des noms publics, SANS basic_auth ni liste d'adresses
| autorisees. Le seul en-tete de restriction est un `X-Robots-Tag: noindex`, qui
| parle aux moteurs de recherche et a personne d'autre.
|
| Ce qu'une page de debogage Laravel affiche a qui provoque une 500 : la valeur
| de CHAQUE variable d'environnement du processus (`DB_PASSWORD`, `APP_KEY`,
| jetons tiers), la configuration resolue, la requete SQL fautive avec ses
| parametres, et le code source de tout le chemin d'appel.
|
| ── DEUX CHOIX, ET LEURS RAISONS ─────────────────────────────────────────────
|
| 1. `filter_var(..., FILTER_VALIDATE_BOOLEAN)` et NON `(bool)`. Portage de la
|    lecon deja payee dans `MockServicesProvider::drapeau()` : `(bool) "off"`
|    vaut **true** en PHP, comme `(bool) "0.0"` ou `(bool) "no"`. `env()`
|    normalise `"false"` mais pas ces formes-la — un operateur qui ecrit
|    `APP_DEBUG=off` en croyant desactiver l'ACTIVAIT.
|
| 2. Le refus n'est PAS contournable par une variable. Rendre l'exception
|    configurable, ce serait reconstruire le defaut qu'on repare : il suffirait
|    d'un drapeau oublie dans un `.env` pour rouvrir la porte. `testing` figure
|    dans la liste parce que la suite de tests a besoin des traces completes ;
|    `local` parce que c'est l'outil quotidien des developpeurs, et qu'une garde
|    qui leur vole leur outil finit par etre retiree en entier.
|
| ⚠️ `debug_refuse` existe pour que le refus NE SOIT PAS SILENCIEUX (cf.
| `AppServiceProvider::boot()`). Elle est calculee ici, dans la configuration,
| et non dans le fournisseur : en production `config:cache` est actif et
| `env()` hors configuration rend `null` — un signal bati sur `env('APP_DEBUG')`
| dans un fournisseur ne se declencherait donc JAMAIS la ou il sert.
*/

$environnement = env('APP_ENV', 'production');

$debogageDemande = filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN);

// Les seuls environnements ou une trace complete ne peut atteindre personne
// d'autre que celui qui l'a provoquee.
$debogageAutorise = in_array($environnement, ['local', 'testing'], true);

return [
    'name' => env('APP_NAME', 'Axion CRM Pro'),
    'env' => $environnement,
    'debug' => $debogageDemande && $debogageAutorise,

    /*
     * Vrai quand le debogage a ete DEMANDE et REFUSE : c'est le signe d'une
     * configuration de deploiement fautive, que `AppServiceProvider` journalise
     * au niveau critique. Le silence ici redonnerait le defaut d'origine, qui
     * etait precisement que personne ne disait rien.
     */
    'debug_refuse' => $debogageDemande && ! $debogageAutorise,

    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => env('APP_TIMEZONE', 'Europe/Paris'),
    'locale' => env('APP_LOCALE', 'fr'),
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'fr'),
    'faker_locale' => env('APP_FAKER_LOCALE', 'fr_FR'),
    'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY'),
    'previous_keys' => array_filter(explode(',', (string) env('APP_PREVIOUS_KEYS', ''))),
    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
    ],
];
