# G3-E — écrans du code courriel et du code de secours

Cahier G3-G5, § 9. Dépend de G3-D (#776, fusionnée).

## Ce que fait le lot
- `DemandeCode` (fenêtre « code avant un geste sensible ») : un bouton « Envoyer le code par e-mail » appelle `POST /compte/code`. Réponse 200 : « Un code vient de partir ». 429 : « Trop de codes demandés ». 503 et autres : message d'envoi impossible. Le bouton de confirmation reste inchangé (6 chiffres).
- `CodeEmailPage` (connexion) : le lien « Utiliser un code de secours » bascule vers un champ de 10 caractères (lettres et chiffres) ; le serveur reçoit ces 10 caractères dans `code` (même route `POST /auth/code-email/verifier`). 422 et 429 gardent leurs messages. Le mode 6 chiffres est inchangé (tests existants non modifiés).
- Démonstration : `POST /compte/code` (plafond de 3, puis 429) et un code de secours de 10 caractères accepté à la connexion.

## Fichiers
- M `frontend/src/features/settings/securite/DemandeCode.tsx`
- M `frontend/src/features/auth/CodeEmailPage.tsx`
- M `frontend/src/demo/handlers/socle/ecrans-communs.handlers.ts`
- C `frontend/tests/screens/SecuriteCodeCourriel.test.tsx`
- C `docs/envoi/lots/G3-E.md`

Aucune route nouvelle (l'instantané NR-20 ne bouge pas), aucune migration, aucun drapeau touché (`ENVOI_REEL_ACTIF` reste false).

## Mutations prouvées rouges
- Appel à `POST /compte/code` retiré : 2 tests rouges.
- Message 429 de la demande retiré : 1 test rouge.
- Longueur du code de secours ramenée à 6 : 1 test rouge.
