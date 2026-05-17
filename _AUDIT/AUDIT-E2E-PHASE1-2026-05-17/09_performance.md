# Phase 9 — Performance & Optimisation

> Aucun build/Lighthouse/k6 lancé. Évaluation lecture statique.

## Frontend bundle

| Critère | Cible | Réalité |
|---------|-------|---------|
| Build succès | ✅ | **non testé** (`pnpm build` pas lancé) |
| Bundle main ≤ 500 KB gz | requis | **non mesuré** |
| Lazy-load MapLibre | requis | ❌ chargée au mount dans FranceCoverageMap (pas dynamic import) |
| Code splitting routes | requis | ✅ Vite config `manualChunks` (react, router, query, maplibre) |
| Images optimisées | webp + responsive | non utilisées Phase 1 |

## Backend

| Critère | Cible | Réalité |
|---------|-------|---------|
| Index DB | spec/03 indexes | ✅ couverts (workspace+naf, dept, geo gist, denomination_normalized trgm) |
| Materialized view coverage_matrix_cells | hourly refresh | ✅ migration 000006 + scheduler `coverage:refresh-matrix` |
| pgbouncer transaction mode | requis prod | ❌ pas configuré |
| Eloquent N+1 detection | requis | ❌ pas de `laravel-debugbar` |
| Spatie QueryBuilder eager load | requis | partiel (CompaniesController.show avec `->load(['contacts','tags'])`) |

## Workers

| Critère | Cible | Réalité |
|---------|-------|---------|
| Concurrence revue v1.1 | 2/2/4/2/2/1/3 | ✅ via `WORKER_CONCURRENCY=2` default |
| Restart 500 jobs | P11 audit | ❌ pas implémenté |
| Memory threshold pre-shutdown | 4.8 GB | ❌ pas implémenté |
| Graceful shutdown SIGTERM | ✅ | ✅ closeBrowser sur SIGTERM/SIGINT |

## DB

| Critère | Cible | Réalité |
|---------|-------|---------|
| Partitionnement pg_partman | audit_logs/scraper_runs/llm_usage | ❌ retiré (table BIGSERIAL simple) |
| `idx_runs_dedup` | P0 audit | ✅ migration 000003 `scraper_runs.dedup_key` UNIQUE |
| Stats VACUUM tuning | requis | ❌ pas tuné `autovacuum_vacuum_scale_factor` |

## Forces

1. **Vite config manualChunks** — separation react/router/query/maplibre.
2. **Materialized view coverage_matrix_cells** — refresh hourly via scheduler.
3. **Indexes Postgres couvrants** — workspace+naf, denomination_normalized GIN trgm, geo_point gist.
4. **POC #5 validated p95 35.94 ms** pour dedup 10M rows.
5. **scraper_runs.dedup_key UNIQUE** — index couvrant le check freshness.

## Faiblesses

1. **MapLibre pas lazy** — chargée au mount, ~600 KB gzipped.
2. **Pas de pg_partman** — audit_logs croît linéairement, retention manuelle.
3. **Pas de pgbouncer** — limite connexions Postgres concurrence horizon workers.
4. **Pas d'image responsive Phase 1** — pas de webp/avif sizes.
5. **No N+1 detection** — debugbar absent, queries peuvent boucler en silent.

## Score Phase 9 : **65 / 100** (pondéré ×0.5 → 32.5/50)
