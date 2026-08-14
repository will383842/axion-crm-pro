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

];
