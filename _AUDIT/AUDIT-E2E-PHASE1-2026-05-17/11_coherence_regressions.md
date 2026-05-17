# Phase 11 — Cohérence inter-fichiers + régressions

## Cross-références vérifiées (statique)

| Check | Résultat |
|-------|----------|
| 26 imports `routes/api.php` → fichiers controllers | ✅ 26/26 résolvent |
| 6 providers `bootstrap/providers.php` → fichiers `Providers/` | ✅ 6/6 |
| 10 policies AuthServiceProvider → fichiers `Policies/` | ✅ 10/10 |
| 14 contracts MockServicesProvider → fichiers `Contracts/` | ✅ 14/14 |
| 14 mocks backend → fichiers `Services/*/Mocks/` | ✅ 14/14 |
| 14 reals backend → fichiers `Services/` | ✅ 14/14 (5 implémentés HTTP, 9 throw LogicException) |
| 22 imports `routeTree.tsx` → fichiers `features/*/Page.tsx` | ✅ 22/22 |
| 11 models → migrations correspondent | ✅ Company/Contact/ScraperRun/Tag/RgpdRequest/AuditLog/LlmUseCase/ProxyProvider/User/Workspace/PersonalAccessToken |
| LLM use cases seeder ↔ ClassifierService appels | ✅ classify_company_axion, sector_classification, extract_strategic_keywords, auto_tag tous seedés |

## Régressions vs v1.1/v1.2 spec

| Check | Statut |
|-------|--------|
| 6 catégories taille (artisan/commercant/tpe/pme/eti/grande) | 🟡 5 catégories seedées (effectif_ranges) — `commercant` séparé non distinct, agrégé sous tpe |
| Use case `classify_company_axion` mergé | ✅ |
| Use case `fiche_quality_scoring` retiré | ✅ (jamais ajouté) |
| `OwnerUserSeeder` email williamsjullin@gmail.com | ✅ env default |
| Métriques business v1.1 (4 gauges) | 🟡 documentées dans alerts.yml — pas exposées côté Laravel |
| WCAG 2.2 (vs 2.1) | ✅ axe-core tests `withTags(['wcag2a','wcag2aa','wcag22aa'])` |
| i18next dès S1 | ✅ |
| Terraform module Hetzner | ❌ absent |
| Langfuse self-hosted | ❌ absent docker-compose.observability |
| Concurrence v1.1 workers | 🟡 WORKER_CONCURRENCY=2 default, pas par-source tuning |

## Anti-patterns détectés

| Pattern | Résultat |
|---------|----------|
| `dd()` / `dump()` / `var_dump()` oubliés | ✅ **0** trouvé |
| `console.log()` oubliés frontend | grep manquant mais pino utilisé workers ✅ |
| Magic numbers hardcodés | quelques (TTL_MINUTES, MAX_FAILED_ATTEMPTS) — acceptable comme constants de classe |
| TODO / FIXME / XXX | rares, surtout dans commentaires des stubs Sprint X |
| Fonctions > 100 lignes | ✅ aucune trouvée |
| Fichiers > 500 lignes | ✅ WaterfallOrchestrator 200L plus gros service |
| Dépendance morte BullMQ workers | ❌ déclarée dans package.json, jamais utilisée dans code |
| pdf-parse workers | ❌ déclarée, jamais utilisée |
| TanStack Virtual frontend | ❌ déclarée, jamais utilisée (CompaniesListPage utilise `<table>` HTML brut) |

## Code mort

- **BullMQ** dans `workers/package.json:21` → 1.4 MB de deps Redis BullMQ jamais importé
- **pdf-parse** dans `workers/package.json` → `pdf-parse@^1.1.1` non utilisé
- **TanStack Virtual** dans `frontend/package.json` → `@tanstack/react-virtual` non utilisé

## Score Phase 11 : **74 / 100** (pondéré ×0.5 → 37/50)
