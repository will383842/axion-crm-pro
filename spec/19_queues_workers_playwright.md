# 19 — Queues + workers Laravel & Playwright

> **Architecture hybride :** Laravel Horizon (PHP queues sur Redis DB 0) + BullMQ Node (workers Playwright sur Redis DB 1). Bridge Redis : convention de nommage commune.

---

## §1 — Configuration Horizon (Laravel)

### `config/horizon.php`

```php
return [
    'environments' => [
        'production' => [
            // Queue critique : enrichment orchestrator (latence faible)
            'critical-supervisor' => [
                'connection' => 'redis',
                'queue'      => ['critical'],
                'balance'    => 'auto',
                'minProcesses' => 2,
                'maxProcesses' => 6,
                'memory'     => 256,
                'tries'      => 3,
                'timeout'    => 300,
                'nice'       => 0,
            ],

            // Scraping APIs officielles (rate-limited mais fiables)
            'api-supervisor' => [
                'connection' => 'redis',
                'queue'      => ['insee','annuaire-entreprises','bodacc','france-travail','mesri','ban','crunchbase-api'],
                'balance'    => 'auto',
                'minProcesses' => 1,
                'maxProcesses' => 4,
                'memory'     => 256,
                'tries'      => 5,
                'backoff'    => [60, 300, 900, 3600, 14400],
                'timeout'    => 120,
            ],

            // Scrapers heavy (Playwright dispatch côté Laravel → Node)
            'scraper-dispatch-supervisor' => [
                'connection' => 'redis',
                'queue'      => ['scraping-dispatch'],
                'balance'    => 'auto',
                'minProcesses' => 2,
                'maxProcesses' => 8,
                'memory'     => 256,
                'tries'      => 3,
                'timeout'    => 180,
            ],

            // Direction Finder (LLM-heavy)
            'direction-finder-supervisor' => [
                'connection' => 'redis',
                'queue'      => ['direction-finder'],
                'balance'    => 'simple',
                'processes'  => 2,
                'memory'     => 512,
                'tries'      => 2,
                'timeout'    => 600,
            ],

            // Email finder + validation SMTP
            'email-supervisor' => [
                'connection' => 'redis',
                'queue'      => ['email-finder','smtp-validation'],
                'balance'    => 'auto',
                'minProcesses' => 2,
                'maxProcesses' => 6,
                'memory'     => 256,
                'tries'      => 3,
                'timeout'    => 90,
            ],

            // LLM calls
            'llm-supervisor' => [
                'connection' => 'redis',
                'queue'      => ['llm','llm-haiku','llm-mistral'],
                'balance'    => 'auto',
                'minProcesses' => 2,
                'maxProcesses' => 4,
                'memory'     => 256,
                'tries'      => 3,
                'timeout'    => 60,
            ],

            // Maintenance, jobs nightly, notifications
            'default-supervisor' => [
                'connection' => 'redis',
                'queue'      => ['default','notifications','exports','imports'],
                'balance'    => 'auto',
                'minProcesses' => 1,
                'maxProcesses' => 3,
                'memory'     => 256,
                'tries'      => 3,
                'timeout'    => 300,
            ],
        ],
    ],
    'metrics' => ['trim_snapshots' => ['job' => 720, 'queue' => 720]],
    'memory_limit' => 64,
    'queue_wait' => ['redis:critical' => 30, 'redis:default' => 120, 'redis:scraping-dispatch' => 60],
];
```

### Job exemple — orchestration enrichissement

```php
// app/Jobs/EnrichCompanyJob.php
class EnrichCompanyJob implements ShouldQueue
{
    use Queueable, SerializesModels, Dispatchable;

    public int $tries = 1;
    public int $backoff = 0;
    public int $timeout = 300;

    public function __construct(public string $companyId, public ?string $triggeredByUserId = null)
    {
        $this->onQueue('critical');
    }

    public function handle(WaterfallOrchestrator $orchestrator): void
    {
        $company = Company::findOrFail($this->companyId);
        $user = $this->triggeredByUserId ? User::find($this->triggeredByUserId) : null;
        $orchestrator->enrichCompany($company, $user);
    }

    public function failed(\Throwable $e): void
    {
        Log::channel('stdout')->error('EnrichCompanyJob failed', ['company' => $this->companyId, 'error' => $e->getMessage()]);
        Anomaly::create([
            'kind' => 'enrichment_failed',
            'severity' => 'warning',
            'message' => "Company {$this->companyId} enrichment failed: {$e->getMessage()}",
            'detected_at' => now(),
        ]);
    }
}
```

### Job exemple — dispatch scraping Playwright

```php
// app/Jobs/Scraping/DispatchPlaywrightScraperJob.php
class DispatchPlaywrightScraperJob implements ShouldQueue
{
    use Queueable, Dispatchable;

    public function __construct(
        public string $scraperPluginSlug,         // 'google_maps'|'google_search'|'direction_finder'
        public string $targetRef,
        public string $workspaceId,
        public ?string $triggerScraperRunId = null,
    ) {
        $this->onQueue('scraping-dispatch');
    }

    public function handle(ProxyRouter $proxyRouter, UserAgentSelector $uaSelector, Redis $redis): void
    {
        // 1. Pre-flight checks
        if ($this->isOptedOut()) {
            ScraperRun::find($this->triggerScraperRunId)?->update(['status' => 'skipped_opt_out', 'completed_at' => now()]);
            return;
        }
        if (!$this->shouldScrape()) {
            ScraperRun::find($this->triggerScraperRunId)?->update(['status' => 'skipped_already_fresh', 'completed_at' => now()]);
            return;
        }

        // 2. Acquire proxy + UA
        $proxy = $proxyRouter->acquireForJob($this);
        $ua = $uaSelector->pickFor($this->extractDomain());

        // 3. Push to BullMQ (côté Node)
        $jobData = [
            'workspaceId' => $this->workspaceId,
            'source' => $this->scraperPluginSlug,
            'targetType' => 'company',
            'targetRef' => $this->targetRef,
            'runId' => $this->triggerScraperRunId,
            'proxy' => ['id' => $proxy->proxyId, 'url' => $proxy->proxyUrl],
            'userAgent' => $ua->user_agent,
            'settings' => $this->fetchSettings(),
        ];
        Redis::connection('bullmq')->select(1);   // DB 1 = BullMQ
        Redis::connection('bullmq')->rpush(
            "bull:scraping_{$this->scraperPluginSlug}:wait",
            json_encode([
                'name' => 'scrape',
                'data' => $jobData,
                'opts' => ['attempts' => 3, 'backoff' => ['type' => 'exponential', 'delay' => 5000]],
                'timestamp' => now()->getTimestampMs(),
            ])
        );
        Redis::connection('bullmq')->lpush('bull:scraping_'.$this->scraperPluginSlug.':waiting-children:notif', '1');
    }
}
```

---

## §2 — Workers Node.js Playwright (BullMQ)

### `workers/src/main.ts`

```typescript
import { Worker, QueueEvents } from 'bullmq'
import IORedis from 'ioredis'
import pino from 'pino'
import { ScraperContextSchema } from './scrapers/types'
import { plugins } from './scrapers'
import { reportResult } from './bridge/report'

const logger = pino({ level: 'info' })
const connection = new IORedis({
  host: process.env.REDIS_HOST!,
  port: parseInt(process.env.REDIS_PORT ?? '6379'),
  db: 1,                                          // bridge DB
  maxRetriesPerRequest: null,
})

const WORKER_TYPE = process.env.WORKER_TYPE!     // 'google_maps'|'google_search'|'direction_finder'|...
const CONCURRENCY = parseInt(process.env.CONCURRENCY ?? '2')

const plugin = plugins[WORKER_TYPE]
if (!plugin) throw new Error(`Unknown worker type: ${WORKER_TYPE}`)

const queueName = `scraping_${WORKER_TYPE}`

const worker = new Worker(
  queueName,
  async (job) => {
    const ctx = ScraperContextSchema.parse(job.data)
    logger.info({ runId: ctx.runId, source: ctx.source }, 'starting scrape')
    const result = await plugin.scrape(ctx)
    await reportResult(ctx, result)
    return result
  },
  { connection, concurrency: CONCURRENCY, lockDuration: 600_000 }
)

worker.on('failed', (job, err) => {
  logger.error({ jobId: job?.id, err }, 'job failed')
})

worker.on('completed', (job) => {
  logger.info({ jobId: job.id, durationMs: Date.now() - job.timestamp }, 'job completed')
})

const events = new QueueEvents(queueName, { connection })
events.on('failed', ({ jobId, failedReason }) => {
  logger.error({ jobId, failedReason }, 'job failed via events')
})

// Graceful shutdown
async function shutdown() {
  logger.info('shutdown signal received')
  await worker.close()
  await connection.quit()
  process.exit(0)
}
process.on('SIGTERM', shutdown)
process.on('SIGINT', shutdown)
```

### `workers/src/bridge/report.ts`

Report résultat vers Laravel via API HTTP interne :

```typescript
import { fetch } from 'undici'
import type { ScraperContext, ScraperResult } from '../scrapers/types'

export async function reportResult(ctx: ScraperContext, result: ScraperResult): Promise<void> {
  await fetch(`${process.env.LARAVEL_INTERNAL_URL}/internal/scraper-result`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Internal-Token': process.env.INTERNAL_API_TOKEN!,
    },
    body: JSON.stringify({ context: ctx, result }),
  })
}
```

Côté Laravel :

```php
// routes/internal.php (NOT exposed via Caddy — bind 127.0.0.1 only)
Route::post('/internal/scraper-result', function (Request $req) {
    abort_unless($req->header('X-Internal-Token') === config('app.internal_api_token'), 403);
    $ctx = ScraperContextData::from($req->input('context'));
    $result = ScraperResultData::from($req->input('result'));
    app(ScraperResultProcessor::class)->process($ctx, $result);
    return response()->noContent();
})->withoutMiddleware(['auth', 'csrf', 'set.workspace']);
```

### `ScraperResultProcessor`

```php
class ScraperResultProcessor
{
    public function process(ScraperContextData $ctx, ScraperResultData $result): void
    {
        DB::beginTransaction();
        try {
            $run = ScraperRun::find($ctx->runId);
            $run->update([
                'completed_at' => now(),
                'duration_ms'  => $result->metrics->durationMs,
                'status'       => $result->status,
                'contacts_found' => $result->metrics->contactsFound,
                'emails_found' => $result->metrics->emailsFound,
                'tokens_consumed' => $result->metrics->tokensConsumed ?? 0,
                'cost_eur'     => $this->computeCost($result),
                'metadata'     => $result->data,
                'error_message' => $result->error?->message,
                'error_code'    => $result->error?->code,
            ]);

            // Apply data to Company/Contact/etc.
            $applier = match ($ctx->source) {
                'google_maps' => app(GoogleMapsResultApplier::class),
                'site_web'    => app(SiteWebResultApplier::class),
                'google_search' => app(GoogleSearchResultApplier::class),
                'direction_finder' => app(DirectionFinderResultApplier::class),
                default => null,
            };
            $applier?->apply($ctx, $result);

            DB::commit();

            // Métriques Prometheus
            app(ScrapingMetrics::class)->recordRun($ctx->source, $result->status, $result->metrics->durationMs);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
```

---

## §3 — Schedule (Laravel cron)

### `app/Console/Kernel.php`

```php
protected function schedule(Schedule $schedule): void
{
    // Refresh coverage matrix (extra, en plus de pg_cron)
    $schedule->command('coverage:refresh-mv')->hourly()->onOneServer();

    // Discover new prospects
    $schedule->command('discover:insee-new-companies')->dailyAt('02:00')->onOneServer();
    $schedule->command('discover:bodacc-signals')->dailyAt('02:30')->onOneServer();
    $schedule->command('discover:france-travail-hiring')->dailyAt('03:00')->onOneServer();
    $schedule->command('discover:crunchbase-fundraising')->dailyAt('03:30')->onOneServer();
    $schedule->command('discover:news-french-tech')->weeklyOn(1, '04:00')->onOneServer();

    // Re-enrichment (TTL expirés)
    $schedule->command('enrich:re-enrich-stale')->dailyAt('05:00')->onOneServer();

    // Maintenance
    $schedule->command('proxies:health-check')->everyTwoHours()->onOneServer();
    $schedule->command('search-engines:health-check')->hourly()->onOneServer();
    $schedule->command('email:update-disposable-list')->monthlyOn(1, '03:00')->onOneServer();
    $schedule->command('validators:check-blacklist')->hourly()->onOneServer();

    // RGPD
    $schedule->command('rgpd:purge-stale-records')->dailyAt('06:00')->onOneServer();
    $schedule->command('rgpd:anonymize-old-ips')->dailyAt('06:30')->onOneServer();
    $schedule->command('audit:verify-chain')->dailyAt('07:00')->onOneServer();

    // Monitoring
    $schedule->command('anomalies:detect')->everyFifteenMinutes()->onOneServer();
    $schedule->command('coverage:detect-duplicates-flags')->dailyAt('08:00')->onOneServer();

    // Snapshots analytics (Phase 2 ready)
    $schedule->command('analytics:snapshot-daily')->dailyAt('00:30')->onOneServer();

    // Horizon snapshot (metrics)
    $schedule->command('horizon:snapshot')->everyFiveMinutes();
}
```

---

## §4 — Worker concurrency (récap)

| Worker | Machine | Concurrence | Notes |
|--------|---------|-------------|-------|
| `worker-google-maps` | worker-1 | 4 | Sessions Playwright lourdes |
| `worker-pages-jaunes` | worker-1 | 3 | |
| `worker-sites-web` | worker-1 | 6 | Sites variés, latence variable |
| `worker-google-search` | worker-2 | 3 | Captcha-sensitive |
| `worker-direction-finder` | worker-2 | 2 | LLM-heavy, slow |
| `worker-crunchbase` | worker-2 | 2 | Rate-limit strict |
| `worker-social-light` | worker-2 | 4 | Léger |

Horizon Laravel (côté app server) :
- `critical-supervisor` : 2-6 processus
- `api-supervisor` : 1-4 processus
- `scraper-dispatch-supervisor` : 2-8 processus
- `direction-finder-supervisor` : 2 processus
- `email-supervisor` : 2-6 processus
- `llm-supervisor` : 2-4 processus
- `default-supervisor` : 1-3 processus

---

## §5 — Graceful shutdown

### Laravel Horizon

```php
// Supervisor sends SIGTERM → Horizon awaits jobs in-progress (timeout 300s)
'wait' => 60,
'maxJobs' => 1000,           // restart after 1000 jobs (prevent memory leaks)
'maxTime' => 3600,           // restart after 1h
```

### Node BullMQ workers

```typescript
// Cf. workers/src/main.ts
process.on('SIGTERM', async () => {
  await worker.close()          // attend les jobs en cours, max 600s (lockDuration)
  await connection.quit()
  process.exit(0)
})
```

### Docker Compose

```yaml
services:
  worker:
    stop_grace_period: 10m       # wait 10 min before SIGKILL
```

---

## §6 — Retry policies

| Type job | Tries | Backoff (s) | Notes |
|----------|-------|-------------|-------|
| Enrichment orchestrator | 1 | — | Pas de retry, signaler erreur immédiat |
| API officielle (INSEE, etc.) | 5 | [60, 300, 900, 3600, 14400] | Rate-limit + 429 |
| Scraping Playwright | 3 | [5000, 15000, 60000] | Captcha → bascule moteur, retry après cooldown |
| Direction Finder | 2 | [30000] | LLM-heavy, retry coûteux |
| Email finder + SMTP | 3 | [5000, 30000, 120000] | Greylisting possible |
| LLM call | 3 | [1000, 5000, 15000] | Rate-limit ou timeout |

---

## §7 — Dead Letter Queue

Tout job qui échoue après tries exhausted → `failed_jobs` table Laravel + `bull:scraping_*:failed` Redis (BullMQ retention 14j).

UI admin "Scraper Runs" affiche les `failed_jobs` avec bouton "Retry" + "Mark resolved".

---

## §8 — Queue depth alerting

Cf. `16_monitoring_observabilite.md` § 5 (alert `RedisQueueBacklog`).

Si une queue dépasse 10 000 jobs en attente pendant > 15 min :
- Alerte Slack
- Auto-scale Horizon processes (+50% via `php artisan horizon:scale supervisor max=12`)

---

## Lecture suivante

→ `20_detection_nouveaux_prospects_signaux.md` (jobs nightly INSEE / BODACC / France Travail + scraping news FR).
