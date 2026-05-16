# POC #5 — Anti-doublon perf — RÉSULTATS

> **Date exécution :** 2026-05-16T19:13:42
> **Machine :** win32 x64 Node v24.12.0

## Setup

- Volume DB testé : **10 000 000 rows scraper_runs** partitionnées
- Partitions : 12 mensuelles
- Index dedup : `idx_runs_dedup (target_id, source, completed_at DESC) WHERE status='ok'`
- Queries benchmark : 10000 (concurrence 10)

## Latences mesurées (ms)

| Percentile | Mesuré | Cible spec | Statut |
|------------|--------|------------|--------|
| p50 | 10.46 | < 10 | 🟡 |
| **p95** | **35.94** | **< 50** | **🟢 GO** |
| p99 | 87.91 | < 200 | 🟢 |
| max | 572.17 | — | — |
| moyenne | 15.25 | — | — |

Throughput global : **655 qps** sur 15.3s.

## Vérification plan d'exécution

- Utilise `idx_runs_dedup` : ✅ YES
- Présence Seq Scan sur `scraper_runs` : ✅ NO (GOOD)
- **Statut index** : 🟢 OK

### Plan d'exécution sample

```
Limit  (cost=5.13..13.18 rows=1 width=16) (actual time=4.980..4.986 rows=0 loops=1)
  Buffers: shared hit=2 read=33
  ->  Merge Append  (cost=5.13..101.68 rows=12 width=16) (actual time=4.978..4.983 rows=0 loops=1)
        Sort Key: scraper_runs.completed_at DESC
        Buffers: shared hit=2 read=33
        ->  Index Scan using scraper_runs_2025_06_target_id_source_completed_at_idx on scraper_runs_2025_06 scraper_runs_1  (cost=0.14..8.16 rows=1 width=16) (actual time=0.030..0.030 rows=0 loops=1)
              Index Cond: ((target_id = '16f75bbf-9aba-4beb-a256-10f62ede7137'::uuid) AND (source = 'insee'::text) AND (completed_at > (now() - ('90 days'::cstring)::interval)))
              Buffers: shared hit=2
        ->  Index Scan using scraper_runs_2025_07_target_id_source_completed_at_idx on scraper_runs_2025_07 scraper_runs_2  (cost=0.43..8.45 rows=1 width=16) (actual time=1.069..1.069 rows=0 loops=1)
              Index Cond: ((target_id = '16f75bbf-9aba-4beb-a256-10f62ede7137'::uuid) AND (source = 'insee'::text) AND (completed_at > (now() - ('90 days'::cstring)::interval)))
              Buffers: shared read=3
        ->  Index Scan using scraper_runs_2025_08_target_id_source_completed_at_idx on scraper_runs_2025_08 scraper_runs_3  (cost=0.43..8.46 rows=1 width=16) (actual time=0.532..0.533 rows=0 loops=1)
              Index Cond: ((target_id = '16f75bbf-9aba-4beb-a256-10f62ede7137'::uuid) AND (source = 'insee'::text) AND (completed_at > (now() - ('90 days'::cstring)::interval)))
              Buffers: shared read=3
        ->  Index Scan using scraper_runs_2025_09_target_id_source_completed_at_idx on scraper_runs_2025_09 scraper_runs_4  (cost=0.43..8.46 rows=1 width=16) (actual time=0.168..0.169 rows=0 loops=1)
              Index Cond: ((target_id = '16f75bbf-9aba-4beb-a256-10f62ede7137'::uuid) AND (source = 'insee'::text) AND (completed_at > (now() - ('90 days'::cstring)::interval)))
              Buffers: shared read=3
        ->  Index Scan using scraper_runs_2025_10_target_id_source_completed_at_idx on scraper_runs_2025_10 scraper_runs_5  (cost=0.43..8.46 rows=1 width=16) (actual time=0.266..0.266 rows=0 loops=1)
              Index Cond: ((target_id = '16f75bbf-9aba-4beb-a256-10f62ede7137'::uuid) AND (source = 'insee'::text) AND (completed_at > (now() - ('90 days'::cstring)::interval)))
              Buffers: shared read=3
        ->  Index Scan using scraper_runs_2025_11_target_id_source_completed_at_idx on scraper_runs_2025_11 scraper_runs_6  (cost=0.43..8.46 rows=1 width=16) (actual time=0.124..0.124 rows=0 loops=1)
              Index Cond: ((target_id = '16f75bbf-9aba-4beb-a256-10f62ede7137'::uuid) AND (source = 'insee'::text) AND (completed_at > (now() - ('90 days'::cstring)::interval)))
              Buffers: shared read=3
        ->  Index Scan using scraper_runs_2025_12_target_id_source_completed_at_idx on scraper_runs_2025_12 scraper_runs_7  (cost=0.43..8.46 rows=1 width=16) (actual time=0.136..0.136 rows=0 loops=1)
              Index Cond: ((target_id = '16f75bbf-9aba-4beb-a256-10f62ede7137'::uuid) AND (source = 'insee'::text) AND (completed_at > (now() - ('90 days'::cstring)::interval)))
              Buffers: shared read=3
        ->  Index Scan using scraper_runs_2026_01_target_id_source_completed_at_idx on scraper_runs_2026_01 scraper_runs_8  (cost=0.43..8.46 rows=1 width=16) (actual time=0.178..0.178 rows=0 loops=1)
              Index Cond: ((target_id = '16f75bbf-9aba-4beb-a256-10f62ede7137'::uuid) AND (source = 'insee'::text) AND (completed_at > (now() - ('90 days'::cstring)::interval)))
              Buffers: shared read=3
        ->  Index Scan using scraper_runs_2026_02_target_id_source_completed_at_idx on scraper_runs_2026_02 scraper_runs_9  (cost=0.43..8.46 rows=1 width=16) (actual time=0.650..0.650 rows=0 loops=1)
              Index Cond: ((target_id = '16f75bbf-9aba-4beb-a256-10f62ede7137'::uuid) AND (source = 'insee'::text) AND (completed_at > (now() - ('90 days'::cstring)::interval)))
              Buffers: shared read=3
        ->  Index Scan using scraper_runs_2026_03_target_id_source_completed_at_idx on scraper_runs_2026_03 scraper_runs_10  (cost=0.43..8.46 rows=1 width=16) (actual time=0.064..0.064 rows=0 loops=1)
              Index Cond: ((target_id = '16f75bbf-9aba-4beb-a256-10f62ede7137'::uuid) AND (source = 'insee'::text) AND (completed_at > (now() - ('90 days'::cstring)::interval)))
              Buffers: shared read=3
        ->  Index Scan using scraper_runs_2026_04_target_id_source_completed_at_idx on scraper_runs_2026_04 scraper_runs_11  (cost=0.43..8.46 rows=1 width=16) (actual time=1.192..1.192 rows=0 loops=1)
              Index Cond: ((target_id = '16f75bbf-9aba-4beb-a256-10f62ede7137'::uuid) AND (source = 'insee'::text) AND (completed_at > (now() - ('90 days'::cstring)::interval)))
              Buffers: shared read=3
        ->  Index Scan using scraper_runs_2026_05_target_id_source_completed_at_idx on scraper_runs_2026_05 scraper_runs_12  (cost=0.43..8.45 rows=1 width=16) (actual time=0.561..0.561 rows=0 loops=1)
              Index Cond: ((target_id = '16f75bbf-9aba-4beb-a256-10f62ede7137'::uuid) AND (source = 'insee'::text) AND (completed_at > (now() - ('90 days'::cstring)::interval)))
              Buffers: shared read=3
Planning:
  Buffers: shared hit=1534 read=29
Planning Time: 30.089 ms
Execution Time: 5.458 ms
```

## Verdict global

| Critère | Statut |
|---------|--------|
| p95 < 50 ms | 🟢 GO |
| Index utilisé | 🟢 OK |

**Verdict : 🟢 GO — hypothèse spec validée**



---

**POC produit par Axion CRM Pro — POC #5 — 2026-05-16T19:13:42**
