<?php

/*
|------------------------------------------------------------------------------
| TELESCOPE EST ETEINT PAR DEFAUT — constats A-007 / F40-003 (S1)
|------------------------------------------------------------------------------
|
| ── POURQUOI CE FICHIER EXISTE ────────────────────────────────────────────────
|
| Il n'existait pas. C'ETAIT LE DEFAUT.
|
| `backend/config/` comptait 20 fichiers et aucun `telescope.php` : la
| configuration servie etait donc celle du paquet, dont la ligne 19 dit
|
|     'enabled' => env('TELESCOPE_ENABLED', true),      ← VRAI par defaut
|
| et `laravel/telescope` est une dependance DURE (`composer.json:21`, section
| `require` — donc installee meme par `composer install --no-dev`). Aucune
| migration Telescope n'existe dans `backend/database/migrations/`. Telescope
| enregistre a la TERMINAISON de chaque requete et de chaque commande artisan,
| et chacun de ces enregistrements echouait :
|
|     SQLSTATE[42P01] relation "telescope_entries" does not exist
|
| Cout mesure en production : 270 Mo de journal, ~90 Mo par jour, 100 % du meme
| defaut. Un journal qui ne contient qu'une seule erreur repetee n'est plus un
| journal : c'est un disque qui se remplit, et un signal qu'on apprend a ignorer.
|
| ── LE PIEGE, ET IL AVAIT DEJA COUTE LE DOSSIER UNE FOIS ──────────────────────
|
| Le depot croyait le probleme traite. `App\Providers\TelescopeServiceProvider`
| sort de `register()` ET de `boot()` quand `env('TELESCOPE_ENABLED', false)` est
| faux, et son docblock affirme « Telescope est desactive par defaut en prod ».
|
| Ca ne protegeait RIEN. `composer.json` porte `extra.laravel.dont-discover: []`,
| donc le fournisseur du PAQUET — `Laravel\Telescope\TelescopeServiceProvider` —
| est decouvert automatiquement et s'enregistre A COTE de celui de
| l'application. Lui ne lit pas `env()`, il lit `config('telescope.enabled')`,
| et il appelle `Telescope::start()`. Mesure du 2026-08-20, processus neuf :
|
|     $ env -u TELESCOPE_ENABLED APP_ENV=production php sonde-telescope.php
|     enabled=true
|     fournisseurs=Laravel\Telescope\TelescopeServiceProvider,App\Providers\TelescopeServiceProvider
|     enregistre=true
|
| Le court-circuit applicatif economisait quelques `require` pendant que le
| fournisseur du paquet demarrait Telescope juste a cote. Un correctif qui a
| l'air d'en etre un est pire qu'un defaut nu : il ferme le dossier.
|
| ── POURQUOI UNE FUSION ET PAS UNE COPIE DE LA CONFIGURATION DU PAQUET ────────
|
| `php artisan vendor:publish --tag=telescope-config` aurait copie ~180 lignes
| ici, dont la liste des observateurs, figee a la version 5.22.1. Une seule de
| ces lignes est en cause. On reprend donc la configuration du paquet telle
| quelle et on n'ecrase QUE `enabled` : le reste continue de suivre le paquet a
| chaque mise a jour, et la revue de ce fichier montre exactement ce qui change.
|
| ⚠️ SI QUELQU'UN JOUE `vendor:publish --tag=telescope-config` UN JOUR, il
| ECRASERA ce fichier et remettra `env('TELESCOPE_ENABLED', true)` — c'est-a-dire
| le defaut. La garde `tests/Feature/Infra/TelescopeDesactiveParDefautTest.php`
| le verra : elle DEMARRE l'application et interroge `Telescope::isRecording()`,
| elle ne relit pas ce fichier.
|
| ── CE QUI N'EST PAS FERME PAR CE FICHIER ─────────────────────────────────────
|
| Le drapeau ne cree pas les tables. Poser `TELESCOPE_ENABLED=true` sans jouer
| `php artisan telescope:install` puis `migrate` reproduit EXACTEMENT le defaut
| de production. Telescope reste activable deliberement — c'est voulu, une garde
| qui vole son outil a celui qui s'en sert finit par etre retiree en entier —
| mais l'activer sans migrer reste une faute.
*/

$configurationDuPaquet = base_path('vendor/laravel/telescope/config/telescope.php');

/*
 * Repli si le paquet disparait de `composer.json` un jour : sans ce garde-fou,
 * ce fichier serait une erreur fatale au demarrage, y compris en production.
 * Le repli conserve la seule chose qui compte ici — l'extinction.
 */
$defauts = is_file($configurationDuPaquet) ? require $configurationDuPaquet : [];

return array_merge($defauts, [

    /*
     * 🔴 LA SEULE LIGNE QUI CHANGE PAR RAPPORT AU PAQUET.
     *
     * Defaut FALSE au lieu de TRUE. Les deux lectures — celle du fournisseur
     * du paquet (`config('telescope.enabled')`) et celle du fournisseur
     * applicatif (`env('TELESCOPE_ENABLED', false)`) — s'accordent enfin sur
     * la meme valeur.
     *
     * ⚠️ `filter_var(..., FILTER_VALIDATE_BOOLEAN)` et NON `(bool)`, meme
     * lecon que `MockServicesProvider::drapeau()` et que `config/app.php` :
     * `(bool) "off"` vaut **true** en PHP. `env()` normalise `"false"`, mais
     * pas `"off"`, `"no"` ni `"0.0"` — un operateur qui ecrit
     * `TELESCOPE_ENABLED=off` en croyant eteindre ALLUMERAIT.
     */
    'enabled' => filter_var(env('TELESCOPE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
]);
