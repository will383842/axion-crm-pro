# E2E-FIN — Parcours complet par l'interface (démo)

## Fait
- `frontend/tests/e2e/demo/dm3-parcours-envoi.spec.ts` (scénario P) : assistant de création (oppositions exclues visibles dans l'estimation), validation par code, la séquence de la campagne apparaît, pause avec confirmation, reprise refusée sous frein (raison en clair, statut inchangé), feu vert consulté (« Accorder » désactivé, mention « n'active pas l'envoi réel »), arrêt général en deux clics (bandeau sans « Reprendre »), puis vérification qu'aucun écran n'annonce un message parti en réel.
- `sequences.handlers.ts` : `ajouterSequenceDeCampagne` crée la séquence de démo à la validation d'une campagne (compteurs seulement, aucune personne, aucun corps de message). La reprise d'une telle séquence reste refusée : « Un frein est actif : le feu vert n'est pas accordé… ».
- `creation.handlers.ts` : la validation appelle `ajouterSequenceDeCampagne`. L'estimation exposait déjà `exclusions.opposition` (affichée « Oppositions (N) ») : rien d'autre à changer.
- `campagnes.handlers.ts` : non modifié (une campagne programmée n'a pas de geste de pause ; le parcours passe par la séquence).

## Reste / limites
- Annuler la validation d'une campagne laisse sa séquence de démo en place (aucune suppression, données fictives seulement).
- Le spec Playwright n'est pas joué en local : il l'est par la CI de démo.

## Tests lancés
- Rouge d'abord : le spec e2e (la séquence n'apparaissait pas) ; verts après : `tsc --noEmit`, `eslint --max-warnings 0` sur les fichiers touchés, vitest ciblé (séquences, assistant, non-régression, couverture e2e).
- `ENVOI_REEL_ACTIF=false` : aucun envoi réel, aucune donnée de personne.
