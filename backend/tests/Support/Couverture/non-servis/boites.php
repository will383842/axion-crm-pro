<?php

/** Entrées NonServiAuPalier0 du domaine « boites » : 501 inconditionnels (D3 bis), vérifiés par appel dans NonServiAuPalier0Test. */
$r = 'Une boîte n\'a de sens qu\'avec un domaine d\'envoi et un serveur réels ; ses identifiants s\'entrent par commande locale';

return [
    'POST /envoi/boites' => ['code' => 501, 'raison' => "$r.", 'exemple' => '/envoi/boites'],
    'POST /envoi/boites/test-connexion' => ['code' => 501, 'raison' => "$r : aucun test de connexion.", 'exemple' => '/envoi/boites/test-connexion'],
    'PUT /envoi/boites/{id}/reglages' => ['code' => 501, 'raison' => 'Aucune colonne ne porte la signature ni l\'absence d\'une boîte au palier 0.', 'exemple' => '/envoi/boites/00000000-0000-0000-0000-000000000000/reglages'],
];
