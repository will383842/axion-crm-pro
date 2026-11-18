<?php

/*
|--------------------------------------------------------------------------
| Prospection LinkedIn « prêt à cliquer » (voie S) — socle LI-A
|--------------------------------------------------------------------------
| Le fondateur envoie lui-même depuis son navigateur ; le CRM prépare,
| contrôle et journalise. Aucun appel vers LinkedIn, aucun outil tiers.
| `active` éteint (défaut) ⇒ toutes les routes /prospection-linkedin/* en 404.
| Seul ce fichier lit PROSPECTION_LINKEDIN_ACTIVE.
*/

return [
    'active' => filter_var(env('PROSPECTION_LINKEDIN_ACTIVE', false), FILTER_VALIDATE_BOOLEAN),

    // Plafonds par défaut prudents (ESCALADE E5), modifiables par le propriétaire.
    'plafonds' => [
        'invitations_jour' => 10,
        'invitations_semaine' => 50,
        'messages_jour' => 20,
        'notes_mois' => 5,
        'jours_ouvres_seulement' => true,
    ],

    'seuil_score' => 60,
    'delai_reinvitation_jours' => 21,
    'jours_contact_courriel' => 90,

    // Site public (échec fermé : vide ⇒ aucun lien suivi, aucun texte prêt).
    'site_url' => (string) env('PROSPECTION_LINKEDIN_SITE_URL', ''),
    'politique_confidentialite_url' => (string) env('PROSPECTION_LINKEDIN_POLITIQUE_URL', ''),
];
