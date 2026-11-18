# AUTH-RL — codes courriel : verrou sur le renvoi, deux tests manquants

Relectures de G3-A/G3-B (#697, #700). Dépend de G3-D (#776, fusionnée).

## Ce que fait le lot
- `CodesConnexionCourriel::renvoyer` pose `pg_advisory_xact_lock(hashtextextended('code_courriel|' || user_id, 0))` AVANT de compter, et le garde jusqu'à l'insertion du code : deux renvois simultanés ne lisent plus le même total (le plafond de `double_auth.renvois_max` est tenu).
- Le courriel part APRÈS la validation de la transaction : le verrou n'attend pas le canal. Si le canal lève, la ligne reste (comportement d'avant).
- `emettre` garde son comportement : l'écriture est extraite dans `inserer` (même code), rien d'autre ne change.
- Tests ajoutés : lien magique d'un compte « courriel » ⇒ `requires_2fa` ; session régénérée après la vérification du code.

## Fichiers
- M `backend/app/Services/Auth/CodeCourriel/CodesConnexionCourriel.php`
- C `backend/tests/Feature/Auth/CodesCourrielRelectureTest.php`
- C `docs/envoi/lots/AUTH-RL.md`

Aucune route, aucune migration, aucun drapeau touché (`ENVOI_REEL_ACTIF` reste false).

## Tests
`CodesCourrielRelectureTest` : le test de course joue DEUX processus (le second tient le verrou du compte, pose le dernier code permis, valide ; le premier renvoi attend, voit ce code et est refusé). Joué sous le propriétaire ET sous `pgsql_app`.

## Mutations prouvées rouges
- Verrou retiré : test 1 rouge (les deux modes).
- `session()->regenerate()` retiré de `CodeEmailController::verifier` : test 3 rouge.
- `requires_2fa` forcé à faux dans `MagicLinkController::verify` : test 2 rouge.
