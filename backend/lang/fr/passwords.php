<?php

/*
 * Messages de réinitialisation de mot de passe en français (broker Laravel).
 */

return [
    'reset' => 'Votre mot de passe a été réinitialisé.',
    'sent' => 'Si un compte correspond à cette adresse, un lien de réinitialisation vous a été envoyé.',
    'throttled' => 'Veuillez patienter avant de réessayer.',
    'token' => 'Ce lien de réinitialisation n’est plus valide.',
    // Neutre à dessein : dire « aucun compte » révélerait quelles adresses ont
    // un compte (énumération). Même phrase que `sent`, à la lettre près.
    'user' => 'Si un compte correspond à cette adresse, un lien de réinitialisation vous a été envoyé.',
];
