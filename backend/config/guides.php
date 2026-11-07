<?php

/*
|--------------------------------------------------------------------------
| Lien du guide sur la fiche d'une personne (bloc « Contenus et accords »)
|--------------------------------------------------------------------------
|
| Fichier à part (comme `origines.php`) pour ne pas entrer en conflit avec les
| lots voisins.
|
| Le contrat site → Studio → CRM ne change pas : il porte déjà l'IDENTIFIANT
| public du guide (ex. `guide-ia`). Le CRM le traduit en lien grâce à la TABLE
| FERMÉE `publics` ci-dessous. Aucune adresse ne vient d'une donnée reçue ;
| un identifiant absent de la table n'a pas de lien. Le lien PERSONNEL de
| l'e-mail (`?t=…`) n'est jamais affiché, ni stocké, ni reconstitué.
|
| `lien_enabled` : interrupteur, ÉTEINT par défaut. Éteint : aucune adresse dans
| l'API, la fiche montre le nom du guide sans lien. Se pose par un DÉPLOIEMENT
| (un `restart` ne relit pas l'environnement). Rien n'est écrit en base : le
| lien est calculé à la lecture ; retour arrière = éteindre.
|
| `publics` : identifiant → `nom` (affiché), `libelle` (lecture d'écran) et
| `url` (https seulement, sans port, identifiant, paramètre ni fragment : la
| garde de `LienGuide` est réappliquée même sur cette table écrite à la main).
|
| `hote_autorise` : seul hôte accepté pour les liens (par défaut `axion-ia.com`,
| surchargeable par `CRM_GUIDE_HOTE`). L'hôte de test `exemple.invalid` n'est
| accepté QUE dans les environnements testing, local et demo, jamais en production.
|
| ⚠️ AVANT D'ALLUMER `CRM_GUIDE_LIEN_ENABLED` : poser la VRAIE adresse publique du
| guide (sur `hote_autorise`) dans `publics`. Tant que l'entrée de test
| `exemple.invalid` reste en place, la production n'affiche aucun lien.
|
| ⚠️ À REMPLACER PAR WILL : l'entrée `guide-ia` ci-dessous est un guide de TEST
| en `exemple.invalid` (domaine réservé, qui ne résout jamais). Ne jamais y
| inventer une vraie adresse : Will y met l'adresse publique réelle du guide
| validé, avant d'allumer l'interrupteur.
*/
return [
    'lien_enabled' => (bool) env('CRM_GUIDE_LIEN_ENABLED', false),
    'hote_autorise' => (string) env('CRM_GUIDE_HOTE', 'axion-ia.com'),
    'publics' => [
        // À REMPLACER PAR WILL : adresse de test, pas une vraie adresse.
        'guide-ia' => [
            'nom' => 'Guide exemple A',
            'libelle' => 'Guide exemple A (PDF)',
            'url' => 'https://exemple.invalid/guides/guide-ia.pdf',
        ],
    ],
];
