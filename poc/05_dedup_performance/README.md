# POC #5 — Anti-doublon performance à 1 M+ rows

> **Hypothèse à valider** (spec v1.2 `12_coverage_matrix_deduplication.md` § Niveau 3) :
> la query dedup `shouldScrape(target_id, source)` reste sous **50 ms p95** quand `scraper_runs` contient **10 M rows partitionnées par mois**.
>
> **Budget : 0 €** (Postgres 16 Docker local).
> **Durée : ~2 heures.**

---

## Pré-requis

- Docker Desktop installé et démarré
- Node 22 LTS
- pnpm
- ~5 GB disque libre (pour 10M rows)

---

## Setup

```powershell
cd "C:\Users\willi\Documents\Projets\Axion-CRM-Pro\poc\05_dedup_performance"
pnpm install
copy .env.example .env
```

`.env` par défaut OK (Postgres localhost).

---

## Lancement

```powershell
# 1. Démarre Postgres 16 + extensions pg_partman + pg_trgm
pnpm run docker:up

# 2. Crée les tables Phase 1 minimum (scraper_runs partitionnée + companies + indexes)
pnpm run db:migrate

# 3. Seed 10M rows synthétiques scraper_runs (12 partitions mensuelles)
#    Durée ~5-10 min selon machine
pnpm run db:seed

# 4. Lance le benchmark : 10 000 queries dedup parallélisées
pnpm run benchmark

# 5. Lit le RESULTS.md généré
type RESULTS.md
```

---

## Critères GO / NO-GO

| KPI | Cible spec | Mesure réelle | Statut |
|-----|------------|----------------|--------|
| p50 latence query dedup | < 10 ms | (rempli par benchmark) | |
| **p95 latence query dedup** | **< 50 ms** | | **CRITIQUE** |
| p99 latence query dedup | < 200 ms | | |
| EXPLAIN ANALYZE | utilise `idx_runs_dedup` | | |
| Aucun seq scan | sur `scraper_runs_*` | | |

**Si p95 > 50 ms → NO-GO.** Optimisations à tester :
- Ajouter index covering `INCLUDE (status, ...)` 
- BRIN index sur `started_at` (cf. spec § Bottlenecks)
- Partitionnement hebdomadaire au lieu de mensuel

---

## Cleanup après POC

```powershell
pnpm run docker:down       # arrête Postgres
pnpm run docker:purge      # supprime volumes (libère ~5 GB disque)
```
