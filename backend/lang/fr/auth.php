<?php

/*
 * Messages d'authentification en français.
 *
 * Audit UX du 02/10/2026 (P0-6) : sans ce fichier, `__('auth.failed')`
 * renvoyait la CLÉ brute (`auth.failed`), affichée telle quelle à l'écran de
 * connexion. Le dépôt n'avait aucun dossier `lang/` et `app.locale` vaut `fr`.
 *
 * Garde : `tests/Feature/Auth/LoginTest.php` (« message FRANÇAIS »).
 */

return [
    'failed' => 'Adresse e-mail ou mot de passe incorrect.',
    'password' => 'Le mot de passe est incorrect.',
    'throttle' => 'Trop d’essais. Réessayez dans :seconds secondes.',
    // `:until` est fourni par AuthService mais volontairement NON affiché :
    // c'est une date ISO 8601 brute, illisible pour une personne.
    'locked' => 'Ce compte est temporairement verrouillé après trop d’essais. Réessayez plus tard ou réinitialisez votre mot de passe.',
];
