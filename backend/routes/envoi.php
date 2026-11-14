<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes du produit Envoi (lot R00)
|--------------------------------------------------------------------------
| Requis depuis routes/api.php DANS le groupe `auth:sanctum` + `workspace` + `first-login` : ces middlewares
| s'appliquent donc ici sans être répétés.
|
| `produit-envoi` couvre TOUT /envoi/*, la santé comprise : un espace sans le produit reçoit 404 partout,
| avant même le contrôle de permission (aucun 403 ne trahit l'existence de la surface).
|
| Un fichier par domaine dans routes/envoi/. Ils sont créés d'avance, vides tant qu'un lot n'y ajoute pas ses routes.
| Règle : aucune route d'envoi ne résout `ServeurEnvoi` pour une lecture, et aucune ne porte « temoin », « armer »
| ni « reprendre » (tests/Feature/Envoi/Routes).
*/
Route::middleware('produit-envoi')->group(function () {
    foreach ([
        'sante', 'feu-vert', 'chauffe', 'boites', 'sequences', 'infrastructure', 'resultats',
        'conformite', 'campagnes', 'creation', 'reponses', 'reglages', 'reputation',
    ] as $domaine) {
        require __DIR__ . "/envoi/{$domaine}.php";
    }
});
