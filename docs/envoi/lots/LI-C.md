# LI-C — API de la file LinkedIn (voie S)

Cahier : `docs/envoi/lots/PONT-LINKEDIN-cahier.md` (section LI-C), consignes : cahier FINI #680.

## Fichiers
C `backend/routes/prospection-linkedin.php` ; M `backend/routes/api.php` (une ligne `require`, groupe `v1` authentifié) ;
C `backend/app/Http/Controllers/Api/ProspectionLinkedInController.php` ; C `backend/app/ProspectionLinkedIn/OpposerProspect.php` ;
C `backend/tests/Feature/ProspectionLinkedIn/ProspectionRoutesTest.php` ; C ce fichier.

## Routes (`/api/v1/prospection-linkedin/*`, aucune ne supprime)
`GET /file` · `POST /prospects` · `POST /prospects/{id}/gestes` (en-tête `Idempotency-Key` 16 à 128 caractères) ·
`POST /arret` · `POST /reprise` (motif obligatoire) · `POST /plafonds` (bornes du CHECK ; au-dessus des défauts seulement
avec `confirme: true`).

## Garanties
- Drapeau `PROSPECTION_LINKEDIN_ACTIVE` éteint ⇒ 404 avant tout accès à la base du module. Propriétaire seul
  (`permission:workspaces.manage`, posée sur les routes : la garde de recensement des permissions l'exige). Conséquence : un
  non-propriétaire reçoit 403 même drapeau éteint (aucune donnée du module n'est lue).
- Échec fermé : panne du registre d'opposition ⇒ file vide, rien n'est proposé ; un opposé n'est jamais proposé ; relecture de
  l'opposition ET de l'arrêt juste avant `invitation_faite` / `message_fait` (opposé ⇒ 409 `oppose`, `ecarte`, arrêt
  `oppose_dans_file` ; arrêté ⇒ 409 `arret`).
- `OpposerProspect` : une transaction (registre LinkedIn, registre d'adresse si fiche rattachée avec adresse, puis `ecarte`) ;
  une erreur annule tout et rend 503.
- Audit propre sans profil ni adresse ; aucune requête sortante ; aucune suppression.
- Texte prêt : `TextePret` (« [ESSAI] » tant que `envoi.reel_actif` est faux) ; texte non conforme ⇒ `null`, jamais dégradé.

## Tests (rouge d'abord, puis vert) et mutations
11 tests HTTP réels, écritures rejouées sous le propriétaire ET sous le rôle applicatif (`SET LOCAL ROLE`). Mutations vérifiées
rouges : relecture avant clic retirée ; transaction d'`OpposerProspect` retirée ; contrôle du propriétaire retiré.
Pas de contrat OpenAPI ajouté : aucune garde de couverture existante n'exige ce préfixe (gardes de routes lancées, vertes).
Aucun drapeau touché, aucune migration, aucun envoi réel.
