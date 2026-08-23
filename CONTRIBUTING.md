# Contributing — Axion CRM Pro

## Workflow

1. Brancher depuis `main` : `git checkout -b feat/<topic>`
2. Conventional Commits (`feat`, `fix`, `test`, `docs`, `chore`, `refactor`, `perf`, `ci`)
3. PR template auto-rempli (description + test plan + check-list sécurité)
4. CI verte requise (typecheck + lint + tests Pest/Vitest)
5. Squash & merge sur `main`

## Quality gates

- PHPStan level 8 (`composer analyse`)
- TypeScript strict (`pnpm typecheck`)
- ESLint flat config (`pnpm lint --max-warnings 0`)
- Axe-core 0 violation critical sur 4 pages clés

## Objectifs NON gardés — personne ne les mesure

⚠️ Ce qui suit ressemble à une porte et n'en est pas une. Ces deux lignes
figuraient jusqu'au 2026-08-22 sous « Quality gates », où elles annonçaient un
seuil bloquant :

- ~~Pest backend ≥ 75 % couverture sur services métier~~
- ~~Vitest frontend ≥ 60 % couverture~~

**La CI ne mesure la couverture nulle part** (constat A09-006). Mesure du
2026-08-22, dans `.github/workflows/ci.yml` : le setup PHP porte
`coverage: none`, le pas Pest lance `php vendor/bin/pest --colors
--configuration phpunit-ci.xml` sans option de couverture, et les deux pas
frontend lancent `pnpm test`, jamais `pnpm test:coverage`. La configuration
frontend le dit elle-même, juste au-dessus des seuils qu'elle déclare
(`frontend/vitest.config.ts`, bloc `coverage`) : « SEUILS DÉCORATIFS EN
L'ÉTAT […] ces nombres ne bloquent rien et n'ont jamais rien bloqué ».

Annoncer une porte qui n'existe pas est pire que n'annoncer aucune porte : une
revue qui lit « ≥ 75 % de couverture » en conclut que le risque est couvert et
cesse de le regarder. Tant que la couverture n'est pas mesurée en CI, ces
nombres restent des OBJECTIFS.

**Pour en faire une vraie porte** — et c'est une décision de Will, pas un
correctif : mesurer d'abord la couverture réelle, poser le seuil À CETTE
VALEUR, puis le faire décroître-vers-le-haut comme la baseline PHPStan. Armer
directement 75 % / 60 % ferait rougir toutes les PR du jour au lendemain.

## Dépendances — 🧊 GELÉES jusqu'à la fin de l'étape 0 (2026-08-18)

Aucune montée de dépendance n'est fusionnée — ni majeure, ni mineure, ni
corrective — tant que l'étape 0 (pose du harnais de tests) n'est pas terminée.
Les mises à jour de **sécurité** ne sont pas gelées.

Le gel est appliqué dans `.github/dependabot.yml` (règles `ignore` /
`update-types: version-update:semver-*`, aucun `groups:`).

📄 **Décision, inventaire chiffré, procédure de dégel** :
[`_REPORTS/2026-08-18_POLITIQUE-DEPENDANCES-ETAPE-0.md`](_REPORTS/2026-08-18_POLITIQUE-DEPENDANCES-ETAPE-0.md)

⛔ Ne pas monter une dépendance « au passage » dans une PR fonctionnelle.
⛔ Ne pas répondre `@dependabot ignore …` sur une PR : la règle créée est
persistante, stockée hors du dépôt, et survivrait au dégel sans qu'aucun
fichier ne la mentionne.

## Sécurité

- **AUCUN secret en clair dans le code ou git** — tout via `.env` (gitignored) ou Doppler
- **AUCUN appel réseau réel** sans flag `MOCK_*=false` explicite
- **SSRF guard** obligatoire (`SsrfGuard::ensure($url)`) avant tout fetch HTTP externe
- **Préfixe `ext_`** pour toute variable LLM provenant d'input externe (sanitize anti prompt-injection)
- **RLS PostgreSQL** activée — toute requête doit passer par `SetCurrentWorkspace` middleware
- **Hash chain audit** — toute mutation passe par `AuditHashChainLogger`

## Tests obligatoires sur les services critiques

- LLM Router (cost cap, sanitize, fallback chain, idempotency cache)
- DeduplicationService (6 niveaux + dedup_key stable)
- AuthService (throttle, lock, regenerate session)
- TwoFactorService (TOTP window, recovery codes one-shot)
- GdprErasureService (transaction atomique, opt-out cascade)
- AuditHashChain (verifyChain valid + invalid scenarios)

## Doctrine technique (héritée d'Axion-IA)

- Hébergement UE par défaut (RGPD)
- Mix LLM open-source + propriétaires, Claude pivot
- OWASP Top 10 appliqué, journalisation immuable (hash chain), minimisation PII
- Code custom — pas de no-code en production
- Aucun lock-in : LLM Router pluggable, ProxyProvider pluggable, ScraperPlugin pluggable

## Avant de pusher

```bash
# Backend
docker exec axion-crm-api composer test
docker exec axion-crm-api composer analyse
docker exec axion-crm-api composer lint

# Frontend
docker exec axion-crm-app pnpm typecheck
docker exec axion-crm-app pnpm lint
docker exec axion-crm-app pnpm test

# Workers (depuis host) — il n'existe AUCUN conteneur `axion-crm-worker-*` :
# docker-compose.yml déclare 8 services (postgres, redis, api, horizon, reverb,
# scheduler, app, caddy) et pas un seul worker. Mesure du 2026-08-22, constat
# A09-010 : ces deux lignes prescrivaient donc une commande impossible.
# Sous-shell : la ligne suivante repart de la racine du dépôt.
(cd workers && pnpm typecheck)
(cd workers && pnpm test)
```

## Bug critique en prod

1. Suivre `infra/runbooks/03-site-down.md`
2. Si compromission suspectée → suivre `05-rotate-secrets.md`
3. Ouvrir un ticket post-mortem dans `_INCIDENTS/YYYY-MM-DD-<topic>.md`

## Contact

- Maintainer : Will (williamsjullin@gmail.com)
- DPO : contact@axion-ia.com
