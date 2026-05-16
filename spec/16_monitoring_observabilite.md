# 16 — Monitoring & observabilité

> **Stack 100 % auto-hébergée :** Prometheus + Grafana + Loki + Tempo + Alertmanager + GlitchTip + Uptime Kuma.
> **Coût additionnel :** 0 € (CPX21 ~10 €/mois inclus dans infra).
> **Cibles :** 40+ métriques métier + 10 dashboards Grafana + alertes critiques Slack/Telegram.

---

## §1 — Stack monitoring

| Composant | Rôle | Port |
|-----------|------|------|
| Prometheus 2.55 | Métriques pull | 9090 |
| Grafana 11 | Dashboards | 3000 |
| Loki 3 | Logs centralisés (LogQL) | 3100 |
| Promtail | Log shipper Docker → Loki | — |
| Tempo 2.6 | Distributed tracing (OTel) | 3200 |
| Alertmanager 0.27 | Routing alertes | 9093 |
| GlitchTip 4 | Error tracking (Sentry-compatible) | 8000 |
| Uptime Kuma 1.23 | Probes externes | 3001 |

Tous déployés sur `Observability — CPX21 (10.0.0.50)`.

---

## §2 — 40+ métriques Prometheus (catégorisées)

### Catégorie A — HTTP API

```
axion_crm_http_requests_total{method,endpoint,status}
axion_crm_http_request_duration_ms_histogram{method,endpoint}
axion_crm_http_active_connections
axion_crm_http_rate_limit_hits_total{endpoint,user_or_ip}
```

### Catégorie B — Auth

```
axion_crm_auth_login_attempts_total{result="success|failed|locked"}
axion_crm_auth_two_factor_attempts_total{result}
axion_crm_auth_magic_link_requests_total
axion_crm_auth_active_sessions_gauge
```

### Catégorie C — Scraping

```
axion_crm_scraper_runs_total{source,status}
axion_crm_scraper_duration_ms_histogram{source}
axion_crm_scraper_contacts_found_total{source}
axion_crm_scraper_emails_found_total{source}
axion_crm_scraper_emails_validated_total{source,status}
axion_crm_scraper_skip_total{source,reason="already_fresh|opt_out|quota"}
axion_crm_scraper_retry_total{source}
axion_crm_scraper_error_total{source,error_code}
```

### Catégorie D — LLM

Cf. `07_llm_router.md` § 11.

### Catégorie E — Proxies

Cf. `09_proxy_pluggable_system.md` § 7.

### Catégorie F — Rotations

Cf. `10_rotations_universelles.md` § 9.

### Catégorie G — Email validation

Cf. `06_email_finder_validation.md` § 9.

### Catégorie H — Enrichment waterfall

```
axion_crm_enrichment_runs_total{status}
axion_crm_enrichment_duration_ms_histogram{size_category}
axion_crm_enrichment_cost_eur_histogram
axion_crm_enrichment_quality_transition_total{from,to}
axion_crm_enrichment_waterfall_step_duration_ms{step}
axion_crm_enrichment_waterfall_step_failures_total{step,error}
```

### Catégorie I — Business KPIs

```
axion_crm_companies_total_gauge{workspace,size,quality}
axion_crm_contacts_total_gauge{workspace,seniority,discovery_source}
axion_crm_companies_with_valid_email_gauge{workspace,size}
axion_crm_companies_with_linkedin_url_gauge{workspace,size}
axion_crm_companies_quality_distribution{workspace,quality_score}
axion_crm_coverage_percent_gauge{workspace,department_code}
axion_crm_signals_detected_total{workspace,signal_type}
```

### Catégorie J — Système

```
axion_crm_db_connections_gauge
axion_crm_db_query_duration_ms_histogram
axion_crm_redis_memory_used_bytes
axion_crm_redis_queue_length_gauge{queue}
axion_crm_horizon_jobs_processed_total{queue,status}
axion_crm_horizon_jobs_failed_total{queue}
```

**Total ~ 48 métriques distinctes** (le prompt v6 mentionnait 40+).

---

## §3 — Instrumentation Laravel

```php
// app/Providers/MetricsServiceProvider.php
use Prometheus\CollectorRegistry;
use Prometheus\Storage\Redis as RedisStorage;

class MetricsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CollectorRegistry::class, function () {
            return new CollectorRegistry(new RedisStorage(['host' => '10.0.0.30', 'database' => 6]));
        });
    }
}

// Usage dans services
class ScrapingMetrics
{
    public function __construct(private CollectorRegistry $registry) {}

    public function recordRun(string $source, string $status, int $durationMs): void
    {
        $this->registry->getOrRegisterCounter(
            'axion_crm', 'scraper_runs_total', '', ['source','status']
        )->inc([$source, $status]);

        $this->registry->getOrRegisterHistogram(
            'axion_crm', 'scraper_duration_ms', '', ['source'],
            [100, 500, 1000, 5000, 10000, 30000, 60000, 120000]
        )->observe($durationMs, [$source]);
    }
}

// Endpoint d'exposition
Route::get('/metrics', function (CollectorRegistry $reg) {
    $renderer = new \Prometheus\RenderTextFormat();
    return response($renderer->render($reg->getMetricFamilySamples()), 200, [
        'Content-Type' => \Prometheus\RenderTextFormat::MIME_TYPE,
    ]);
})->middleware('internal_only');
```

---

## §4 — 10 dashboards Grafana

### Dashboard 1 — Vue d'ensemble (overview)

Panels :
- KPI : entreprises totales, contacts totaux, fiches 🟢/🟡/🔴 (stat panel)
- Throughput scraping last 24h (timeseries)
- Coût LLM 30 jours (timeseries)
- Top 10 erreurs scraping last 1h (table)
- Coverage map (heatmap par dept, via geomap plugin)
- Uptime (stat from Uptime Kuma)

### Dashboard 2 — Scraping détail

Panels :
- Runs par source 24h (bar)
- Success rate par source 7j (timeseries)
- Latence p50/p95/p99 par source (timeseries)
- Skip raisons (pie : opt_out / already_fresh / quota)
- Heatmap d'erreurs source × heure
- Captcha events Google Search Wrapper

### Dashboard 3 — LLM Router

Panels :
- Coût €/jour par provider (timeseries, stacked)
- Tokens IN/OUT par use case (timeseries)
- Cache hit ratio par use case (gauge)
- Fallback events 7j (table)
- p95 latence par provider (timeseries)
- Budget gauge par workspace (current month)

### Dashboard 4 — Proxies & rotations

Panels :
- IPs actives vs cooldown vs disabled (stat)
- Bandwidth GB/jour par provider (timeseries)
- Success rate par proxy (top 20) (table)
- Coût €/mois par provider (gauge)
- Search engines state timeline (state-timeline)
- User-Agent usage distribution (bar)

### Dashboard 5 — Email validation

Panels :
- Validations par status 24h (pie)
- Score distribution (histogram)
- Catch-all domains detected (table)
- SMTP errors par error_code (timeseries)
- Cache hit ratio (gauge)
- IP réputation validator (stat from blacklist check)

### Dashboard 6 — Direction Finder (ETI/Grandes)

Panels :
- Runs par status 7j (timeseries)
- C-level trouvés moyenne par ETI (stat)
- Sources successful distribution (pie : corporate_pages, press, annual_report, google_search)
- Coût moyen par run (gauge)
- Top pages cibles trouvées (table)

### Dashboard 7 — Business KPIs

Panels :
- Évolution fiches 🟢 / 7j / 30j / total (timeseries)
- Distribution par taille (TPE/PME/ETI/Grandes) (pie)
- Distribution par priorité Axion-IA (bar)
- Top 10 départements scrappés (table)
- Top 10 NAF (table)
- Conversion funnel discovered → enriched → qualified

### Dashboard 8 — RGPD / Compliance

Panels :
- Demandes RGPD ouvertes par type + échéance (table)
- Délai moyen traitement (gauge)
- Audit log entries 7j (timeseries)
- Hash chain status (stat)
- Opt-out adds 7j (timeseries)

### Dashboard 9 — Infrastructure

Panels :
- CPU/RAM/disk par serveur (timeseries)
- DB connections + slow queries (timeseries)
- Redis memory + queue depths (timeseries)
- Network IO par serveur (timeseries)
- Disk space remaining (stat)

### Dashboard 10 — Anomalies & alertes

Panels :
- Anomalies actives (table)
- Alertes firing dernières 24h (timeseries)
- MTTR alerts (gauge)
- Top 5 alert names (table)

---

## §5 — Alertmanager rules (YAML)

```yaml
# monitoring/alertmanager/rules.yml
groups:
  - name: scraping_critical
    interval: 1m
    rules:
      - alert: ScrapingSourceErrorSpike
        expr: |
          (sum by (source) (rate(axion_crm_scraper_runs_total{status="failed"}[5m]))
          / sum by (source) (rate(axion_crm_scraper_runs_total[5m]))) > 0.15
        for: 5m
        labels: { severity: critical }
        annotations:
          summary: "Source {{ $labels.source }} error rate > 15% over 5min"
          description: "Investigate scraper logs at https://grafana.axion-pro.com/d/scraping"

      - alert: ProxyProviderDegraded
        expr: |
          (sum by (provider) (rate(axion_crm_proxy_failures_total[10m]))
          / sum by (provider) (rate(axion_crm_proxy_acquire_total[10m]))) > 0.30
        for: 10m
        labels: { severity: warning }
        annotations:
          summary: "Proxy provider {{ $labels.provider }} success rate < 70%"

      - alert: AllSearchEnginesBlocked
        expr: count(axion_crm_rotation_active_entities{dimension="search_engine"}) == 0
        for: 2m
        labels: { severity: critical }
        annotations:
          summary: "All search engines blocked — Google Search Wrapper down"

  - name: llm_critical
    rules:
      - alert: LLMCostSpike
        expr: |
          increase(axion_crm_llm_cost_eur_total[1h])
          > 2 * (avg_over_time(axion_crm_llm_cost_eur_total[7d:1h]))
        for: 30m
        labels: { severity: warning }
        annotations:
          summary: "LLM cost > 2x baseline 7j"

      - alert: LLMProviderDown
        expr: |
          (sum by (provider) (rate(axion_crm_llm_calls_total{status!="ok"}[5m]))
          / sum by (provider) (rate(axion_crm_llm_calls_total[5m]))) > 0.50
        for: 5m
        labels: { severity: critical }
        annotations:
          summary: "LLM provider {{ $labels.provider }} > 50% failures"

      - alert: LLMWorkspaceBudgetReached
        expr: axion_crm_llm_cost_eur_total >= axion_crm_workspace_cost_cap_eur
        for: 0m
        labels: { severity: critical }
        annotations:
          summary: "Workspace {{ $labels.workspace }} hit monthly LLM budget cap"

  - name: business_critical
    rules:
      - alert: EnrichmentQualityDropping
        expr: |
          (sum(rate(axion_crm_enrichment_quality_transition_total{to="complete"}[1h]))
          / sum(rate(axion_crm_enrichment_runs_total{status="success"}[1h]))) < 0.30
        for: 2h
        labels: { severity: warning }
        annotations:
          summary: "Fiches 🟢 production rate < 30% over 2h"

  - name: system
    rules:
      - alert: DiskSpaceLow
        expr: node_filesystem_avail_bytes{mountpoint="/"} / node_filesystem_size_bytes{mountpoint="/"} < 0.10
        for: 10m
        labels: { severity: warning }
      - alert: PostgresHighConnections
        expr: pg_stat_database_numbackends > 150
        for: 5m
        labels: { severity: warning }
      - alert: RedisQueueBacklog
        expr: axion_crm_redis_queue_length_gauge > 10000
        for: 15m
        labels: { severity: warning }
```

---

## §6 — Routing alertes

```yaml
# monitoring/alertmanager/config.yml
route:
  receiver: default
  group_by: ['alertname','severity']
  group_wait: 30s
  group_interval: 5m
  repeat_interval: 4h
  routes:
    - matchers: ['severity = "critical"']
      receiver: slack-critical
      continue: true
    - matchers: ['severity = "critical"']
      receiver: telegram-critical

receivers:
  - name: default
    slack_configs:
      - api_url: ${SLACK_WEBHOOK_DEFAULT}
        channel: '#axion-crm-pro-alerts'

  - name: slack-critical
    slack_configs:
      - api_url: ${SLACK_WEBHOOK_CRITICAL}
        channel: '#axion-crm-pro-prod'
        title: '🚨 {{ .GroupLabels.alertname }}'
        text: '{{ range .Alerts }}{{ .Annotations.summary }}\n{{ end }}'

  - name: telegram-critical
    telegram_configs:
      - bot_token: ${TELEGRAM_BOT_TOKEN}
        chat_id: ${TELEGRAM_CHAT_ID}
        parse_mode: HTML
```

---

## §7 — Anomaly detection statistique

Job `app:detect-anomalies` exécuté toutes les 15 min :

```php
class AnomalyDetector
{
    public function run(): void
    {
        $this->detectScraperErrorSpike();
        $this->detectProxyDegradation();
        $this->detectLLMCostAnomaly();
        $this->detectEnrichmentQualityDrop();
    }

    private function detectLLMCostAnomaly(): void
    {
        $hourCost = LlmUsage::where('used_at', '>=', now()->subHour())->sum('cost_eur');
        $baseline = LlmUsage::where('used_at', '>=', now()->subDays(7))
            ->where('used_at', '<', now()->subDay())
            ->selectRaw('AVG(c) AS m, STDDEV(c) AS s FROM (SELECT SUM(cost_eur) AS c FROM llm_usage WHERE used_at >= now() - INTERVAL \'7 days\' GROUP BY date_trunc(\'hour\', used_at)) t')
            ->first();
        $zscore = ($hourCost - $baseline->m) / max(0.01, $baseline->s);
        if ($zscore > 3) {
            Anomaly::create([
                'workspace_id' => null,
                'kind' => 'llm_cost_spike',
                'severity' => 'warning',
                'message' => "LLM cost {$hourCost}€ vs baseline {$baseline->m}€ (z={$zscore})",
                'detected_at' => now(),
            ]);
            event(new AnomalyDetected('llm_cost_spike'));
        }
    }
}
```

---

## §8 — Logs structurés (Monolog JSON)

```php
// config/logging.php
'channels' => [
    'stack' => ['driver' => 'stack', 'channels' => ['stdout']],
    'stdout' => [
        'driver' => 'monolog',
        'handler' => Monolog\Handler\StreamHandler::class,
        'with' => ['stream' => 'php://stdout'],
        'formatter' => Monolog\Formatter\JsonFormatter::class,
        'processors' => [
            App\Logging\WorkspaceContextProcessor::class,
            App\Logging\RequestIdProcessor::class,
        ],
    ],
],
```

### Workspace context

```php
class WorkspaceContextProcessor
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;
        $extra['workspace_id'] = config('app.current_workspace_id');
        $extra['user_id'] = auth()->id();
        $extra['request_id'] = request()->header('X-Request-Id') ?? Str::uuid()->toString();
        return $record->with(extra: $extra);
    }
}
```

### Schéma de log JSON

```json
{
  "message": "Scraper completed",
  "level": "INFO",
  "context": { "source": "google_maps", "duration_ms": 4321 },
  "extra": { "workspace_id": "...", "user_id": "...", "request_id": "..." },
  "datetime": "2026-05-16T13:45:00.123+00:00",
  "channel": "production"
}
```

---

## §9 — Loki (logs centralisés)

### Sources

- Logs Docker stdout via Promtail (containers `laravel`, `horizon`, `worker-*`, etc.)
- Logs Nginx (Caddy) Edge
- Logs Postgres slow queries

### Rétention

90 jours (configurable). LogQL queries pour debug :

```logql
{container="laravel"} |= "ERROR" | json | line_format "{{.message}}"
{container="worker-google-search"} | json | duration_ms > 30000
```

---

## §10 — Tempo + OpenTelemetry (tracing distribué — P1 audit v1.1 durci)

> **P1 audit v1.1** : OpenTelemetry SDK PHP + JS + Node intégrés **dès S1**, pas en bout de Sprint 12. Sans OTel dès jour 1, debug en prod = devine.

Sampling adaptatif : 100 % pour erreurs, 10 % requêtes normales, 1 % requêtes haute fréquence (`/up`, métriques).

### Setup OpenTelemetry PHP (Laravel)

```bash
composer require open-telemetry/sdk open-telemetry/opentelemetry-auto-laravel \
                 open-telemetry/exporter-otlp open-telemetry/transport-grpc
```

```php
// bootstrap/otel.php (chargé en début de bootstrap)
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\Contrib\Grpc\GrpcTransportFactory;

$transport = (new GrpcTransportFactory())->create('http://10.0.0.50:4317');
$exporter = new SpanExporter($transport);
$tracerProvider = TracerProvider::builder()
    ->addSpanProcessor(new BatchSpanProcessor($exporter, sampler: $adaptiveSampler))
    ->setResource(\OpenTelemetry\SDK\Resource\ResourceInfoFactory::defaultResource()
        ->merge(\OpenTelemetry\SDK\Resource\ResourceInfo::create(\OpenTelemetry\SDK\Common\Attribute\Attributes::create([
            'service.name' => 'axion-crm-pro-laravel',
            'service.version' => config('app.version'),
            'deployment.environment' => config('app.env'),
        ])))
    )->build();

\OpenTelemetry\SDK\Trace\TracerProviderFactory::getInstance()->register($tracerProvider);
```

Auto-instrumentation : Laravel HTTP, DB Eloquent, Redis, Guzzle (HTTP client) via `open-telemetry/opentelemetry-auto-laravel`.

### Setup OpenTelemetry Node (workers)

```typescript
// workers/src/otel.ts (importé en tout début de main.ts)
import { NodeSDK } from '@opentelemetry/sdk-node'
import { OTLPTraceExporter } from '@opentelemetry/exporter-trace-otlp-grpc'
import { getNodeAutoInstrumentations } from '@opentelemetry/auto-instrumentations-node'
import { Resource } from '@opentelemetry/resources'
import { SemanticResourceAttributes } from '@opentelemetry/semantic-conventions'

const sdk = new NodeSDK({
  resource: new Resource({
    [SemanticResourceAttributes.SERVICE_NAME]: `axion-worker-${process.env.WORKER_TYPE}`,
    [SemanticResourceAttributes.SERVICE_VERSION]: process.env.APP_VERSION ?? '0.0.0',
  }),
  traceExporter: new OTLPTraceExporter({ url: 'http://10.0.0.50:4317' }),
  instrumentations: [getNodeAutoInstrumentations({
    '@opentelemetry/instrumentation-fs': { enabled: false },     // bruyant
  })],
})
sdk.start()
process.on('SIGTERM', () => sdk.shutdown())
```

### Setup OpenTelemetry React (browser)

```typescript
// frontend/src/otel.ts
import { WebTracerProvider } from '@opentelemetry/sdk-trace-web'
import { OTLPTraceExporter } from '@opentelemetry/exporter-trace-otlp-http'
import { registerInstrumentations } from '@opentelemetry/instrumentation'
import { FetchInstrumentation } from '@opentelemetry/instrumentation-fetch'
import { DocumentLoadInstrumentation } from '@opentelemetry/instrumentation-document-load'

const provider = new WebTracerProvider()
provider.addSpanProcessor(new BatchSpanProcessor(new OTLPTraceExporter({
  url: 'https://api.axion-pro.com/otel/traces',
})))
provider.register()
registerInstrumentations({ instrumentations: [
  new FetchInstrumentation({ propagateTraceHeaderCorsUrls: [/api\.axion-pro\.com/] }),
  new DocumentLoadInstrumentation(),
]})
```

### Span types métier custom

- `http.server.request` (auto)
- `db.query` (auto)
- `cache.redis` (auto)
- `llm.call` (custom — attributs : `llm.use_case`, `llm.provider`, `llm.model`, `llm.tokens_input`, `llm.tokens_output`, `llm.cost_eur`)
- `scraper.run` (custom — attributs : `scraper.source`, `scraper.target_id`, `scraper.proxy_id`, `scraper.user_agent_hash`)
- `playwright.navigation` (custom — attributs : `playwright.url`, `playwright.duration_ms`, `playwright.captcha_detected`)
- `enrichment.waterfall.step` (custom — attributs : `step.name`, `step.status`)

### Drill-down debug

UI Grafana → click trace ID dans une slow query log → Tempo affiche le span tree complet : API call → DB → Redis → scraper dispatch → Playwright nav → LLM call. Permet de debug une requête lente en 2 min vs 2h sans tracing.

---

## §10bis — Langfuse (evals LLM — P1 audit v1.1)

> **P1 audit v1.1** : sans evals automatiques, on ne peut pas détecter quand un prompt template régresse après modification. Langfuse self-hosted gratuit, déjà l'observabilité standard 2026 pour les apps IA.

### Stack additionnelle observability server

```yaml
# docker-compose.observability.yml addition
services:
  langfuse:
    image: langfuse/langfuse:2
    restart: unless-stopped
    environment:
      DATABASE_URL: postgresql://langfuse:${LANGFUSE_DB_PASSWORD}@postgres:5432/langfuse
      NEXTAUTH_URL: https://langfuse.axion-pro.com
      NEXTAUTH_SECRET: ${LANGFUSE_NEXTAUTH_SECRET}
      SALT: ${LANGFUSE_SALT}
      TELEMETRY_ENABLED: "false"
    ports: ["127.0.0.1:3050:3000"]
    depends_on: [postgres]
```

### Intégration LLMClient

```php
// app/Services/LLM/LLMRouterService.php — addition v1.1
private function logToLangfuse(LLMRequestData $req, LLMResponseData $resp, LlmUseCase $uc): void
{
    if (!config('langfuse.enabled')) return;
    Http::baseUrl(config('langfuse.url'))
        ->withBasicAuth(config('langfuse.public_key'), config('langfuse.secret_key'))
        ->timeout(2)        // best-effort, no block
        ->post('/api/public/ingestion', [
            'batch' => [[
                'id' => Str::uuid(),
                'type' => 'observation-create',
                'timestamp' => now()->toIso8601String(),
                'body' => [
                    'traceId'      => Str::uuid(),
                    'name'         => "llm.{$req->useCaseSlug}",
                    'type'         => 'GENERATION',
                    'input'        => $req->variables,
                    'output'       => $resp->text,
                    'model'        => $resp->modelUsed,
                    'modelParameters' => ['temperature' => $uc->temperature, 'max_tokens' => $uc->max_tokens],
                    'promptName'   => $req->useCaseSlug,
                    'promptVersion'=> $resp->promptTemplateVersion,
                    'usage'        => ['input' => $resp->tokensInput, 'output' => $resp->tokensOutput, 'totalCost' => $resp->costEur],
                    'metadata'     => ['workspace_id' => $req->workspaceId, 'cache_hit' => $resp->cacheHit],
                ],
            ]],
        ]);
}
```

### Workflow evals

1. Dataset de référence créé manuellement (50-100 examples par use case avec output attendu).
2. Job hebdo `app:llm-evals` rejoue dataset sur prompt courant.
3. Comparaison output vs référence via LLM-as-judge (Claude Sonnet 4.6 scoring 0-100).
4. Alerte Slack si moyenne score < 80 (régression détectée).

### Page admin "LLM Evals"

Affiche pour chaque use case :
- Score moyen 7 derniers jours
- Régression vs version précédente prompt
- Bouton "Re-run evals manuellement"

---

## §11 — Métriques business — durci P1 audit v1.1

Ajout obligatoire (cf. AUDIT_v1 § 10.3) :

```
axion_crm_fresh_complete_prospects_gauge{workspace}
  -- entreprises quality_score=complete, jamais contactées (prospection_status='enriched'),
  -- last_enriched_at < 7j
  -- → "combien de prospects fraîchement enrichis dispo pour cold email demain ?"

axion_crm_aged_complete_prospects_gauge{workspace, age_bucket="7-30d|30-90d|90d+"}
  -- complete vieilles, ré-enrichissement potentiellement utile

axion_crm_pipeline_health_score_gauge{workspace}
  -- composite : fresh_complete / target_daily_outreach_volume
  -- > 1.0 = OK, < 0.5 = disette imminente

axion_crm_enrichment_velocity_per_day_gauge{workspace}
  -- entreprises enrichies à quality=complete sur 7 derniers jours / 7
  -- vs cible 7 000/jour

axion_crm_size_category_distribution{workspace, size_category}
  -- répartition artisan/commerçant/tpe/pme/eti/ge en gauge (composantes %)

axion_crm_axion_offer_match_distribution{workspace, offer_code}
  -- combien d'entreprises matchées par offre Axion-IA

axion_crm_signals_velocity_per_day_gauge{workspace, signal_type}
  -- nouveaux signaux business détectés / 7j
```

### Alertes business

```yaml
- alert: ProspectsPipelineDisette
  expr: axion_crm_fresh_complete_prospects_gauge < 100
  for: 24h
  labels: { severity: warning }
  annotations:
    summary: "Moins de 100 prospects 🟢 fraîchement enrichis disponibles depuis 24h"
    runbook: "Vérifier scraper_runs erreurs + lancer enrichissement zone faible coverage"

- alert: EnrichmentVelocityDrop
  expr: axion_crm_enrichment_velocity_per_day_gauge < 3500
  for: 2d
  labels: { severity: warning }
```

---

## §11 — GlitchTip (error tracking)

- Sentry-compatible (mêmes SDKs)
- Captures :
  - Unhandled exceptions Laravel
  - Unhandled rejections Node workers
  - Browser errors React (frontend)
- Issues grouping par fingerprint
- Webhook → Slack pour erreurs nouvelles

---

## §12 — Uptime Kuma (probes externes)

Probes depuis hors-infra (Hetzner Helsinki) :

- `https://crm.axion-pro.com/up` toutes les 60s
- `https://api.axion-pro.com/api/v1/health` toutes les 60s
- Certificat SSL expiry monitoring
- DNS resolution monitoring

Notifications Slack + Telegram si down > 2 min.

---

## §13 — Endpoint `/up` (health check)

```php
Route::get('/up', function () {
    $checks = [
        'db'    => $this->checkDb(),
        'redis' => $this->checkRedis(),
        'queue' => $this->checkQueueDepth(),
    ];
    $ok = collect($checks)->every(fn($v) => $v === true);
    return response()->json([
        'ok' => $ok,
        'checks' => $checks,
        'version' => config('app.version'),
        'time' => now()->toIso8601String(),
    ], $ok ? 200 : 503);
})->middleware('throttle:60,1');
```

---

## §14 — SLOs Phase 1

| Service | SLO | Mesure |
|---------|-----|--------|
| API disponibilité | 99.5 % | Uptime Kuma external probe |
| API latence p95 | < 500 ms | Prometheus histogram |
| Scraper success rate global | > 80 % | sum(status=ok) / sum(all) sur 24h |
| Enrichment quality complete rate | > 30 % | quality_transition_total to=complete / runs_total |
| LLM cost monthly | < 60 € | sum llm_usage.cost_eur month |
| Data freshness coverage | < 30 j | max(now() - last_enriched_at) per zone |

---

## Lecture suivante

→ `17_rgpd_aiact_owasp.md` (registre RGPD + droit accès/suppression + audit hash chain + AI Act + OWASP).
