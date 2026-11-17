# B8-C — API : « joignables » vrais

Cahier : `docs/envoi/lots/B6-B8-cahier.md` §9 (branche `conception/b6-b8`, PR brouillon #671).

## Ce qui change
- `POST /envoi/brouillons/{id}/cible/estimation` : quand la cible demandée est celle enregistrée dans le brouillon (liste, audience, étiquette, sans `contact_ids`),
  l'estimation lit la sélection réelle (`SelectionDestinataires::lot`, lots de 500, 10 lots au plus, échéance globale 3 s). Réponse : `total` = membres examinés,
  `restant` = retenus moins adresses au dernier verdict `invalide`/`risque`, `exclusions` = tous les motifs + `adresse_invalide` + `adresse_risquee`,
  `exclusions_evaluees: true`, `complet` (faux = « au moins N sur M examinés », jamais extrapolé).
- Sinon (autre cible, carnet, `contact_ids`, cible introuvable) : ancienne estimation inchangée (`exclusions_evaluees: false`).
- `GET /envoi/creation/cibles` : `joignables` est `null` (« non estimé ») pour toutes les cibles ; `total` inchangé.
- Erreurs : lecture trop longue = 503 `estimation_trop_longue` ; filtre d'origine inactif = 409 `filtre_origine_inactif`.
- Lecture seule : aucun DNS, aucun verdict écrit, aucune écriture.

## Fichiers
`AssistantCreation.php`, `creation.yaml`, `creation.d.ts` (régénéré), `CreationJoignablesTest.php`, ce document.
Écarts au cahier (nécessaires, aucune PR ouverte ne touche ces fichiers) : `CreationController.php` (1 ligne : passer l'id du brouillon à `estimer`, sans quoi la cible enregistrée est inconnue) ; `EtapeCible.tsx` (affiche « Non estimé » quand `joignables` est nul : sans cela le typage du frontend casse ; B8-E le refera).

## Tests
`CreationJoignablesTest` (12 cas = 6 × `app`/`proprio`). Mutations vérifiées : `joignables = total`, ancienne voie d'estimation, extrapolation de `restant`.
Décision ouverte : ESCALADE 6 du cahier (au-delà de 5 000 membres : pas d'extrapolation, défaut appliqué).
