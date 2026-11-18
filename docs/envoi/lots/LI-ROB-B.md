# LI-ROB-B — réglage et texte LinkedIn (relecture de #693)

Plan : commentaire « PLAN DU RESTE À FAIRE (18/11) » de #680.

## Ce qui change
- `config/prospection_linkedin.php` : le drapeau est lu par `filter_var(..., FILTER_VALIDATE_BOOLEAN)`. « off », « no », « non » ⇒ faux. Le défaut reste faux.
- `TextePret::composer` : la longueur (300 pour l'invitation, 1900 pour le message) est contrôlée APRÈS l'ajout du préfixe « [ESSAI] ». Choix : refus (exception), jamais de coupe. Le texte rendu ne dépasse donc jamais la limite.

## Tests
`backend/tests/Unit/ProspectionLinkedIn/TextePretRelectureTest.php` (rouges d'abord, 7 rouges). L'instantané `nr16` est inchangé (Nr16DrapeauxTest vert). Aucune donnée d'espace : propriétaire et `pgsql_app` identiques.
Mutations rouges : retour à `(bool) env(...)` ⇒ 5 rouges ; contrôle de l'invitation sans préfixe ⇒ 1 rouge ; contrôle du message sans préfixe ⇒ 1 rouge.
