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
- Pest backend ≥ 75 % couverture sur services métier
- Vitest frontend ≥ 60 % couverture (config dans vitest.config.ts)
- Axe-core 0 violation critical sur 4 pages clés

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

# Workers
docker exec axion-crm-worker-google-maps pnpm typecheck
docker exec axion-crm-worker-google-maps pnpm test
```

## Bug critique en prod

1. Suivre `infra/runbooks/03-site-down.md`
2. Si compromission suspectée → suivre `05-rotate-secrets.md`
3. Ouvrir un ticket post-mortem dans `_INCIDENTS/YYYY-MM-DD-<topic>.md`

## Contact

- Maintainer : Will (williamsjullin@gmail.com)
- DPO : contact@axion-ia.com
