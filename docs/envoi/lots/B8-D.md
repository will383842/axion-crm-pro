# B8-D — API : audience demandée, capacités de l'assistant

Cahier : `docs/envoi/lots/B6-B8-cahier.md` §10 (branche `conception/b6-b8`, PR brouillon #671).

## Ce qui change
- `GET /envoi/creation/cibles?audience=ID` : l'audience demandée (chiffres seulement, moins de 18 caractères) est toujours proposée,
  en tête de la réponse, même hors des 50 premières par nom, une seule fois. Hors espace, supprimée, inactive ou valeur invalide : ignorée, 200, sans oracle d'existence.
- `GET /envoi/creation/contexte` ajoute `capacites: {ab, boites, plafond}`, lues des refus du serveur : `ab` = faux tant que `PlanSequence`
  refuse un `objet_b` (sonde, aucune écriture) ; `boites` et `plafond` = `RaisonBlocage::REGLAGES_APPLIQUES` (faux).
- `RaisonBlocage::REGLAGES_APPLIQUES = false` (ajout, autorisé par le pilotage) ; `reglageNonApplique` la lit, donc la matérialisation aussi
  (comportement inchangé tant qu'elle est fausse). Aucune route nouvelle.

## Fichiers
`AssistantCreation.php`, `CreationController.php`, `EnvoiMessagesController.php`, `RaisonBlocage.php` (constante seule + une garde),
`creation.yaml`, `creation.d.ts` (régénéré), `CreationCiblesAudienceTest.php`, ce document.

## Tests
`CreationCiblesAudienceTest` : 5 cas (4 × `app`/`proprio` + droits). Rouges vus avant le code (cau 1 et cau 4 échouaient).
