# Lot CI-F1 — un seul build du frontend pour e2e et axe

Suivi : #680 (plan du 18/11, escalade CI-F1, décisions du pilotage « option 1 » puis « correctif (b) »). PR #777.

## Comportement
- `a11y.yml` construit le frontend UNE fois (job `build-frontend`), publie l'artefact `frontend-dist` et une empreinte sha256 de l'arbre.
- `axe-playwright` et le job `e2e` (qui appelle `e2e.yml` en `workflow_call`) dépendent de ce job. Chacun télécharge `frontend-dist`, recalcule l'empreinte et ÉCHOUE si le build est absent ou différent, avant toute mesure.
- `e2e.yml` n'a plus de déclencheurs propres ni de build. Les `paths` d'`a11y.yml` deviennent `frontend/**`, `backend/openapi/**` et `.github/**`, pour que e2e tourne dans les mêmes cas qu'avant.

## Garde `CiBrouillonsGardesIndispensablesTest`
- `ci`, `security`, `a11y` et `demo` : exigence stricte d'avant (les 4 types `pull_request`, dont `ready_for_review`).
- Exception pour `e2e` SEUL : workflow_call, appelé par `a11y` et par lui seul ; `a11y` porte les 4 types et des `paths` couvrant `frontend/**`, `backend/openapi/**`, `.github/**`.
- Mutation prouvée rouge : `demo.yml` en `workflow_call` seul.

## Ce qui ne change pas
Contrôles exigés (Backend Laravel, Frontend React/Vite, Workers Node + Playwright, Aucune donnée nominative) intacts ; permissions `contents: read` ; `ENVOI_REEL_ACTIF` intact ; aucune migration.

## Fichiers
`.github/workflows/a11y.yml`, `.github/workflows/e2e.yml`, `backend/tests/Feature/Infra/PorteA11yMesureCeQuElleAnnonceTest.php`, `backend/tests/Feature/Infra/CiBrouillonsGardesIndispensablesTest.php`, ce document.
