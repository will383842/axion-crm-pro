<?php

/*
|--------------------------------------------------------------------------
| Push automatique agrégé CRM → Usine (L7)
|--------------------------------------------------------------------------
|
| Fichier à part (et non `config/crm.php`) pour éviter les conflits.
|
| Le CRM envoie chaque jour à l'Usine des COMPTAGES seulement (mot × réseau ×
| compte × jour) : aucune donnée de personne, cellule < `seuil` masquée "<3".
|
| `enabled` : interrupteur ÉTEINT par défaut. Retour arrière = l'éteindre, par
|    un DÉPLOIEMENT (un `restart` ne relit pas l'environnement).
| `url`     : adresse de la route `/api/agregats-crm` de l'Usine (https obligatoire).
| `secret`  : USINE_AGREGATS_CRM_SECRET, lu SEULEMENT dans l'environnement, jamais
|    écrit dans le dépôt ni dans un journal. Moins de 32 caractères = absent :
|    la commande ne pousse rien et alerte. (La variable USINE_AGREGATS_CRM_IPS
|    vit du côté Usine ; le CRM ne la lit pas.)
*/
return [
    'agregats' => [
        'enabled' => (bool) env('USINE_AGREGATS_CRM_ENABLED', false),
        'url' => (string) env('USINE_AGREGATS_CRM_URL', ''),
        'secret' => env('USINE_AGREGATS_CRM_SECRET'),
        'seuil' => 3,
        'timeout' => 10,
    ],
];
