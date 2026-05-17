# Phase 8 — Tests automatisés

> **Limitation honnête** : aucun test n'a été exécuté (pas d'environnement PHP/Node).
> Compteurs basés sur lecture statique des fichiers.

## Stats brutes

| Catégorie | Cible spec | Réel | Ratio |
|-----------|------------|------|-------|
| Pest backend | ≥ 200 | **14** | 7 % |
| Vitest frontend | ≥ 80 | **1** | 1.25 % |
| Vitest workers | ≥ 40 | **0** | 0 % |
| Playwright E2E | ≥ 50 scenarios | **4 describe** (~15 tests) | 30 % blocks |
| Tests load k6 | présent | ✅ `infra/loadtest/k6-api.js` | OK |
| Coverage CI | ≥ 70 % backend, ≥ 60 % frontend | **non mesuré** | — |

## Détail tests Pest backend (14)

- `tests/Unit/SmokeTest.php` — 2 tests (MockLLMClient + LLMResponseData)
- `tests/Unit/Auth/MagicLinkServiceTest.php` — 2 tests (issue + anti-enum)
- `tests/Unit/Dedup/DeduplicationServiceTest.php` — 6 tests (buildDedupKey, hash, SOURCE_TTL_DAYS)
- `tests/Unit/Email/EmailFinderServiceTest.php` — 4 tests (PATTERNS, candidates, renderPattern,
  detectPattern)

**Manque entièrement** :
- Tests endpoints (login, /companies, /coverage, /rgpd) — 0
- Tests RLS effective — 0
- Tests Audit hash chain integrity — 0
- Tests SSRF guard — 0
- Tests prompt injection — 0
- Tests Waterfall orchestration — 0
- Tests Scrapers (mock) — 0
- Tests Spatie Permission teams — 0
- Tests compute_size_category SQL function — 0
- Tests recompute_quality_score trigger — 0
- Tests RGPD erasure cascade — 0

## Tests Playwright E2E (4 describes)

- `auth.spec.ts` — 4 tests (Login, Magic Link, 2FA, Password Reset rendering)
- `coverage.spec.ts` — 2 tests (map + level switcher)
- `companies.spec.ts` — 3 tests (empty state + rows + search filter)
- `rgpd.spec.ts` — 3 tests (rgpd-requests, ai-act, audit-logs rendering)
- `a11y.spec.ts` — 4 tests (login, companies, coverage, rgpd a11y)

Total : ~16 tests E2E vs cible 50 scénarios.

## Forces

1. **Pest + Vitest + Playwright configurés** correctement (phpunit.xml, vitest.config.ts,
   playwright.config.ts).
2. **MockSmtpProber fixtures** + EmailFinderService tests = 4 tests significatifs.
3. **DeduplicationService 6 tests** valident le cœur métier dedup.
4. **CI GitHub Actions** lance les 3 stacks tests (job backend + frontend + workers).
5. **axe-core a11y intégré** dans Playwright.

## Faiblesses

1. **Couverture tests endpoint = 0** — pas un seul test feature HTTP.
2. **0 tests workers** — `tests/extract.test.ts` seulement (utilitaire pur).
3. **0 tests intégration DB** — RLS, audit chain, GENERATED columns non vérifiées.
4. **0 tests RGPD erasure** — Pas de test transaction multi-tables atomique.
5. **Coverage CI non configuré** — pas de gate quality.

## P0 bloquants prod

- **Couverture < 10 %** — déploiement aveugle, regression spiral garanti.
- **0 tests RLS** — risque cross-workspace data leak non vérifié.
- **0 tests audit hash chain** — falsification non détectée.

## Score Phase 8 : **18 / 100** (pondéré ×1.5 → 27/150)
