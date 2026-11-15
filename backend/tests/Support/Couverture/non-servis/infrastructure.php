<?php

/** Entrées NonServiAuPalier0 du domaine « infrastructure » (409/501 vérifiés par appel dans NonServiAuPalier0Test). */
$f = 'Envois fermés au palier 0';

return [
    'POST /envoi/configuration/appliquer' => ['code' => 409, 'raison' => "$f : aucune application de configuration.", 'exemple' => '/envoi/configuration/appliquer'],
    'POST /envoi/configuration/versions/{numero}/revenir' => ['code' => 409, 'raison' => "$f : aucun retour de configuration.", 'exemple' => '/envoi/configuration/versions/1/revenir'],
    'POST /envoi/serveurs/{id}/test' => ['code' => 409, 'raison' => "$f : aucun test d'envoi du serveur.", 'exemple' => '/envoi/serveurs/@serveur@/test'],
];
