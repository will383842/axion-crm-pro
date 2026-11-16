# VERDICTS-CODE-2 — rafraîchissement planifié des verdicts d'adresse

Cahier : `VERDICTS-cahier.md` §1.3, §1.4, §3.4, §3.5, §5. Suite de CODE-1 (#614).

## Ce que fait le lot
- Commande `envoi:verdicts:rafraichir` (`--espace=`, `--essai`), planifiée **toutes les heures à la minute 17**
  dans `backend/routes/console/envoi-verdicts.php` (`withoutOverlapping(30)`, `onOneServer()`).
- Pour chaque espace actif (hors corbeille) ayant le produit Envoi : adresses des inscriptions `active` échues dans
  les 3 jours dont le dernier verdict est absent, ou `valide` depuis 20 jours ou plus (500 adresses au plus par espace).
- Bornes : 120 s (aucun nouvel espace au-delà, compté `hors_borne`) ; plafonds DNS de `QuotaDns` (200 par passage,
  2 000 par jour, fermé si le cache est indisponible) ; le quota est partagé par tous les espaces du passage.
- Garde de schéma : tâche non due, et commande sans effet (message, code 0), si la colonne `motif` ou la table
  `email_domaines` manque (le déploiement remplace le code avant `migrate`).
- `--essai` : tout est calculé puis annulé (verdicts ET cache des domaines), compteur `ecrits` = ce qui serait écrit.
- Code de sortie 1 si au moins un espace est en erreur ; journal = compteurs et classe d'exception, jamais d'adresse.

## Ce que le lot ne fait pas
Aucun envoi, aucune connexion SMTP, aucune route, aucune config, aucun drapeau (`ENVOI_REEL_ACTIF` intact), aucune
suppression de verdict ni de donnée de personne (INSERT seulement). Fichiers chauds du cahier §3.5 non touchés.

## Filet modifié : pourquoi
`nr07-taches-planifiees.json` et `nr08-taches-sous-rls.json` : UNE entrée chacun (`envoi:verdicts:rafraichir`),
la nouvelle tâche planifiée. Rien d'autre régénéré (`nr06`, `nr20` laissés tels quels).

## Tests
`PointsEntreeV0Test` : le test V0 « fichiers vides » accepte UNE tâche, la nôtre (renforcé : exactement une).
`RafraichissementVerdictsTest` (T2.1 à T2.8, T2.10), `VerdictsRoleApplicatifTest` (T2.9, sous `pgsql_app`).
