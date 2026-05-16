# 16 — MONITORING + OBSERVABILITÉ

## Vue d'ensemble

Stack 100 % auto-hébergée (zéro coût SaaS récurrent) déployée sur `obs-01` (CCX23) :

- **Prometheus 2.55+** — scraping de métriques (15s scrape interval)
- **Grafana 11+** — visualisation, 10 dashboards
- **Loki 3+** — logs structurés JSON avec labels
- **Tempo 2+** — traces OpenTelemetry (préparation Phase 2)
- **GlitchTip 4+** — error tracking (alternative OSS Sentry)
- **Uptime Kuma 1.23+** — synthetic monitors externes (status pages)
- **Alertmanager** — routage alertes vers Slack / Telegram / email

Tous les services backend + workers exposent un endpoint `/metrics` Prometheus + envoient leurs logs structurés JSON (Monolog côté PHP, Pino côté Node) avec labels.

---

## 1. Métriques Prometheus (40+)

### Métriques d'application Laravel

| Métrique | Type | Labels | Description |
|---|---|---|---|
| `axion_http_requests_total` | counter | `method`, `path`, `status` | Total requêtes HTTP servies |
| `axion_http_request_duration_seconds` | histogram | `method`, `path` | Durée requêtes p50/p95/p99 |
| `axion_db_query_duration_seconds` | histogram | `query_type` | Durée requêtes DB |
| `axion_db_connections_active` | gauge | — | Connexions DB actives |
| `axion_redis_commands_total` | counter | `command` | Commandes Redis exécutées |

### Métriques de scraping

| Métrique | Type | Labels | Description |
|---|---|---|---|
| `axion_scraper_runs_total` | counter | `source_key`, `status` (ok/error/banned/rate_limited) | Runs scrapers |
| `axion_scraper_run_duration_seconds` | histogram | `source_key` | Durée par run |
| `axion_scraper_companies_found_total` | counter | `source_key` | Entreprises trouvées |
| `axion_scraper_contacts_found_total` | counter | `source_key` | Contacts trouvés |
| `axion_scraper_emails_found_total` | counter | `source_key`, `email_type` | Emails trouvés classifiés |
| `axion_scraper_circuit_broken_total` | counter | `source_key` | Circuit breaker triggered |

### Métriques LLM Router

| Métrique | Type | Labels | Description |
|---|---|---|---|
| `axion_llm_calls_total` | counter | `use_case`, `provider`, `status` | Total appels LLM |
| `axion_llm_call_duration_seconds` | histogram | `use_case`, `provider`, `model` | Latence appels LLM |
| `axion_llm_tokens_total` | counter | `use_case`, `provider`, `model`, `direction` (in/out) | Tokens consommés |
| `axion_llm_cost_eur_total` | counter | `use_case`, `provider` | Coût cumulé EUR |
| `axion_llm_fallback_used_total` | counter | `use_case` | Fallback déclenché |

### Métriques proxies

| Métrique | Type | Labels | Description |
|---|---|---|---|
| `axion_proxy_requests_total` | counter | `provider`, `target_domain`, `status` | Requêtes par provider |
| `axion_proxy_request_duration_seconds` | histogram | `provider`, `target_domain` | Latence |
| `axion_proxy_active_count` | gauge | `provider`, `status` | Proxies actifs/cooldown/disabled |
| `axion_proxy_success_rate_24h` | gauge | `provider` | Success rate 24h % |
| `axion_proxy_monthly_cost_eur` | gauge | `provider` | Coût mensuel actuel |

### Métriques email validation

| Métrique | Type | Labels | Description |
|---|---|---|---|
| `axion_email_validations_total` | counter | `status` (valid/invalid/catchall/unknown/greylist) | Total validations |
| `axion_email_validation_duration_seconds` | histogram | `method` (syntax/mx/smtp/catchall) | Durée par méthode |
| `axion_email_pattern_detected_total` | counter | `confidence_bucket` (high/mid/low) | Patterns détectés |

### Métriques queues Horizon

| Métrique | Type | Labels | Description |
|---|---|---|---|
| `axion_queue_depth` | gauge | `queue` | Jobs en attente |
| `axion_queue_jobs_processed_total` | counter | `queue`, `status` | Jobs processed |
| `axion_queue_job_duration_seconds` | histogram | `queue`, `job_class` | Durée jobs |
| `axion_queue_failed_jobs_total` | counter | `queue`, `job_class` | Échecs jobs |
| `axion_queue_workers_active` | gauge | `queue` | Workers actifs |

### Métriques business

| Métrique | Type | Labels | Description |
|---|---|---|---|
| `axion_companies_enriched_total` | counter | `tier`, `axion_offer`, `region` | Entreprises enrichies cumulé |
| `axion_companies_priority_distribution` | gauge | `priority_score` | Distribution priorités |
| `axion_signals_detected_total` | counter | `signal_type`, `severity` | Signaux business détectés |
| `axion_coverage_pct` | gauge | `region` | % couverture par région |

### Métriques GDPR / sécurité

| Métrique | Type | Labels | Description |
|---|---|---|---|
| `axion_gdpr_requests_total` | counter | `request_type`, `status` | Requêtes RGPD |
| `axion_gdpr_processing_seconds` | histogram | `request_type` | Durée traitement RGPD |
| `axion_audit_logs_total` | counter | `action` | Total audit logs |
| `axion_auth_failed_logins_total` | counter | `reason` | Échecs login |
| `axion_auth_2fa_failures_total` | counter | — | Échecs 2FA |

---

## 2. Les 10 dashboards Grafana

### Dashboard 1 — Vue exécutive (KPIs business)

Panneaux :
- Big numbers : entreprises enrichies 24h / 7j / 30j / total
- Big numbers : coûts LLM mois / proxies mois / total Hetzner mois
- Big numbers : signaux business critiques 7j
- Graphe : throughput enrichissement 30 jours (line)
- Pie chart : distribution priorités Axion-IA
- Bar chart : coverage par région
- Table : top 10 zones recommandées à attaquer

### Dashboard 2 — Throughput global

- Graphe : `rate(axion_scraper_runs_total[5m])` par source
- Graphe : `rate(axion_companies_enriched_total[1h])` cumulatif
- Histogramme : durée runs par source p50/p95/p99
- Table : runs en cours par source

### Dashboard 3 — Coverage par géo (heatmap)

- Heatmap : régions × NAF sections avec coverage_pct
- Carte choropleth via Geomap plugin Grafana (data layer GeoJSON)
- Bar chart : top 20 départements par % coverage
- Table : zones jamais scrapées + total entreprises potentielles

### Dashboard 4 — Performance par campagne (placeholder Phase 2)

- KPI cards : sent / opens / clicks / replies / bounces / unsubscribes
- Funnel chart par campaign_id
- Affichage "Module en développement Phase 2" en V1 mais structure prête

### Dashboard 5 — Santé des rotations

- 5 panels (un par dimension de rotation) :
  - Proxies : success rate par provider, cooldowns actifs, IPs actives
  - User-agents : distribution effective dernières 24h
  - Cibles géo : zones cooldown, queue depth, prochaines cibles
  - LinkedIn : daily_used par compte, status, cooldowns
  - LLMs : fallback rate par use_case

### Dashboard 6 — Coût LLM détaillé

- Big number : coût total mois courant
- Big number : coût moyen par enrichissement (EUR)
- Time series : `rate(axion_llm_cost_eur_total[1d])` par use_case
- Stacked bar : coût par provider × jour
- Table : top 10 use_cases les plus coûteux
- Alerting : si dépassement 80% / 100% du budget mensuel

### Dashboard 7 — Email finder performance

- Bar chart : distribution validations (valid/invalid/catchall/unknown)
- Time series : `rate(axion_email_validations_total[5m])` par status
- Histogram : durée cascade SMTP p50/p95/p99
- Table : top 10 domaines avec catch-all
- Big number : taux faux positifs (validés qui bouncent in Phase 2)

### Dashboard 8 — Scraper runs

- Big numbers : runs total / ok / error / banned dans 24h
- Time series : runs par source × status
- Table : top 20 erreurs (group by error_message stripped)
- Heatmap : erreurs par source × heure du jour
- Big number : circuit_broken_total dernière 1h (alerting)

### Dashboard 9 — Infrastructure (CPU / RAM / disk / network)

Métriques `node_exporter` par serveur :
- CPU usage par serveur (gauges)
- RAM usage par serveur
- Disk usage par serveur
- Network in/out par serveur
- Postgres : connections active, slow queries, replication lag (si replica)
- Redis : memory usage, ops/sec, evicted keys

### Dashboard 10 — Audit & sécurité

- Big number : audit_logs créés 24h
- Time series : `rate(axion_auth_failed_logins_total[5m])` par reason
- Time series : `rate(axion_auth_2fa_failures_total[5m])`
- Big number : GDPR requests pending (countdown deadline 30j)
- Table : top 10 actions audit-loggées 24h
- Status : intégrité hash chain (OK / BROKEN)

---

## 3. Alertmanager rules YAML

```yaml
groups:
  - name: axion-crm-critical
    interval: 30s
    rules:
      # DB down
      - alert: PostgresDown
        expr: up{job="postgres"} == 0
        for: 1m
        labels: { severity: critical }
        annotations:
          summary: "PostgreSQL Axion CRM Pro est DOWN"
          runbook: "https://docs.axion-crm-pro/runbooks/postgres-down"

      # Redis down
      - alert: RedisDown
        expr: up{job="redis"} == 0
        for: 1m
        labels: { severity: critical }

      # Queue depth
      - alert: QueueDepthCritical
        expr: sum(axion_queue_depth) > 10000
        for: 5m
        labels: { severity: critical }
        annotations:
          summary: "Queue depth global > 10k jobs"

      # Error rate
      - alert: ErrorRateHigh
        expr: |
          sum(rate(axion_http_requests_total{status=~"5.."}[5m]))
          / sum(rate(axion_http_requests_total[5m])) > 0.05
        for: 10m
        labels: { severity: high }
        annotations:
          summary: "Error rate API > 5% sur 10 min"

      # Coût LLM
      - alert: LlmCostExceedingBudget
        expr: axion_llm_cost_eur_total > 300
        for: 1m
        labels: { severity: high }
        annotations:
          summary: "Coût LLM mensuel > 300 € (budget 250 €)"

      # Scraper banned
      - alert: ScraperBanned
        expr: increase(axion_scraper_runs_total{status="banned"}[1h]) > 3
        for: 1m
        labels: { severity: high }
        annotations:
          summary: "Scraper {{ $labels.source_key }} banné > 3x dernière heure"

      # LinkedIn account suspicious
      - alert: LinkedInAccountSuspicious
        expr: changes(axion_linkedin_accounts_status{status="suspicious"}[5m]) > 0
        labels: { severity: high }
        annotations:
          summary: "Compte LinkedIn suspicious — re-login requis"

      # Anomalie volume scraping (drop 30%)
      - alert: ScrapingVolumeDrop
        expr: |
          (
            sum(rate(axion_companies_enriched_total[1h]))
            / sum(rate(axion_companies_enriched_total[1h] offset 24h))
          ) < 0.7
        for: 30m
        labels: { severity: medium }

      # Hash chain audit broken
      - alert: AuditHashChainBroken
        expr: axion_audit_chain_integrity == 0
        for: 0s
        labels: { severity: critical }
        annotations:
          summary: "Audit log hash chain ROMPUE — investigation forensique"

      # GDPR deadline approaching
      - alert: GdprDeadlineSoon
        expr: axion_gdpr_request_days_to_deadline < 3
        labels: { severity: high }
```

---

## 4. Channels d'alerting

### Slack `#axion-crm-alerts`

Pour : `medium` + `high` (toutes alertes opérationnelles).

```yaml
receivers:
  - name: 'slack'
    slack_configs:
      - api_url: 'https://hooks.slack.com/services/...'   # vault
        channel: '#axion-crm-alerts'
        send_resolved: true
        title: '{{ .CommonAnnotations.summary }}'
```

### Telegram backup

Pour : `critical` (au cas où Slack tombe).

```yaml
  - name: 'telegram'
    telegram_configs:
      - bot_token: '<bot-token>'
        chat_id: <will-chat-id>
        parse_mode: 'Markdown'
```

### Email (Will)

Pour : `critical` (3e canal redondant).

```yaml
  - name: 'email-will'
    email_configs:
      - to: 'contact@axion-ia.com'
        from: 'alerts@crm.axion-ia.com'
        smarthost: 'smtp.mailgun.org:587'
```

### Route Alertmanager

```yaml
route:
  group_by: ['alertname']
  group_wait: 30s
  group_interval: 5m
  repeat_interval: 4h
  receiver: 'slack'
  routes:
    - matchers: [ severity = "critical" ]
      receiver: 'telegram'
      continue: true
    - matchers: [ severity = "critical" ]
      receiver: 'email-will'
      continue: true
```

---

## 5. Anomaly detection custom

Le simple seuillage Prometheus ne capte pas toutes les anomalies (saisonnalité, lentes dérives). Un job nightly `DetectAnomaliesJob` calcule moyenne + écart-type sur 7j glissants et flag les outliers (> 2.5 σ).

```php
final class AnomalyDetector
{
    public function run(): void
    {
        $metrics = [
            'companies_enriched_per_hour',
            'llm_cost_per_hour',
            'scraper_runs_error_rate',
            'email_validations_per_hour',
            'queue_depth_avg',
        ];
        foreach ($metrics as $metric) {
            $history = $this->fetchLast7Days($metric);    // 168 valeurs horaires
            $mean = array_sum($history) / count($history);
            $stddev = $this->stddev($history, $mean);
            $current = $this->fetchCurrent($metric);
            if (abs($current - $mean) > 2.5 * $stddev) {
                $this->createAnomaly([
                    'metric' => $metric,
                    'current' => $current,
                    'mean' => $mean,
                    'stddev' => $stddev,
                    'deviation' => round(abs($current - $mean) / $stddev, 2),
                    'severity' => abs($current - $mean) > 3.5 * $stddev ? 'high' : 'medium',
                ]);
            }
        }
    }
}
```

Insertion dans table `monitoring_anomalies` (à créer dans `03_db_schema_phase1.md` ou en migration séparée). Affichage dans page 17 admin (`/alerts`).

---

## 6. Logs structurés (Loki)

### Côté PHP (Monolog JSON)

```php
// config/logging.php
'loki' => [
    'driver' => 'monolog',
    'handler' => GuzzleHandler::class,
    'formatter' => JsonFormatter::class,
    'formatter_with' => [
        'extra' => ['service' => 'axion-crm-api', 'env' => env('APP_ENV')],
    ],
    'with' => [ 'pushUrl' => 'http://obs-01:3100/loki/api/v1/push' ],
],
```

Labels attachés : `service`, `env`, `level`, `workspace_id`, `user_id`, `request_id` (header X-Request-Id).

### Côté Node.js (Pino)

```ts
import pino from 'pino';
import { createWriteStream } from 'pino-loki';

export const log = pino({
  base: { service: 'axion-crm-workers', env: process.env.NODE_ENV },
  formatters: { level: (label) => ({ level: label }) },
}, createWriteStream({ host: 'http://obs-01:3100', batching: true }));
```

### Recherches utiles dans Grafana Explore + Loki

```logql
# Toutes les erreurs scraping derniers 30 min
{service="axion-crm-workers"} |= "level=error" | json

# Logs d'un user spécifique
{service="axion-crm-api", user_id="42"}

# Logs d'une requête (corrélation via request_id)
{request_id="01HXY3..."}
```

---

## 7. Traces OpenTelemetry (Tempo) — Phase 2

V1 : pas activé. Phase 2 : tracer le waterfall complet pour debug (chaque étape devient un span).

Préparé : `app-01` lance Octane avec env var `OTEL_EXPORTER_OTLP_ENDPOINT=http://obs-01:4317`.

---

## 8. Uptime Kuma — Synthetic monitors

Configuration côté `obs-01` (`http://obs-01:3001`) :

- Monitor 1 : `GET https://crm.axion-ia.com/api/monitoring/health` toutes les 60s, alerte si != 200
- Monitor 2 : `GET https://crm.axion-ia.com` (login page, vérif HTML contains "Axion CRM Pro")
- Monitor 3 : INSEE Sirene API ping (externe — détecte source down)
- Monitor 4 : annuaire-entreprises ping
- Monitor 5 : Google Maps reachability test (depuis IP statique non proxy)
- Monitor 6 : Anthropic, OpenAI, Mistral API status check
- Public status page : `status.axion-ia.com` (sous-domaine optionnel)

---

## 9. Métriques Laravel — exposition Prometheus

Package `arquivei/laravel-prometheus-exporter` ou implémentation custom :

```php
Route::get('/api/monitoring/metrics/prometheus', function () {
    $registry = app(\Prometheus\CollectorRegistry::class);
    $renderer = new \Prometheus\RenderTextFormat();
    return response($renderer->render($registry->getMetricFamilySamples()))
        ->header('Content-Type', \Prometheus\RenderTextFormat::MIME_TYPE);
})->middleware('basic-auth-prometheus');     // Basic auth user/pass dédié scrape
```

Prometheus config :
```yaml
scrape_configs:
  - job_name: 'axion-crm-api'
    metrics_path: '/api/monitoring/metrics/prometheus'
    basic_auth: { username: 'prometheus', password_file: '/etc/prom-pass' }
    static_configs:
      - targets: ['10.20.0.20:8080','10.20.0.21:8080']    # app-01, app-02
```

---

## 10. Critères de done monitoring (S12)

- [ ] 40+ métriques Prometheus exposées
- [ ] 10 dashboards Grafana provisionnés (via JSON dans `infra/grafana/dashboards/`)
- [ ] Alertmanager configuré + 3 channels (Slack/Telegram/email) testés
- [ ] Loki ingestion fonctionnelle (logs PHP + Node)
- [ ] Anomaly detector job tourne nightly sans erreur
- [ ] Uptime Kuma : 6 monitors actifs
- [ ] Dashboard "Vue exécutive" lisible par Will en 30 secondes (sans formation)
- [ ] Test alerting : `kill -9 $(pgrep postgres)` → alerte critical reçue Slack+Telegram+email en < 2 min

---

## 11. Anti-patterns interdits

- ❌ Logs en plain text (forcer JSON)
- ❌ Métriques avec labels haute cardinalité (`user_id`, `siren`) → cardinality explosion Prometheus
- ❌ Stocker logs > 30 jours dans Loki (rotation auto)
- ❌ Alertes sans `for` (= flapping)
- ❌ Dashboards non versionnés (JSON Grafana doit être dans Git)
- ❌ Désactiver les alertes "trop bruyantes" — d'abord investiguer

---

## Prochaine étape

→ Lire `17_rgpd_aiact_owasp.md` pour la conformité (RGPD + AI Act + OWASP top 10).
