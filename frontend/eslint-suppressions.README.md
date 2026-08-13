# Suppressions ESLint — dette figée le 2026-08-13

`eslint-suppressions.json` (mécanisme natif ESLint ≥ 9.24, `--suppress-all`) fige
**73 violations pré-existantes** du front, constatées le 2026-08-13 lors du
durcissement de la CI (PR « fix/ci-reelle », Gate 0 de l'autopilot CRM).

## Pourquoi ce fichier existe

Jusqu'au 2026-08-13, `pnpm lint` **ne s'exécutait pas du tout** : la config
`eslint.config.mjs` appliquait les règles typées de `typescript-eslint` à des
fichiers hors projet TypeScript, ce qui faisait planter ESLint
(« You have used a rule which requires type information »). L'échec était masqué
en CI par un `|| true`. Une fois la config réparée, 73 violations réelles sont
apparues.

Les corriger toutes aurait demandé de modifier largement le code applicatif dans
une PR d'outillage. Le choix fait — le même que pour la baseline PHPStan du
backend — est de **figer la dette explicitement** plutôt que de neutraliser
l'outil.

## Règles d'usage

- **Le nouveau code doit être propre** : toute violation qui n'est pas déjà dans
  `eslint-suppressions.json` fait rougir la CI.
- **Ce fichier ne doit que décroître.** Ne jamais relancer `--suppress-all` pour
  faire taire de nouvelles violations. Pour retirer une entrée corrigée :
  `pnpm exec eslint . --prune-suppressions`.
- Deux avertissements ont été traités séparément, par commentaire uniquement
  (aucun changement de code exécuté) :
  - `src/main.tsx` : directives `eslint-disable no-console` inutilisées, retirées ;
  - `src/features/rgpd/AiActRegisterPage.tsx` : `react-hooks/exhaustive-deps`
    figé par directive datée — le correctif réel (mémoïser `rows`) reste à faire.
