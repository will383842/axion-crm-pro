# B8-F — assistant : A/B, boîtes et plafond grisés tant qu'ils sont refusés

Cahier : `B6-B8-cahier.md`, § 11.2. Dépend de B8-D (le serveur rend `capacites`) et de B8-E.

## Ce que fait le lot
- L'écran lit `capacites` (`ab`, `boites`, `plafond`) dans la réponse de `GET /envoi/creation/contexte`.
  Tout ce qui n'est pas exactement `true` reste refusé (échec fermé, y compris avant la réponse).
- Étape 2 : la case « Tester deux objets (A/B) » est désactivée, avec « Bientôt : le test A/B n'est pas encore pris en charge par l'envoi » (`aria-describedby`).
- Étape 3 : plafond et boîtes désactivés, même explication ; plus de pré-sélection des boîtes.
- `saisie.ts` : sans la capacité, `objet_b`, `boites` et `plafond_jour` ne partent jamais, même si un ancien brouillon les contient. L'écran dit « Réglages retirés : non pris en charge ».
- Capacités vraies : comportement d'avant (les tests existants n'ont pas été modifiés).

## Fichiers
- M `frontend/src/features/envoi/creation/EtapeMessage.tsx`
- M `frontend/src/features/envoi/creation/EtapeDepart.tsx`
- M `frontend/src/features/envoi/creation/saisie.ts`
- M `frontend/src/demo/handlers/envoi/creation.handlers.ts` (le contexte de la démo simule des capacités vraies : les tests existants du parcours complet restent intacts)
- C `frontend/tests/screens/envoi/AssistantCapacites.test.tsx`
- C `docs/envoi/lots/B8-F.md`

`api.ts` et `Assistant.tsx` ne sont pas dans la liste : les capacités sont donc portées par la saisie
(`Saisie.capacites`, refusées par défaut) et recopiées depuis le contexte par les deux étapes.

## Mutations prouvées rouges
- Pré-sélection des boîtes remise (et case cochée sans garde) : 2 tests rouges.
- `objet_b` envoyé malgré `ab: false` : 3 tests rouges.

Aucune migration, aucun drapeau touché (`ENVOI_REEL_ACTIF` reste false).
