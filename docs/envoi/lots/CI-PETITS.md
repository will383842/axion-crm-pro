# CI-PETITS — trois remarques de relecture de la CI (#721, #723, #730)

## Ce qui change
- `ci.yml` : le commentaire du plafond de 20 minutes disait « plus de 5× la durée utile ». Faux : la part la plus longue dure environ 10 minutes, soit environ 2×.
- `security.yml` : les jobs `composer-audit` et `pnpm-audit` utilisent `!cancelled()` à la place de `always()`. Un run annulé ne lance plus d'audit ; un `changements` en échec lance toujours l'audit (échec fermé).
- `deploy-direct-ssh.yml` : deux commentaires périmés corrigés (le job porte `contents: read` et `actions: read`). Commentaires seulement, permissions inchangées.

## Contrôles exigés pour fusionner
Aucun renommé, aucun retiré : Backend Laravel, Frontend React/Vite, Workers Node + Playwright, Aucune donnée nominative.

## Appelants des workflows réutilisables
`ci.yml` est appelé par `deploy-direct-ssh.yml` : seul un commentaire de `ci.yml` change, donc rien à vérifier côté permissions. `security.yml` n'est pas réutilisable.

## Tests
- Nouveau test : les conditions `if:` des deux audits commencent par `!cancelled()` et ne contiennent plus `always()` (vu rouge avant, vert après).
- Une assertion existante exigeait `always()` : elle exige maintenant `!cancelled()` (même rigueur, autre fonction d'état). Les autres assertions sont inchangées.

## Mutation
`always()` remis sur `pnpm-audit` ⇒ 2 tests rouges. Restauré.
