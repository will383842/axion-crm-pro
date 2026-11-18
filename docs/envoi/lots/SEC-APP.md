# SEC-APP — retirer les appareils de confiance quand le mot de passe change

**But** : un changement ou une réinitialisation du mot de passe ferme aussi les appareils de confiance, pas seulement les sessions et les jetons.

## Ce qui change
- `AppareilsConfiance::retirerTous(User, ?jetonAGarder): int` : un seul `UPDATE ... SET retire_le`, filtré sur `user_id`. Jamais de `DELETE`.
- Changement (`PasswordChangeController::change`) : tous les appareils sont retirés, sauf celui de la requête, et seulement si son cookie est reconnu pour CE compte. Cookie absent, faux ou d'un autre compte : tout est retiré.
- Réinitialisation (`PasswordResetController::reset`) : tout est retiré, cookie compris.
- Une seule transaction (mot de passe, jetons, appareils). Une erreur sur le retrait laisse l'ancien mot de passe et rend 500.
- Journal : `appareils_confiance.retires` avec `user_id`, `nombre`, `cause`. Ni jeton ni IP.

## Remarque
À la réinitialisation, le jeton du lien est déjà consommé avant la transaction (anti-rejeu, inchangé). Après une panne, il faut redemander un lien.

## Tests
`backend/tests/Feature/Auth/AppareilsRetraitMotDePasseTest.php` : tests 1 à 3 sous le propriétaire et sous `pgsql_app`.

## Mutations prouvées rouges
- Retrait du filtre `user_id` : tests 2 et 4 rouges.
- Cookie gardé à la réinitialisation : test 3 rouge.
- Cookie présenté non gardé au changement : test 1 rouge.
- Sans `DB::transaction` au changement : test 6 rouge.
