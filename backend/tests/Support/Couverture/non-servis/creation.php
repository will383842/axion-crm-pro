<?php

/** Entrées NonServiAuPalier0 du domaine « creation » (409/501 vérifiés par appel dans NonServiAuPalier0Test). */
$f = 'Envois fermés au palier 0';

return [
    'POST /envoi/messages/test' => ['code' => 409, 'raison' => "$f : aucun message de test ne part.", 'exemple' => '/envoi/messages/test'],
    'POST /envoi/messages/suggestion' => ['code' => 501, 'raison' => 'Suggestion par IA non ouverte (coût et données confiées à un tiers).', 'exemple' => '/envoi/messages/suggestion'],
    'POST /envoi/brouillons/{id}/test' => ['code' => 409, 'raison' => "$f : aucun envoi de test.", 'exemple' => '/envoi/brouillons/@brouillon@/test'],
];
