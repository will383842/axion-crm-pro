# S2-CONTRATS — Contrats conformité, pilotage, infrastructure, séquences (vague 2)

Plan : `docs/envoi/PLAN-FIN-ENVOI.md`, fiche S2-CONTRATS. Contrat seul : aucune route servie, aucune migration, aucun envoi (`ENVOI_REEL_ACTIF=false`).

## Fait
- `conformite.yaml` : `POST /envoi/opposition/import` (D8) — corps `application/json` seulement (`etape`, `contenu` de 1 048 576 octets au plus, `jeton`), jamais `multipart/form-data` ; réponses 200, 403, 409, 422 ; schémas `ImportOppositionsDemande` et `ImportOppositionsReponse` (lignes refusées par numéro et motif, jamais d'adresse).
- `pilotage.yaml` : `POST /envoi/feu-vert/decision` (D12) avec en-tête `If-Match`, `412` et `428` décrits comme propres à « accorde » ; `GET /envoi/feu-vert` gagne `empreinte_liste` et `decision` (facultatifs) ; description sans nombre de points figé (« lu dans `version` »).
- `commun.yaml` : `DomaineResume.adaptateur` facultatif (`faux`, `kumo`, `smtp`), seul schéma commun modifié, référencé par pilotage et infrastructure seulement.
- `infrastructure.yaml` : `501` sur `PUT /envoi/boites/{id}/reglages` ; `403` sur `GET /envoi/boites`, `GET /envoi/boites/{id}`, `GET /envoi/chauffe` ; `403` sur `reculer` (le `409` y était déjà).
- `sequences.yaml` : `403` sur `GET /envoi/sequences` et `GET /envoi/sequences/{id}` seulement.
- Types régénérés par `pnpm api:envoi` ; gestionnaires de démo pour la décision du feu vert (historique gardé, 428/412/409 pour « accorde », « retire » toujours accepté), l'import (simulation puis confirmation, jeton à usage unique) et `adaptateur` dans les domaines.
- Les deux nouveaux chemins sont déclarés comme trous : `trous/conformite.php`, `trous/pilotage.php`.

## Notes
- Le `GET /envoi/feu-vert` garde son schéma de réponse en ligne : `contrat-envoi-f03c.test.ts` (non modifié) lit ses champs obligatoires dans la route. `ListeFeuVert` ne sert qu'à la réponse de la décision.
- Le `403` de `arret-general` est dans `socle.yaml` (S2-SOCLE), non touché.
- Fichier de test ajouté hors liste de la fiche (obligatoire pour « tests rouges d'abord ») : `backend/tests/Feature/Envoi/ContratsS2Test.php` et `frontend/tests/lib/demo-envoi-s2-contrats.test.ts`.
- Vérifié en local : pint, PHPStan (fichier de test), Pest `ContratsS2Test` et `CouvertureContratEnvoiTest`, typecheck, eslint, vitest `tests/lib` et `tests/screens/envoi`, `pnpm api:envoi --verifier`. Les tests Pest qui ont besoin de PostgreSQL n'ont pas pu tourner ici (pas de base locale) : ils sont laissés à la CI.
