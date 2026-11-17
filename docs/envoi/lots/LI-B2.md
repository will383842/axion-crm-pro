# LI-B2 — contrôles, plafonds, arrêt et file du jour

Cahier : `docs/envoi/lots/PONT-LINKEDIN-cahier.md` (branche `conception/pont-linkedin`, PR brouillon #691), section LI-B2.
Dépend de PL-M0B (tables `pl_prospects`, `pl_journal`, `pl_plafonds`, `pl_arret`, en ligne), de LI-A, de LI-L3 et de LI-B1 (#725), tous fusionnés.
Aucune migration, aucun appel vers LinkedIn, aucun outil tiers, aucun envoi, aucun drapeau touché (`ENVOI_REEL_ACTIF` reste `false`).

## Fichiers (seuls ceux-là sont touchés)
- C `backend/app/ProspectionLinkedIn/ControlesProspect.php`
- C `backend/app/ProspectionLinkedIn/FileDuJour.php`
- C `backend/app/ProspectionLinkedIn/Plafonds.php`
- C `backend/app/ProspectionLinkedIn/Arret.php`
- C `backend/tests/Feature/ProspectionLinkedIn/FileDuJourTest.php`
- C `docs/envoi/lots/LI-B2.md`

Vérifié avant de coder : aucune PR ouverte (#735 à #240 listées) ne touche un de ces fichiers ni `StatutOpposition` / `RegistreOppositionLinkedin`.

## Comportement
- `Arret::actif(ws, expediteur)` : dernière ligne de `pl_arret` de l'expéditeur ou « tous » ; erreur de lecture ⇒ **actif** (échec fermé).
- `Plafonds::restants(ws, expediteur, maintenant)` ⇒ `{jour, semaine}` : dernière ligne `pl_plafonds` ou défauts de la configuration ; compte les
  `invitation_faite` du jour et de la semaine ISO (lundi à dimanche) dans `pl_journal` ; samedi et dimanche ⇒ 0 si `jours_ouvres_seulement`.
- `ControlesProspect::verifier(ws, prospects, maintenant)` en un passage, échec fermé (toute exception ⇒ `erreur = true`, rien de proposé, aucun
  état changé, une ligne `alerte` au journal). Opposé par profil (`profilsOpposes`) ou, si fiche rattachée, par adresse et SIREN (`pourLignes`)
  ⇒ `ecarte` + journal `opposition`. Autres refus (journal système `controle_refuse`, motif dans `details`, une ligne par jour et par motif) :
  `arret`, `rgpd_en_cours`, `presse`, `client`, `courriel_recent` (inscriptions de séquence et messages envoyés des 90 derniers jours, lecture seule).
  Le SIREN et les signaux de fiche (presse, client de la console) viennent de la fiche rattachée ou du SIREN du prospect.
- `FileDuJour::construire(ws, expediteur, maintenant)` ⇒ `{prospects, raison, alerte}` : arrêt ⇒ vide (`arret`) ; plafonds ⇒ `min(jour, semaine)` ;
  candidats `qualifie`/`controle`, `plus_tard` échus, `invitation_retiree` depuis au moins 21 jours ; score ≥ seuil ; tri score, ancienneté.
  L'**arrêt et l'opposition sont relus dans la transaction d'écriture**, juste avant le passage à `en_file` (verrou de ligne, état revérifié).
- Nuance du cahier : l'énumération `evenement` de `pl_journal` n'a pas de valeur « mise en file » ; le passage à `en_file` se lit dans
  `pl_prospects.etat` et n'est pas journalisé (aucune migration autorisée dans ce lot).

## Tests rouges d'abord, puis verts
Premier commit = test seul (rouge : classes absentes, 15 échecs `Class "App\ProspectionLinkedIn\…" not found`). Second commit = code (vert : 15 tests,
chacun joué sous `pgsql_app` ET propriétaire). Couverts : registre illisible ⇒ vide + alerte ; opposition par adresse et par SIREN ; RGPD / presse /
client ; courriel il y a 30 jours exclu, 120 jours inclus ; 40 demandés, 12 restants ⇒ 12 ; défauts ; samedi et dimanche ⇒ 0 ; arrêt (A sans effet sur B,
« tous », erreur ⇒ actif) ; arrêt et opposition posés pendant la construction ; retrait de moins de 21 jours exclu ; espace B ; aucune suppression.
(Les « warnings » Pest sont le `.env` absent du poste de développement, comme pour LI-B1.)

## Mutations jouées (chacune fait rougir le test visé, puis annulée)
- relecture finale de l'arrêt retirée ⇒ rouge « arrêt posé PENDANT la construction » ;
- exception du registre avalée ⇒ rouge « registre d'opposition illisible » ;
- filtre samedi retiré ⇒ rouge « samedi et dimanche ⇒ 0 » ;
- plafond de la semaine ignoré ⇒ rouge « 40 prospects, 12 restantes ».

## Hors lot
La relecture au geste « fait » est celle de LI-C (cahier). Pint passé ; PHPStan niveau 8 sans erreur nouvelle sur `app/ProspectionLinkedIn`.
