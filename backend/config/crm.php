<?php

/*
|--------------------------------------------------------------------------
| Drapeaux du chantier « centralisation CRM » (autopilot 2026-08-13)
|--------------------------------------------------------------------------
|
| Stratégie git du chantier : fusion progressive derrière drapeaux. Tout le
| code livré avant l'activation finale doit être INERTE — c'est-à-dire que,
| tous drapeaux à OFF, le comportement observable est identique à celui d'avant
| la PR. Le rollback d'un lot est alors « remettre le drapeau à OFF », sans
| revert ni restauration de base.
|
| Référence : _PLANS/2026-08-13_ORDRE-MISSION-AUTOPILOT-CRM.md
|             (§ STRATÉGIE GIT — FUSION PROGRESSIVE DERRIÈRE DRAPEAUX)
|
*/

return [

    /*
    | L0 — isolation.
    |
    | `db_app_role` : quand ce drapeau est à true, l'application se connecte à
    | Postgres avec le rôle NON-PROPRIÉTAIRE `axion_app` au lieu du rôle
    | `axion` (qui est SUPERUSER + BYPASSRLS : vérifié en prod le 2026-08-14,
    | `SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname='axion'` →
    | t / t). C'est CE drapeau qui rend la Row Level Security réellement
    | opérante : tant qu'il est à false, les policies — même en FORCE — sont
    | intégralement contournées par le superutilisateur, donc le durcissement
    | SQL est inerte.
    |
    | Les migrations continuent de tourner avec le rôle propriétaire
    | (connexion `pgsql_owner`, cf. config/database.php).
    */
    'db_app_role' => env('CRM_DB_APP_ROLE_ENABLED', false),

    /*
    | `strict_workspace_scope` : ceinture applicative (la RLS est la bretelle).
    | Quand il est à true :
    |   - les modèles workspace-scopés portent un global scope Eloquent qui
    |     filtre sur le workspace courant ;
    |   - une lecture/écriture SANS contexte workspace lève
    |     MissingWorkspaceContextException au lieu de renvoyer silencieusement
    |     zéro ligne.
    |
    | Ce second point est le cœur du piège identifié à la contre-vérification :
    | avec des policies strictes, un job Horizon ou une commande artisan qui
    | « oublie » de poser le contexte ne voit AUCUNE ligne et ne fait donc RIEN,
    | tout en sortant en succès. Un cron vert qui ne purge rien est le pire des
    | échecs — on exige donc un échec BRUYANT.
    */
    'strict_workspace_scope' => env('CRM_STRICT_WORKSPACE_SCOPE', false),

    /*
    | L2 — ingestion site → CRM (`POST /api/internal/site-sync`).
    |
    | `enabled` : drapeau MAÎTRE. À false (défaut), l'endpoint répond 503
    | `ingest_disabled` et n'écrit RIEN — la route existe, le code est livré,
    | mais la tuyauterie est inerte. Rollback = remettre à false.
    |
    | `candidates_enabled` : second verrou, propre à l'univers VIVIER. Les flux
    | candidats ne s'ouvrent qu'APRÈS que les textes de consentement v2 sont
    | servis en production sur le site (séquencement croisé imposé par l'ordre
    | de mission). Même avec ce drapeau à true, une fiche candidat sans version
    | de consentement v2 est REJETÉE — le drapeau ouvre le flux, il ne dispense
    | jamais de la preuve du consentement.
    |
    | `hmac_secret` : secret partagé du canal site→CRM (64 hex). Absent =
    | aucune requête ne peut être authentifiée (l'endpoint répond 401), ce qui
    | est le bon état par défaut d'un serveur qui n'a pas encore reçu le secret.
    |
    | `business_workspace` : slug du workspace commercial de destination. Le
    | site n'a PAS à connaître les workspaces du CRM : la destination est une
    | décision du CRM, pas une donnée du payload (sinon un appelant compromis
    | choisirait l'univers d'atterrissage de ses fiches, vivier compris).
    */
    'ingest' => [
        'enabled' => env('CRM_INGEST_ENABLED', false),
        'candidates_enabled' => env('CRM_INGEST_CANDIDATES_ENABLED', false),
        'hmac_secret' => env('SITE_SYNC_HMAC_SECRET', ''),
        'business_workspace' => env('CRM_INGEST_BUSINESS_WORKSPACE', 'axion-ia'),
        // Fenêtre de tolérance de l'horodatage signé (anti-rejeu). 0 = contrôle
        // désactivé (le site ne signe pas encore d'horodatage).
        'max_clock_skew_seconds' => (int) env('CRM_INGEST_MAX_CLOCK_SKEW', 300),
    ],

    /*
    | L3 — funnel d'ingestion de la COLLECTE (schéma pivot ScrapedRecord).
    |
    | `enabled` : à false (défaut), `POST /internal/scraper-result` garde son
    | comportement HISTORIQUE (log-only, 200 `ingested: true`) — le funnel est
    | livré mais inerte, fusion sans risque. À true, le même endpoint valide le
    | pivot, ingère (registre → idempotence → dédup → backfill-only → tags →
    | timeline) et répond avec l'outcome. Les autres portes (waterfall PHP,
    | `scraping:ingest-file`) ne dépendent PAS de ce drapeau : elles n'ont pas
    | de comportement historique à préserver.
    |
    | `validate_mx` : validation DNS des emails collectés (« jamais confiance
    | au collecteur »). false en tests — aucun appel réseau réel, même règle
    | que les MOCK_*.
    */
    'scrape_funnel' => [
        'enabled' => env('CRM_SCRAPE_FUNNEL_ENABLED', false),
        'validate_mx' => env('CRM_SCRAPE_VALIDATE_MX', true),
    ],

    /*
    | Backfill des tags `src:scraping-*` sur le stock (4,29 M de companies,
    | décision 2 de l'audit scraping). DERNIER acte de la mission (ordre de
    | mission §5) : la commande `scraping:backfill-src-tags` refuse tant que ce
    | drapeau n'est pas à true.
    */
    'backfill_enabled' => env('CRM_BACKFILL_ENABLED', false),

];
