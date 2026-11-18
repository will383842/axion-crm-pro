# LI-E — alertes, rappels de relance et relevé hebdomadaire (voie S, pont LinkedIn)

Cahier : `docs/envoi/lots/PONT-LINKEDIN-cahier.md` (branche `conception/pont-linkedin`, PR #691), lot LI-E. Dépend de LI-C (sur `main`).

## Ce que fait le lot
Commande `prospection-linkedin:releve`, planifiée chaque jour à 7 h (`routes/console.php`, `skip` tant que
`PROSPECTION_LINKEDIN_ACTIVE` est éteint ; la commande se garde aussi elle-même et sort sans lire aucune table) :

1. **Rappels de relance** : prospects `plus_tard` dont `relance_le` est échu ⇒ une alerte par expéditeur et par jour (comptes seulement).
2. **Arrêt automatique** : moins de 20 % d'invitations acceptées sur les 50 dernières ⇒ ligne `pl_arret` (déclencheur
   `acceptation_basse`, ajout seul) et alerte. Moins de 50 invitations ⇒ aucun jugement. Arrêt déjà actif ⇒ rien de plus.
3. **Relevé hebdomadaire** (le lundi, une fois par semaine et par expéditeur) : comptes par état et par variante. Aucun profil, nom ni adresse.

Les alertes passent par `App\Socle\Alertes\Alerte` (cloche et courriel, journal toujours, inertes tant que `alertes.actif` est éteint).
Elles vont au seul fondateur ; rien ne part vers une personne. Écritures : ajout seul (`pl_arret`, `pl_journal`), cloisonnées par espace.

## Décisions par défaut (sans risque)
- Le cahier dit « `AlerteTelegram` existante » : cette classe est devenue un simple relais déprécié vers `Alerte` (cloche + courriel, plus de
  Telegram). Le lot appelle donc `Alerte` directement : même comportement, sans passer par un nom déprécié.
- `.env.example` **non modifié** : le cahier demande d'attendre la fusion de X9-B (pas encore fusionnée). La variable reste documentée dans
  `config/prospection_linkedin.php`. À ajouter une fois X9-B fusionnée.
- « Avertissement de LinkedIn saisi par le fondateur » : `POST /arret` pose déjà un arrêt (déclencheur `humain`). Faire porter le déclencheur
  `avertissement` demande une modification du contrôleur, hors fichiers autorisés de ce lot : à traiter dans un lot à part.
