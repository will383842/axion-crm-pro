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
| ── UN DEFAUT N'EST PAS UN REFUS — ce que la premiere reparation laissait ouvert
|
| Mesure du 2026-08-21, processus neuf, sur le depot DEJA corrige :
|
|     $ env -u TELESCOPE_ENABLED -u APP_ENV \
|         APP_ENV=production TELESCOPE_ENABLED=true php sonde-telescope.php
|     app_env='production'
|     enabled=true
|     enregistre=true
|
| La production tout entiere ne tenait donc qu'a l'ABSENCE d'une variable. Or
| c'est precisement par une variable posee de travers que ce depot se blesse :
| `MOCK_MODE` qui remplissait la base de classifications fabriquees (C18-016),
| `APP_DEBUG: 'true'` sur deux noms PUBLICS (F37-003), et `docker compose
| restart` qui ne relit pas `env_file` (A07-003) — donc une valeur fausse qui
| survit a ce qu'on croit etre sa correction.
|
| Un `TELESCOPE_ENABLED=true` colle dans un `.env` de production « le temps de
| regarder quelque chose » rouvre le constat en entier. Et pas seulement le
| disque : Telescope journalise les REQUETES, les requetes SQL avec leurs
| parametres, les jobs et les courriels — donc des donnees personnelles et des
| secrets, dans une table que rien ici ne purge et qu'aucune migration ne cree.
|
| Le patron du depot est deja ecrit deux fois — `MockServicesProvider::drapeau()`
| et `config/app.php` — et il tient en une phrase : **le defaut suit
| l'ENVIRONNEMENT, et le refus n'est pas contournable par une variable.** On
| l'etend ici, on ne le reinvente pas. La liste des environnements permis est
| celle de `config/app.php`, mot pour mot — `local` et `testing` — et pour la
| meme raison : ce sont les seuls ou ce qui est enregistre ne peut atteindre
| personne d'autre que celui qui l'a provoque.
|
| La preproduction est dans le refus, et ce n'est pas un exces : elle porte les
| memes tables que la production — c'est-a-dire aucune table Telescope — sur un
| disque qu'on surveille encore moins, et elle est servie sur des noms publics
| par le Caddy de production (cf. `config/app.php`, constat F37-003).
|
| ⚠️ LE REFUS NE DOIT PAS ETRE MUET. `enabled_refuse` ci-dessous existe pour
| que `App\Providers\TelescopeServiceProvider::boot()` puisse le journaliser au
| niveau critique. Sans ce signal, l'operateur qui pose `TELESCOPE_ENABLED=true`
| sur la preproduction ne verrait RIEN, chercherait la panne ailleurs, ou
| « reparerait » la garde. Un refus muet est la moitie d'un defaut.
| Il est calcule ICI, dans la configuration, et non dans le fournisseur : en
| production `config:cache` est actif et `env()` hors configuration rend `null`
| — un signal bati sur `env('TELESCOPE_ENABLED')` dans un fournisseur ne se
| declencherait donc JAMAIS la ou il sert. Meme lecon que `app.debug_refuse`.
|
| ── CE QUI N'EST PAS FERME PAR CE FICHIER ─────────────────────────────────────
|
| Le drapeau ne cree pas les tables. Poser `TELESCOPE_ENABLED=true` en `local`
| sans jouer `php artisan telescope:install` puis `migrate` reproduit EXACTEMENT
| le defaut de production, en petit. Telescope reste activable deliberement la ou
| il sert — c'est voulu, une garde qui vole son outil a celui qui s'en sert finit
| par etre retiree en entier — mais l'activer sans migrer reste une faute.
*/

$configurationDuPaquet = base_path('vendor/laravel/telescope/config/telescope.php');

/*
 * Repli si le paquet disparait de `composer.json` un jour : sans ce garde-fou,
 * ce fichier serait une erreur fatale au demarrage, y compris en production.
 * Le repli conserve la seule chose qui compte ici — l'extinction.
 */
$defauts = is_file($configurationDuPaquet) ? require $configurationDuPaquet : [];

/*
 * ⚠️ `filter_var(..., FILTER_VALIDATE_BOOLEAN)` et NON `(bool)`, meme lecon que
 * `MockServicesProvider::drapeau()` et que `config/app.php` : `(bool) "off"`
 * vaut **true** en PHP. `env()` normalise `"false"`, mais pas `"off"`, `"no"`
 * ni `"0.0"` — un operateur qui ecrit `TELESCOPE_ENABLED=off` en croyant
 * eteindre ALLUMERAIT.
 *
 * Le defaut est FALSE la ou le paquet met TRUE : c'est la premiere moitie du
 * correctif, celle qui ferme le cas « la variable est absente ».
 */
$telescopeDemande = filter_var(env('TELESCOPE_ENABLED', false), FILTER_VALIDATE_BOOLEAN);

/*
 * Meme lecture d'environnement que `config/app.php` : `env('APP_ENV')` et non
 * `$app->environment()`, qui n'existe pas encore quand la configuration est
 * chargee — et qui, sous `config:cache`, serait de toute facon fige avec elle.
 *
 * Les seuls environnements ou ce que Telescope enregistre ne peut atteindre
 * personne d'autre que celui qui l'a provoque. Liste identique a celle de
 * `app.debug` : deux garde-fous qui divergeraient finiraient par se contredire.
 */
$telescopeAutorise = in_array(env('APP_ENV', 'production'), ['local', 'testing'], true);

return array_merge($defauts, [

    /*
     * 🔴 LA SEULE LIGNE QUI CHANGE PAR RAPPORT AU PAQUET.
     *
     * Defaut FALSE au lieu de TRUE, ET refus non contournable hors `local` /
     * `testing`. Les deux lectures — celle du fournisseur du paquet
     * (`config('telescope.enabled')`) et celle du fournisseur applicatif
     * (`config('telescope.enabled')` depuis le correctif) — s'accordent sur la
     * meme valeur, quelle que soit la variable posee.
     */
    'enabled' => $telescopeDemande && $telescopeAutorise,

    /*
     * Vrai quand Telescope a ete DEMANDE et REFUSE : le signe d'une
     * configuration de deploiement fautive, que
     * `App\Providers\TelescopeServiceProvider::boot()` journalise au niveau
     * critique. Le silence ici redonnerait le defaut d'origine, qui etait
     * precisement que personne ne disait rien.
     */
    'enabled_refuse' => $telescopeDemande && ! $telescopeAutorise,
]);
