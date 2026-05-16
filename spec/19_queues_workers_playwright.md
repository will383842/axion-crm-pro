# 19 — QUEUES + WORKERS + PLAYWRIGHT

## Vue d'ensemble

Axion CRM Pro repose massivement sur des queues asynchrones. **Redis 7** sert de broker commun entre **Laravel Horizon** (côté PHP) et **BullMQ** (côté workers Node.js Playwright). Le découpage : queues "légères" (HTTP, BD, validation, LLM) consommées par PHP-FPM via Horizon ; queues "headless" (scraping Playwright) consommées par Node.js + Chromium stealth.

Les workers Node communiquent leur résultat à Laravel via une queue de retour `scrape-results` que Laravel consomme côté PHP. Cette architecture découple totalement les deux runtimes : un crash Playwright n'impacte pas Laravel et vice-versa.

---

## 1. Liste exhaustive des 16 queues

| Queue | Runtime | Concurrence | Priorité | Retries | Backoff | Description |
|---|---|---|---|---|---|---|
| `insee-fetch` | PHP Horizon | 8 | high | 5 | exp 30s | API INSEE Sirene |
| `annuaire-entreprises-enrich` | PHP Horizon | 16 | high | 5 | exp 30s | API annuaire-entreprises.data.gouv.fr |
| `bodacc-check` | PHP Horizon | 8 | medium | 5 | exp 60s | API BODACC signaux |
| `france-travail-check` | PHP Horizon | 4 | medium | 5 | exp 60s | API France Travail recrutements |
| `mesri-scrape` | PHP Horizon | 4 | low | 3 | exp 120s | API MESRI/ONISEP écoles |
| `ban-geocode` | PHP Horizon | 16 | high | 3 | exp 30s | API BAN géocodage |
| `email-validate` | PHP Horizon | 8 | high | 3 | exp 60s | Cascade SMTP validation |
| `llm-tasks` | PHP Horizon | 8 | high | 5 | exp 30s | Appels LLM Router |
| `enrichment-orchestrator` | PHP Horizon | 4 | high | 3 | exp 30s | Orchestrateur waterfall (chain + batch) |
| `gmaps-scrape` | Node BullMQ | 4 | medium | 5 | exp 60s | Google Maps Playwright stealth |
| `pj-scrape` | Node BullMQ | 4 | medium | 5 | exp 60s | Pages Jaunes Playwright |
| `website-crawl` | Node BullMQ | **6** | high | 3 | exp 30s | Crawl sites web + extraction emails (audit P0 #6 — 12→6, 12×300MB Chromium=OOM sur 8Go RAM) |
| `linkedin-pb-find` | PHP Horizon | 2 | medium | 3 | exp 120s | PhantomBuster (long polling) |
| `crunchbase-scrape` | Node BullMQ | 2 | low | 3 | exp 300s | Crunchbase Playwright (très lent) |
| `social-light-scrape` | Node BullMQ | 4 | low | 3 | exp 120s | Handles sociaux |
| `scrape-results` | PHP Horizon | 8 | high | 3 | exp 30s | Réception résultats des workers Node |

> Concurrences ajustables runtime dans `config/horizon.php` ou env var `WORKER_CONCURRENCY_<QUEUE>`.

---

## 2. Configuration Laravel Horizon

```php
// config/horizon.php
return [
    'use' => 'default',
    'prefix' => env('HORIZON_PREFIX', 'axion-crm-horizon:'),

    'environments' => [
        'production' => [
            // High priority — réactivité critique
            'supervisor-high' => [
                'connection' => 'redis',
                'queue' => ['llm-tasks','insee-fetch','annuaire-entreprises-enrich','ban-geocode','email-validate','scrape-results','enrichment-orchestrator'],
                'balance' => 'auto',
                'maxProcesses' => 32,
                'maxTime' => 0,
                'maxJobs' => 0,
                'memory' => 256,
                'tries' => 5,
                'timeout' => 300,
                'nice' => 0,
            ],
            // Medium priority — scraping HTTP API
            'supervisor-medium' => [
                'queue' => ['bodacc-check','france-travail-check','linkedin-pb-find'],
                'maxProcesses' => 16,
                'tries' => 3,
                'timeout' => 600,
            ],
            // Low priority — batch lourd
            'supervisor-low' => [
                'queue' => ['mesri-scrape'],
                'maxProcesses' => 4,
                'tries' => 3,
                'timeout' => 1200,
            ],
        ],
    ],

    'waits' => [
        'redis:llm-tasks' => 60,
        'redis:email-validate' => 120,
        'redis:website-crawl' => 60,
    ],
];
```

---

## 3. Code worker Laravel (exemple : `EnrichWithAnnuaireEntreprisesJob`)

```php
namespace App\Modules\Sources\Jobs;

use App\Modules\Sources\Plugins\AnnuaireEntreprisesPlugin;
use App\Modules\Scraping\Dto\ScrapeRequest;
use App\Modules\Scraping\Models\ScraperRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EnrichWithAnnuaireEntreprisesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 60;
    public array $backoff = [30, 60, 120, 300, 600];

    public function __construct(
        public int $workspaceId,
        public int $companyId,
        public string $siren,
    ) {
        $this->onQueue('annuaire-entreprises-enrich');
    }

    public function handle(AnnuaireEntreprisesPlugin $plugin): void
    {
        $req = new ScrapeRequest(
            workspaceId: $this->workspaceId,
            sourceKey: 'annu_ent',
            targetId: $this->companyId,
            payload: ['siren' => $this->siren],
        );
        $started = now();
        $result = $plugin->execute($req);

        ScraperRun::create([
            'workspace_id' => $this->workspaceId,
            'scraper_name' => 'annu_ent_enrich',
            'source_key' => 'annu_ent',
            'target_id' => $this->companyId,
            'triggered_by' => 'system',
            'started_at' => $started,
            'ended_at' => now(),
            'duration_ms' => $started->diffInMilliseconds(now()),
            'status' => $result->status,
            'contacts_found' => $result->contactsFound,
            'contacts_new' => $result->contactsNew,
            'emails_found' => $result->emailsFound,
            'meta' => $result->payload,
            'error_message' => $result->errorMessage,
        ]);

        // En cas d'erreur retry, Horizon va backoff naturellement
        if ($result->status === 'error') {
            throw new \RuntimeException($result->errorMessage ?? 'Annuaire-entreprises enrich failed');
        }
    }

    public function failed(\Throwable $e): void
    {
        ScraperRun::create([
            'workspace_id' => $this->workspaceId,
            'scraper_name' => 'annu_ent_enrich',
            'source_key' => 'annu_ent',
            'target_id' => $this->companyId,
            'triggered_by' => 'system',
            'started_at' => now(),
            'ended_at' => now(),
            'duration_ms' => 0,
            'status' => 'dead_letter',
            'error_message' => $e->getMessage(),
            'error_stacktrace' => $e->getTraceAsString(),
        ]);
        // Notif Slack si dead_letter sur ce job spécifique > 10/h
    }
}
```

Dispatch :
```php
EnrichWithAnnuaireEntreprisesJob::dispatch($workspaceId, $companyId, $siren);
```

---

## 4. Code worker Node.js BullMQ (exemple : `website-crawler-worker`)

```ts
// workers/src/workers/website-crawler-worker.ts
import { Worker, Queue, QueueEvents } from 'bullmq';
import IORedis from 'ioredis';
import { websiteCrawler } from '../plugins/website-crawler';
import { log } from '../lib/logger';
import { getProxy, getUserAgent, reportProxy } from '../lib/proxy-client';
import { publishResult } from '../lib/results-publisher';

const redis = new IORedis(process.env.REDIS_URL!, { maxRetriesPerRequest: null });

export const websiteCrawlQueue = new Queue('website-crawl', { connection: redis });
const events = new QueueEvents('website-crawl', { connection: redis });

events.on('failed', ({ jobId, failedReason }) => {
  log.error({ jobId, failedReason }, 'website-crawl job failed');
});

import { validateUrlSsrfSafe } from '../lib/ssrf-guard';

const worker = new Worker(
  'website-crawl',
  async (job) => {
    const { workspaceId, companyId, website } = job.data;
    log.info({ jobId: job.id, companyId, website }, 'website-crawl: start');

    // 🛡️ SSRF defense-in-depth côté worker (audit P0 #3)
    // Belt-and-braces : même si l'API Laravel a déjà validé, on re-check ici
    // au cas où la donnée vient d'une source scraping (annu-ent, gmaps) sans validation
    try {
      await validateUrlSsrfSafe(website);
    } catch (e) {
      log.warn({ companyId, website, reason: e.message }, 'website-crawl: SSRF blocked, skip');
      await publishResult('scrape-results', {
        status: 'skipped', sourceKey: 'website', companyId, workspaceId,
        payload: { reason: 'ssrf_blocked', detail: e.message },
        contactsFound: 0, contactsNew: 0, emailsFound: 0, emailsValidated: 0,
        costEurMicro: 0, durationMs: 0,
      });
      return { status: 'skipped' };
    }

    const proxy = await getProxy(website);
    const ua = await getUserAgent();

    try {
      const result = await websiteCrawler.execute({
        workspaceId, sourceKey: 'website',
        targetId: companyId,
        payload: { website, proxy, userAgent: ua },
      });
      await publishResult('scrape-results', { ...result, sourceKey: 'website', companyId, workspaceId });
      await reportProxy(proxy.proxyId, 'success', result.durationMs);
      return result;
    } catch (e) {
      await reportProxy(proxy.proxyId, 'failure', 0, e.message);
      log.error({ jobId: job.id, error: e.message }, 'website-crawl: error');
      throw e;
    }
  },
  {
    connection: redis,
    // ⚠️ Audit P0 #6 — passé de 12 à 6 (12 × 300MB Chromium = 3,6Go sur worker 8Go RAM = OOM)
    concurrency: parseInt(process.env.WEBSITE_CRAWL_CONCURRENCY ?? '6', 10),
    limiter: { max: 30, duration: 60_000 },  // 30 jobs/min/worker
  },
);

// Restart worker après 100 jobs traités (mitigation memory leak Chromium)
let jobsProcessed = 0;
worker.on('completed', () => {
  jobsProcessed++;
  if (jobsProcessed >= 100) {
    log.info('Worker reached 100 jobs, gracefully restarting to avoid Chromium memory leak');
    worker.close().then(() => process.exit(0));   // PM2/Docker restart will respawn
  }
});

process.on('SIGTERM', async () => {
  log.info('SIGTERM received, gracefully closing worker');
  await worker.close();
  process.exit(0);
});
```

### Bootstrap multi-workers `workers/src/index.ts`

```ts
import './workers/website-crawler-worker';
import './workers/gmaps-scraper-worker';
import './workers/pj-scraper-worker';
import './workers/crunchbase-scraper-worker';
import './workers/social-light-scraper-worker';

import { log } from './lib/logger';
log.info('Axion CRM Pro workers up');
```

---

## 5. Communication Node → Laravel (queue `scrape-results`)

Workers Node publient leur résultat dans la queue `scrape-results` (gérée côté BullMQ, lue par Laravel Horizon) :

```ts
// workers/src/lib/results-publisher.ts
import { Queue } from 'bullmq';
import IORedis from 'ioredis';

const redis = new IORedis(process.env.REDIS_URL!);
const queue = new Queue('scrape-results', { connection: redis });

export async function publishResult(_q: string, payload: unknown): Promise<void> {
  await queue.add('scrape-result', payload, {
    attempts: 3,
    backoff: { type: 'exponential', delay: 30_000 },
  });
}
```

Côté Laravel, un job `ProcessScrapeResultJob` consomme `scrape-results` :

```php
class ProcessScrapeResultJob implements ShouldQueue
{
    public function __construct(public array $payload) { $this->onQueue('scrape-results'); }

    public function handle(ScrapeResultProcessor $processor): void
    {
        match ($this->payload['sourceKey']) {
            'website' => $processor->processWebsite($this->payload),
            'gmaps'   => $processor->processGmaps($this->payload),
            'pj'      => $processor->processPagesJaunes($this->payload),
            default   => throw new \RuntimeException("Unknown sourceKey: {$this->payload['sourceKey']}"),
        };
    }
}
```

Le `ScrapeResultProcessor` :
- INSERT/UPDATE `companies`, `contacts`, `company_emails`, `company_phones`, `company_strategic_keywords`, `company_social_handles`
- INSERT `scraper_runs` final (status, durée, etc.)
- Trigger jobs suivants du waterfall si nécessaire (`StepEmailFinderJob` après `website-crawl`)

> **Format payload** convention : `{ sourceKey, workspaceId, companyId, status, durationMs, contactsFound, emailsFound, payload: {...} }`.

---

## 6. Graceful shutdown

### Côté Horizon (PHP)

Horizon gère nativement SIGTERM : il attend que les jobs en cours finissent puis quitte. Configuration :

```php
// config/horizon.php
'trim' => [
    'recent' => 60, 'pending' => 60, 'completed' => 60,
    'recent_failed' => 10080, 'failed' => 10080, 'monitored' => 10080,
],
'fast_termination' => false,
```

### Côté workers Node BullMQ

```ts
process.on('SIGTERM', async () => {
  log.info('SIGTERM received');
  await worker.close();   // attend les jobs in-flight
  await redis.quit();
  process.exit(0);
});
process.on('SIGINT', async () => { /* idem */ });
```

Coolify configure `stop_grace_period: 60s` dans le compose pour donner le temps.

---

## 7. Monitoring queues

Métriques exposées (cf fichier 16) :
- `axion_queue_depth{queue=...}` — jobs en attente
- `axion_queue_jobs_processed_total{queue=..., status=...}` — counter
- `axion_queue_job_duration_seconds{queue=..., job_class=...}` — histogram
- `axion_queue_failed_jobs_total{queue=...}` — failures
- `axion_queue_workers_active{queue=...}` — workers actifs

Alertes Alertmanager :
- `axion_queue_depth > 5000` for 10m → high
- `axion_queue_depth > 10000` for 5m → critical
- `axion_queue_failed_jobs_total` rate > 50/h → high
- Si `scrape-results` queue dépasse 1000 → critique (perte de données potentielle)

---

## 8. Dead letter & retry strategy

- `tries` = 3-5 selon nature du job
- Backoff exponentiel + jitter random pour éviter thundering herd
- Après échec final → table `failed_jobs` Laravel (standard) + insertion `scraper_runs` avec `status = 'dead_letter'`
- Page admin "Dead Letter Queue" expose ces jobs (filtre par job_class) avec bouton "Retry" et "Discard"

---

## 9. Configuration BullMQ globale workers

```ts
// workers/src/config.ts
export const config = {
  redis: {
    url: process.env.REDIS_URL!,
    maxRetriesPerRequest: null,           // requis BullMQ
  },
  worker: {
    concurrency: {
      gmaps: 4,
      pj: 4,
      website: 12,
      crunchbase: 2,
      social_light: 4,
    },
    rateLimits: {
      gmaps: { max: 3, duration: 60_000 },
      pj: { max: 5, duration: 60_000 },
      website: { max: 30, duration: 60_000 },          // ✓ rate global OK
      crunchbase: { max: 1, duration: 60_000 },
    },
    // ⚠️ Audit P0 #6 — concurrences ajustées RAM-aware (8 Go par worker-node)
    // Budget RAM Chromium par worker = ~5 Go (laisse 3 Go buffer Node + OS)
    // 6 × 300MB + 4 × 250MB + 4 × 250MB + 2 × 350MB + 4 × 200MB = 5,4 Go (max parallèle théorique)
    // En pratique on dédie 1 worker par scraper type → reste tenable
    chromiumMaxConcurrencyPerWorker: {
      website: 6,      // était 12 avant audit
      gmaps: 4,
      pj: 4,
      crunchbase: 2,
      social_light: 4,
    },
    // Restart worker après N jobs (mitigation Chromium memory leak)
    jobsBeforeRestart: 100,
    timeouts: {
      gmaps: 180_000,
      pj: 60_000,
      website: 120_000,
      crunchbase: 300_000,
      social_light: 90_000,
    },
  },
};
```

---

## 10. Tests d'acceptance (S2 + S12)

- [ ] 16 queues définies et configurées correctement
- [ ] Horizon UI accessible `/horizon` (admin seulement)
- [ ] 200 jobs in-flight gérés sans crash
- [ ] SIGTERM → tous les jobs in-flight finissent avant exit (max 60s)
- [ ] Job failed final apparaît dans `failed_jobs` + dans page admin "Dead Letter"
- [ ] Rate limit BullMQ respecté (vérifier en simulant burst)
- [ ] Communication Node → PHP via `scrape-results` fiable (0 perte sur 10k jobs)
- [ ] Concurrency configurable runtime via env var

---

## 11. Anti-patterns interdits

- ❌ Job sans `tries` + `backoff` (= retry infini ou abandon précoce)
- ❌ Job sans `timeout` (= job zombie qui bloque worker)
- ❌ Logique métier dans `handle()` du job — déléguer à un service (testable + réutilisable)
- ❌ `Bus::chain([...])` sans gestion d'échec (toute la chaîne se bloque)
- ❌ Workers Node qui appellent directement PostgreSQL (passer par queue → Laravel)
- ❌ Stocker payload énorme (> 1Mo) dans job (utiliser ref `target_id` + lookup)

---

## Prochaine étape

→ Lire `20_detection_nouveaux_prospects_signaux.md` pour les jobs nightly de détection.
