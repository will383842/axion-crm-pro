<?php

/*
|--------------------------------------------------------------------------
| Contrat v3 de l'origine des contacts (ADR-CONTRAT-V3-ORIGINE)
|--------------------------------------------------------------------------
|
| Fichier à part (et non `config/crm.php`) pour ne pas entrer en conflit avec
| les lots voisins.
|
| `v3_enabled` : tout le traitement v3 (version de charge 3, tableau `origines`,
| archive, filtre et champ de l'API des personnes) est DERRIÈRE cet
| interrupteur, ÉTEINT par défaut. Éteint : une charge en version 3 est
| refusée en 503 `origines_v3_disabled` (la ligne reste en attente côté site,
| rien n'est perdu), les versions 1 et 2 sont inchangées, l'API ne montre rien.
| Retour arrière = l'éteindre, par un DÉPLOIEMENT (un `restart` ne relit pas
| l'environnement). Aucune donnée n'est supprimée au retour arrière.
|
| `cle_hmac` : clé serveur pour l'empreinte de `commentaire_ref` (jamais stocké
| en clair). Vide : repli sur la clé de l'application.
*/
return [
    'v3_enabled' => (bool) env('ORIGINES_V3_ENABLED', false),
    'cle_hmac' => env('ORIGINES_HMAC_KEY', ''),
];
