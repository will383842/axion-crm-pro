# Load Test Runbook — Axion CRM Pro

Sprint Pipeline 360° Hardening (H5) — 2026-05-17

## Objectif

Vérifier que l'API tient la charge à 1M companies / mois cible (~33K / jour, ~1400 / h).
Pas seulement le throughput brut : valider les SLA p95/p99 sur les 3 endpoints
les plus appelés depuis le frontend (list companies, audience preview, tags list).

## Outil

[Artillery](https://www.artillery.io/) — déjà standard pour load testing API REST.

```bash
npm install -g artillery
# ou ponctuel sans install
npx artillery run load-tests/audience-refresh.yml
```

## Baselines attendues (cible PRODUCTION CPX42)

| Scenario | p50 | p95 | p99 | Note |
|---|---|---|---|---|
| List companies dept+size (100 results) | ≤ 200ms | ≤ 800ms | ≤ 2000ms | Index `(workspace_id, department_code, size_category)` essentiel |
| Preview audience criteria DSL | ≤ 100ms | ≤ 300ms | ≤ 700ms | COUNT(*) via DSL — coûts variables selon criteria |
| List tags grouped | ≤ 50ms | ≤ 150ms | ≤ 400ms | Petite table, full scan acceptable |
| **Error rate global** | < 0.5% | < 1% | — | Tolérance courte (Redis pump-up, FCM RST) |

Phases du scenario YAML :
- **Warmup** : 60s à 5 req/s (constitue cache PHP-FPM + Postgres planner)
- **Sustained** : 300s à 20 req/s = 6000 req → ~80% du throughput théorique CPX42

## Workflow recommandé

### 1. Préparation (1×, à l'init)

```bash
# Récupérer un Bearer token via login (workspace réel)
curl -X POST https://app.axion-crm-pro.com/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"williamsjullin@gmail.com","password":"...changeme..."}' \
  | jq -r .data.token
```

Exporter en variable :
```bash
export API_TOKEN='<token-récupéré>'
export LOAD_TEST_TARGET='https://app.axion-crm-pro.com'
```

### 2. Run (à chaque sprint avant merge)

```bash
npx artillery run load-tests/audience-refresh.yml
```

Output Artillery affichera p50/p95/p99 + error rate.

### 3. Analyse

Si **p95 > 800ms** sur list companies → vérifier index PG :
```sql
SELECT * FROM pg_indexes WHERE tablename = 'companies' ORDER BY indexname;
EXPLAIN ANALYZE
  SELECT * FROM companies
  WHERE workspace_id = '...' AND department_code = '75' AND size_category = 'pme'
  LIMIT 100;
```

Si **error rate > 1%** → check `tail -f storage/logs/laravel.log` + Sentry pendant le run.

## Antipattern à éviter

❌ Ne PAS lancer Artillery en prod pendant les heures business (9-18h).
   → Plutôt 22h-6h ou en CI sur env staging dédié.

❌ Ne PAS scaler au-delà de 50 req/s sans en parler à Will :
   - CPX42 = 8 vCPU partagés (steal possible chez Hetzner)
   - Une charge soutenue à 50 req/s = saturation FPM par défaut (10 workers)

✅ Pour test "burst" courts (10s à 100 req/s) : duplicate ce yaml en `audience-burst.yml`
   avec phase `maxVusers: 100, duration: 10` — à valider avec Will avant run.

## Quoi enregistrer après chaque run

Dans `load-tests/results/` (à créer si absent, gitignored sauf le runbook) :
- output Artillery brut (`artillery report` HTML)
- date + commit SHA + scénario
- p95 / p99 / error rate observés
- baseline KO / OK

Cette doc + le yml sont les seuls fichiers commités — les résultats sont locaux.
