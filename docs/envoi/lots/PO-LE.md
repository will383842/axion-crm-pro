# PO-LE — liste des personnes : colonne et filtre « Réseau » (écran)

Cahier : `docs/envoi/lots/PONT-LINKEDIN-cahier.md` (PR brouillon #691), lot PO-LE. Dépend de PO-L (fusionné, #696).

## Fichiers
- M `frontend/src/features/crm-console/PersonnesPage.tsx`
- C `frontend/tests/screens/PersonnesReseau.test.tsx`
- M `frontend/src/demo/handlers/crm/alimenter.handlers.ts` (clé `reseaux` et filtre `origine_reseau` dans la démo seulement)
- C `docs/envoi/lots/PO-LE.md`

## Comportement
- La colonne « Réseau » (libellés de `features/origines/libelles.ts`, lus seulement) et le filtre (réseaux de
  `GET /crm/origines/par-reseau`, via `useReseauxOrigine`) n'existent que si cette lecture répond `actif`.
- 404, `actif: false` ou erreur : ni colonne ni filtre, et `origine_reseau` n'est jamais envoyé (liste ni export).
- 422 : le message du serveur est affiché tel quel, sans réessai.
- Personne sans réseau (liste vide ou clé absente) : cellule « — ».
- Aucune écriture, aucune suppression ; rien à voir avec l'envoi réel.

## Preuves
- Rouge d'abord (local, vitest) : 3 tests sur 5 en échec avant le code (colonne, filtre/paramètre, 422) ; les 2 cas
  « fermé » passaient déjà car l'écran n'avait rien à cacher.
- Vert (local, vitest) : 5/5, plus `PersonnesLettreGuide` et `BadgeOpposee` inchangés.
- Mutation « colonne affichée malgré 404 » : le test 404 devient rouge (vérifié), puis code restauré.
- Aucun test Pest/PostgreSQL : lot 100 % écran. La CI de la PR fait foi pour le reste.
