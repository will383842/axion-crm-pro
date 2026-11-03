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
*
| `limite_par_post_canal` (ADR I3/I5) : au plus `max` charges portant un même
| (`post_ref`, `canal`) par fenêtre de `fenetre_secondes` ; au-delà, 429
| `origines_trop_de_charges` (la ligne reste en attente côté site).
|
| `archive_seuil_alerte` (ADR I3) : quand l'archive d'origines d'une personne
| dépasse ce nombre d'entrées, le propriétaire est alerté une fois (cloche).
|
| `masquage_commentaire_ref_enabled` (ADR § 7, I5) : interrupteur PROPRE, ÉTEINT
| par défaut, de la tâche qui passe `commentaire_ref` à null au bout de
| `commentaire_ref_mois` mois. Elle ne supprime rien (aucun DELETE) et ne
| tourne que si `v3_enabled` est aussi allumé. Jamais `CRM_PURGE_ENABLED`.
*/
return [
    'v3_enabled' => (bool) env('ORIGINES_V3_ENABLED', false),
    'cle_hmac' => env('ORIGINES_HMAC_KEY', ''),
    'limite_par_post_canal' => [
        'max' => (int) env('ORIGINES_LIMITE_MAX', 600),
        'fenetre_secondes' => (int) env('ORIGINES_LIMITE_FENETRE', 3600),
    ],
    'archive_seuil_alerte' => (int) env('ORIGINES_ARCHIVE_SEUIL_ALERTE', 200),
    'masquage_commentaire_ref_enabled' => (bool) env('ORIGINES_MASQUAGE_COMMENTAIRE_REF_ENABLED', false),
    'commentaire_ref_mois' => (int) env('ORIGINES_COMMENTAIRE_REF_MOIS', 12),
];
