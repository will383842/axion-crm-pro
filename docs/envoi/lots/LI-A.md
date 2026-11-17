# Lot LI-A — socle pur de la prospection LinkedIn (voie S)

Cahier : `docs/envoi/lots/PONT-LINKEDIN-cahier.md` (branche `conception/pont-linkedin`, PR brouillon #691), section LI-A.
Aucune base, aucune route, aucun appel vers LinkedIn, aucun outil tiers. Aucun drapeau existant touché.

## Fichiers
`backend/config/prospection_linkedin.php`, `backend/app/ProspectionLinkedIn/{EtatsProspect,TextePret,LienSuivi}.php`,
`backend/tests/Unit/ProspectionLinkedIn/SocleProspectionTest.php`, ce document.

## Choix (à relire)
- `TextePret::composer` reçoit un 4ᵉ paramètre optionnel `$type` (`message` par défaut, ou `invitation`) : l'invitation
  est bornée à 300 caractères ; le premier message exige « répondez STOP » ET le lien de politique de confidentialité
  (réglage `politique_confidentialite_url`, échec fermé si vide) ; borne message 1900 caractères.
- Aucune configuration de domaine de site n'existe dans le dépôt : réglages `site_url` et `politique_confidentialite_url`
  ajoutés dans le fichier de config (variables lues ici seulement, vides par défaut ⇒ `LienSuivi` lève une exception).
  `.env.example` non touché (interdit ; la variable y entre en LI-E).
- Machine à états : branches `invitation_retiree` (depuis `invite`) et `plus_tard` (depuis message envoyé/répondu/humain) ;
  `en_pause` fige tout sauf l'écartement ; le délai de 21 jours avant réinvitation n'est PAS ici (lot ultérieur, base).

## Tests rouges d'abord
Classes retirées, tests lancés : 13 échecs (classes introuvables) — avant de les remettre. Ensuite 13 verts.

## Mutations (chacune rougit, puis annulée)
`ecarte` non absorbant ⇒ 1 échec ; contrôle STOP retiré ⇒ 1 échec ; liste de mots vidée ⇒ 1 échec.

## Limites
Tests unitaires sans base : pas de variante `pgsql_app`/propriétaire à exécuter. Pint passé. PHPStan (config du dépôt) : 0 erreur après correction du retour nul de `preg_replace_callback` (rouge en CI d'abord).
