# Lot X7-LIB — libellés des motifs d'opposition X7

Plan : commentaire « PLAN DU RESTE À FAIRE (18/11) » de #680. Aucune migration, aucun drapeau.

## Ce que fait le lot
« Puis-je écrire à… ? » répond `opposition` pour `opposition_art21`, `opposition_stop` et `opposition_liste_refus` (avant : `conditions_envoi`). Un motif `opposition_*` inconnu reste `conditions_envoi`, sans erreur.

## Tests (rouges d'abord)
`backend/tests/Unit/MotifRegroupeTest.php` (ajouts seuls) : 3 motifs, motif inconnu, table complète inchangée.
Fonction pure sans base : le test ne lit ni n'écrit aucune donnée d'espace (PostgreSQL / `pgsql_app` sans objet).

## Mutation
Retirer `OPPOSITION_STOP` de la liste ⇒ 1 test rouge. Restauré.
