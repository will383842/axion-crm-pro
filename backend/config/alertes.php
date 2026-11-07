<?php

/*
|--------------------------------------------------------------------------
| ALERTES — la cloche de la console et l'e-mail, rien d'autre
|--------------------------------------------------------------------------
|
| Une alerte part toujours dans le journal (canal fiable et collecté), puis,
| drapeau ouvert, dans la cloche de la console ET par e-mail. Pas de téléphone,
| pas de SMS, pas de messagerie tierce.
|
| ── Le drapeau ───────────────────────────────────────────────────────────
| `actif` est ÉTEINT par défaut : une fusion sur `main` déploie la production,
| et ouvrir les alertes est une décision d'exploitation. Retour arrière :
| remettre ALERTES_CONSOLE_EMAIL_ACTIVES à false, par un déploiement.
|
| ── Le battement externe (la sonde « si le CRM se tait, un e-mail part ») ──
| Principe, sans compte payant : un service de supervision GRATUIT, extérieur
| au serveur, appelle l'adresse publique `/up` du CRM toutes les 5 minutes et
| envoie lui-même un e-mail à la SECONDE adresse (hors domaine Axion) dès que
| la réponse n'est plus 200 pendant 2 relevés de suite. Comme la surveillance
| vit hors du serveur, elle prévient même quand le CRM, son planificateur et
| son courrier sont tous à l'arrêt. À poser par Will dans l'outil choisi ;
| rien à stocker dans le dépôt.
|
*/

return [

    'actif' => (bool) env('ALERTES_CONSOLE_EMAIL_ACTIVES', false),

    'email' => [
        /* Adresse d'alerte principale : reçoit l'e-mail ET la ligne de cloche (compte du même nom). */
        'destinataire' => env('ALERTES_EMAIL_DESTINATAIRE', ''),
        /* Seconde adresse, de préférence HORS domaine Axion : l'alerte reste lisible si le courrier Axion tombe. */
        'seconde_adresse' => env('ALERTES_EMAIL_SECONDE_ADRESSE', ''),
    ],

    /* Anti-bruit : la même alerte ne repart qu'une fois par fenêtre. */
    'regroupement_minutes' => (int) env('ALERTES_REGROUPEMENT_MINUTES', 15),

];
