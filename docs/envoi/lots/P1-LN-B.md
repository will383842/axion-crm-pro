# P1-LN-B — Listes de refus : import (D8, première moitié)

Plan : `docs/envoi/PLAN-FIN-ENVOI.md`, fiche P1-LN-B. `ENVOI_REEL_ACTIF=false` : aucun envoi réel. Aucune migration (la table vient de S1-C).

## Fait
- `POST /envoi/opposition/import` (propriétaire seul), corps `application/json` `{etape, contenu, jeton?}`, jamais multipart. Le texte reste en mémoire.
- `ImportOppositions` : simulation (aucune écriture, aucune adresse renvoyée, jeton de 15 min en cache, clé = le jeton, valeur = utilisateur, espace, SHA-256) puis confirmation (SHA-256 recalculé ; ligne `envoi_oppositions_imports` insérée en premier, violation d'unicité = 409 ; tout ou rien dans une transaction).
- Lignes refusées avec numéro et motif : valeur commençant par `=`, `+`, `-`, `@`, tabulation ou retour chariot (« caractère de tête refusé » : une adresse valide commençant par `+` ou `-` s'inscrit par le formulaire R13) ; domaine de messagerie grand public. 422 seulement pour le corps entier (plus de 1 048 576 octets, plus de 5 000 lignes).
- `RegistreOppositionManuel::inscrireLot` : même effet que `inscrire` (registre, `email_suppressions`, `opt_out`) avec `import_id`, sans `signalerSansBloquer`. Après le COMMIT, `OppositionSortante::signalerSansBloquer` pour chaque adresse (anti-boucle et dédoublonnage décidés par `OppositionSortante`).
- Le contrôleur pose `ATTRIBUT_JOURNAL_PROPRE` et écrit sa ligne d'audit (étape, nombres, `import_id`, statut), sans le corps ni son empreinte.
- Aucune suppression : l'import ne fait qu'ajouter des refus.

## Reste
- Écran d'import : lot F-LN (vague 5).

## Risques
- Un arrêt du processus entre le COMMIT et l'émission des événements est rattrapé par `crm:outbound-rattraper` (source `registre_manuel`).
- La portée SIREN n'est pas importée (adresse ou domaine seulement).
- Le test des deux confirmations simultanées prouve l'unicité en base avec une seconde connexion (ligne d'import déjà validée par l'autre connexion), pas avec deux processus : pas d'ouvrier de test dans la liste fermée. Les lignes d'import étant en ajout seul, l'espace de ce test n'est pas nettoyé.
- Les tests base de données n'ont pas pu tourner dans l'environnement de rédaction (PostGIS, pgvector et pg_partman absents) : seuls PHPStan et Pint y ont tourné ; la CI fait foi.

## Tests lancés
`OppositionImportTest` (voir la CI), PHPStan niveau 8 (aucune erreur), Pint (réussi).
