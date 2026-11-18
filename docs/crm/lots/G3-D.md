# G3-D — codes de secours (cahier G3-G5, § 8)

**But** : consulter et régénérer les codes de secours, et les accepter à la connexion par code courriel.

## Ce qui est servi
- `GET /compte/codes-secours` → `{restants}` (le nombre, jamais les codes).
- `POST /compte/codes-secours/regenerer {code}` : exige un code « geste » valide (sinon 422, jeu inchangé) ; compte non « courriel » : 409. Rend 10 codes montrés une seule fois ; ils REMPLACENT le jeu de `users.totp_recovery_codes` (mise à jour de colonne, rien n'est supprimé).
- `POST /auth/code-email/verifier` : `code` de 6 chiffres = code courriel ; de 10 caractères = code de secours, consommé au premier usage (ligne du compte verrouillée : deux requêtes simultanées ne passent pas toutes les deux). Autre longueur : 422.
- Un seul jeu de codes par compte, quelle que soit la méthode. `TwoFactorService` et `CodesConnexionCourriel` ne sont pas touchés.

## Filet
`nr20-routes.json` +2 ; `frontend/tests/non-regression/routes.test.ts` −2.

## Tests
`backend/tests/Feature/Auth/CodesSecoursCourrielTest.php` : tests 1 et 2 sous le propriétaire et sous `pgsql_app`.

## Mutations prouvées rouges
- Code consommé non retiré du jeu : test 2 rouge.
- Code « geste » non vérifié à la régénération : test 3 rouge.
