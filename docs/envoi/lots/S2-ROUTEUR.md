# S2-ROUTEUR — Routes d'écran et pages d'attente

## Fait
- Routes `/envoi/sequences`, `/envoi/sequences/$sequenceId`, `/envoi/sante/inscriptions`, toutes gardées par `exigerProduit('envoi')` (sans produit Envoi : « Page introuvable »).
- Pages d'attente (`PageEnAttente`) : `SequencesPage` et `SequencePage` (lot F-SEQ), `InscriptionsPage` (lot K14-ECRAN).
- Onglets « Séquences » (campagnes) et « Inscriptions » (santé).
- Libellés du fil d'Ariane : `/envoi/sequences` et `/envoi/sante/inscriptions` ; `fil-d-ariane.test.tsx` reste vert sans modification.

## Reste
- Remplir les trois pages : F-SEQ (séquences) et K14-ECRAN (inscriptions). Ils ne touchent ni `routeTree.tsx` ni `AutoBreadcrumbs.tsx`.

## Risques
- Aucun côté serveur : frontend seul, aucun appel réseau nouveau. `ENVOI_REEL_ACTIF` reste `false`.
- Filet modifié : pourquoi — `routes-envoi-gardees.test.tsx` (fichier de la liste) gagne les trois routes en attente et un test d'onglets ; aucune assertion existante n'est touchée.

## Tests lancés
- Rouges d'abord (4 échecs) puis verts : `routes-envoi-gardees.test.tsx`, `fil-d-ariane.test.tsx`.
- `vitest run tests/components` (381 verts), `tests/non-regression` + `tests/screens/envoi` (174 verts), `tsc --noEmit`, `pnpm lint`.
