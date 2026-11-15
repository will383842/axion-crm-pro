# G4-UI — Badge « opposée » dans les écrans du CRM

## Fait
- `BadgeOpposee` (nouveau) : le mot « opposée » écrit (pas une couleur seule), infobulle qui regroupe les motifs
  (`stop`, `art21`, `liste_refus`) en clair ; un motif inconnu n'est jamais affiché tel quel ; rien si l'adresse n'est pas
  opposée ou si l'ancienne API n'envoie pas `opposition`.
- Badge dans la liste des contacts (`ContactsListPage`), la liste des personnes (`PersonnesPage`) et la fiche d'une
  personne (`PersonneDetailPage`). Type `OppositionStatut` (facultatif) dans `types.ts`.
- Démo : une personne sur cinq et un contact sur sept sont « opposés » (faux serveur, lecture seule).
- Lecture seule : aucune écriture, aucun envoi, aucune suppression. `commentaire_ref` n'est jamais lu ni affiché.

## Reste
- Exclusion d'envoi : G4-ENVOI. Section « Accord donné » (D15) : G4-ACCORD (vague 3), hors de cette fiche.
- `/contacts` redirige vers le hub en démo (console v2 ouverte) : l'ancienne liste n'est donc couverte que par le test
  d'écran, pas par le e2e de démo.

## Risques
- Le serveur reste la source : le badge n'ajoute aucune règle. Le stop gagne toujours côté serveur.

## Tests lancés
- Rouge d'abord : `tests/screens/BadgeOpposee.test.tsx` échouait (`Failed to resolve import "@/features/crm-console/BadgeOpposee"`).
- Vert : `BadgeOpposee.test.tsx` 8/8 ; `tests/screens`, `tests/components`, `tests/non-regression`, couverture e2e : 124 fichiers, 1033 tests verts.
- `dm3-crm-opposee.spec.ts` : 3/3 (Playwright démo). `tsc --noEmit` et `pnpm lint` propres.
- `ENVOI_REEL_ACTIF=false` inchangé ; aucun fichier du backend touché.
