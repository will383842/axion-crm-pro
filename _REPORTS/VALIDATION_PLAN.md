# Plan de validation runtime — Axion CRM Pro

> Date : 2026-05-17 — autopilot Claude Opus 4.7.
> Note honnête : le code livré n'a pas été exécuté pendant l'autopilot. Cet environnement
> (Windows sandbox) n'a ni PHP/Composer/Node/Docker actifs pour validation. Ce document
> liste les checks que Will doit lancer en premier pour valider le tag
> `phase1-mocks-complete-2026-05-17`.

## Niveau 1 — Dépendances résolvent

```bash
# Backend PHP 8.3
cd backend && composer install
# Doit terminer sans erreur. ~80 paquets, taille vendor ~120 Mo.

# Frontend pnpm
cd frontend && pnpm install
# Génère pnpm-lock.yaml. ~40 paquets dont React 19, Vite 6, Tailwind 4, MapLibre 4, TanStack Router.

# Workers pnpm
cd workers && pnpm install
# ~15 paquets dont Playwright 1.49, cheerio, ioredis, pino, bullmq (non utilisé : à retirer plus tard).
```

## Niveau 2 — Compilation / typecheck

```bash
# PHPStan level 8 + Pint
cd backend && composer analyse && composer lint

# TypeScript strict
cd frontend && pnpm typecheck && pnpm lint
cd workers  && pnpm typecheck && pnpm lint
```

**Bugs probables restants (déduits sans exec)** :
- `cheerio` import — vérifier que `import * as cheerio from 'cheerio'` résout en v1 (sinon `import { load }`).
- `@axe-core/playwright` version — vérifier que la 4.10 expose bien `AxeBuilder` (sinon 4.x default).
- Spatie `QueryBuilder` v6.2 + Laravel 12 compat — pourrait nécessiter v6.3+.

## Niveau 3 — Boot stack

```bash
make up
make ps   # tous les services healthy ?
docker compose logs api | head -50   # erreur Laravel ?
```

**Si erreur boot Laravel**, suspecter en ordre :
1. APP_KEY pas généré → `docker exec axion-crm-api php artisan key:generate`
2. config publish manquant pour Sanctum/Horizon → vérifier `composer.json` et `bootstrap/providers.php`
3. Composer post-autoload-dump n'a pas tourné → manuel `composer dump-autoload`

## Niveau 4 — Migrations + seeders

```bash
make fresh   # = migrate:fresh --seed
```

**Bugs probables (déduits sans exec)** :
- Migration `pgcrypto` digest() dans GENERATED column — testée IMMUTABLE OK mais Postgres peut chipoter
- Migration `audit_logs` BIGSERIAL PRIMARY KEY (id seul) — pas partitionné contrairement à la spec/03
  (partman bootstrap retiré pour simplicité MVP, ré-ajouter Sprint 13)
- Seeder `OwnerUserSeeder` insert avec UUID generés par PHP `Str::uuid()` v4 — OK
- `EffectifRangesSeeder` codes incluent `NN` qui n'est pas dans CHECK constraint
  (la table ne CHECK pas la valeur, OK)

## Niveau 5 — Tests Pest backend

```bash
make test-backend
```

Tests attendus verts :
- `tests/Unit/SmokeTest.php` — MockLLMClient + LLMResponseData (Sprint 1)
- `tests/Unit/Dedup/DeduplicationServiceTest.php` — 6 tests (Sprint 4)
- `tests/Unit/Email/EmailFinderServiceTest.php` — 4 tests (Sprint 8)
- `tests/Unit/Auth/MagicLinkServiceTest.php` — 2 tests (Sprint 3 ; requiert RefreshDatabase)

## Niveau 6 — Tests Vitest frontend + workers

```bash
make test-frontend test-workers
```

Tests attendus verts :
- `frontend/tests/smoke.test.ts` — process.env exists
- `workers/tests/extract.test.ts` — 3 tests extractEmails/extractPhones

## Niveau 7 — E2E Playwright

```bash
cd frontend && pnpm exec playwright install chromium firefox webkit
pnpm e2e
```

5 specs × 3 projets (chromium + firefox + mobile-safari) = 15 runs.
**Note** : nécessite `app.localhost` self-signed Caddy → option `ignoreHTTPSErrors: true` déjà set.

## Niveau 8 — Audit OWASP + DR drill

```bash
docker exec axion-crm-api php artisan app:pentest-self-check
docker exec axion-crm-api php artisan audit:verify-chain
# Si tu as backups configurés :
bash infra/scripts/dr-drill.sh
```

## Bugs CRITIQUES déjà fixés (audit 3 honnête)

Voir `_AUDIT/AUDIT_3_2026-05-17_real.md` :
- C1 User/Workspace UUID keyType ✓ fixé `fedacce`
- C2 Contact upsert sans GENERATED column ✓ fixé `fedacce`
- C3 `\Queue::push()` invalide → DispatchScrapeJob::dispatch ✓ fixé
- C4 SsrfGuard appelé dans 5 HTTP clients ✓ fixé
- C5 Sanctum tokenable_id UUID custom PAT model ✓ fixé
- C7 EnsureFrontendRequestsAreStateful double-bind retiré ✓ fixé
- routeTree.ts → routeTree.tsx (JSX dans .ts) ✓ fixé

## Bugs probables non encore testés

- **Spatie Permission teams=true UUID model_id** : peut nécessiter override `getMorphClass()` côté User
- **Migration RLS policies** : `current_setting('app.current_workspace_id', true)` peut retourner '' au lieu de NULL si pas set, casser les comparaisons
- **Workers BRPOP** : `redis.brpop(key, timeout)` ioredis v5 retourne `null` ou `[key, value]` ; vérifier signature
- **Composer require pragmarx/google2fa-laravel + pragmarx/google2fa** : conflict possible si version mismatch
- **darkaonline/l5-swagger v9** : nécessite annotations OpenAPI dans les controllers (vide actuellement → /docs sera vide)
- **Frontend `useNavigate({ to: '/' })`** : signature TanStack Router v1.85+ ; peut nécessiter typed routes generation (`pnpm exec tsr generate`)

## Roadmap post-validation

| Priorité | Item |
|----------|------|
| P0 | Run `make fresh && make test` puis fix bugs runtime restants |
| P0 | Run `make test-e2e` après Playwright install |
| P1 | Souscrire credentials Anthropic + Webshare + IPRoyal + 2captcha |
| P1 | DPA papier providers (action humaine) |
| P1 | Provisionner Hetzner CPX42 prod (terraform/hcloud) |
| P1 | Acheter domaine `axion-crm-pro.com` + DNS Cloudflare |
| P2 | Activer pg_partman audit_logs (Sprint 13) |
| P2 | Pentest tiers (Will) |
