# LI-D1 — écran de la file « prêt à cliquer »

Cahier : `docs/envoi/lots/PONT-LINKEDIN-cahier.md` (branche `conception/pont-linkedin`, #691). LI-C fusionnée par #741.

## Livré
Écran `FileLinkedinPage` (front seul, aucune route d'entrée de menu : c'est LI-D2) : une carte par prospect avec
« Ouvrir le profil » (`target="_blank"`, `rel="noopener noreferrer"`, aucune ouverture automatique), « Copier le texte »,
gestes à clé d'idempotence (une clé par carte et par geste, rejouée à l'identique au nouvel essai), « Opposition » avec
confirmation, bouton d'arrêt toujours visible (rouge quand actif), restants du jour et de la semaine, mention « ESSAI »
portée par le texte, 404 ⇒ page « module éteint », statut annoncé (`aria-live`).

## Échec fermé
409 `oppose` ou `arret` ⇒ carte retirée, bandeau d'alerte, file relue. Le serveur (LI-C) reste seul juge : l'écran ne contacte
personne et ne supprime rien.

## Écart au périmètre (à valider par le relecteur)
Le filet NR-20 du frontend exige que chaque appel d'API vise une route de la photo
`backend/tests/Feature/NonRegression/instantanes/nr20-routes.json`, qui ne listait pas les six routes de LI-C. Ajout de six lignes
(photo « au moins » : ajouter est permis, rien n'est retiré ni affaibli). Sans cela le filet rougit dès LI-D1.
