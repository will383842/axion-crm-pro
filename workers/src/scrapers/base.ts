import pino from 'pino';
import { getRedis } from '../bridge/redis';
import { sendResult, type ScrapeResult } from '../bridge/result-sender';
import { tickJob } from '../healthcheck-server';
import type { QueueName } from '../bridge/queues';

const log = pino({ name: 'worker-base' });

export interface ScrapeRequestJob {
  run_id: string;
  source: string;
  target_url: string | null;
  context?: Record<string, unknown>;
  company_id?: number;
  proxy_url?: string;
  user_agent?: string;
  timeout_s?: number;
  attempts?: number;
  max_attempts?: number;
}

export interface ScraperImplementation {
  scrape(req: ScrapeRequestJob): Promise<Omit<ScrapeResult, 'run_id' | 'source'>>;
  name: string;
}

/** La part de Redis dont le moteur dépend — injectable, donc testable sans réseau. */
export interface RedisLike {
  brpop(queue: string, timeoutSeconds: number): Promise<[string, string] | null>;
  lpush(queue: string, value: string): Promise<unknown>;
}

export interface StartWorkerDeps {
  concurrency?: number;
  redis?: RedisLike;
  send?: (result: ScrapeResult) => Promise<void>;
  /** Fermeture des ressources externes (navigateur) une fois le drain terminé. */
  onShutdown?: () => void | Promise<void>;
  /** `false` en test : ne pas poser de gestionnaires sur les signaux du processus. */
  handleSignals?: boolean;
}

export interface WorkerHandle {
  /** Demande l'arrêt : plus aucun job n'est tiré, le job en cours va au bout. */
  stop(): void;
  /** Résolue quand toutes les boucles sont sorties et `onShutdown` exécuté. */
  readonly drained: Promise<void>;
  /** Résolue dès qu'aucun job n'est plus en cours (n'attend pas la fin des BRPOP). */
  whenIdle(): Promise<void>;
}

/** Délai maximal accordé aux jobs en cours après un signal, avant sortie forcée. */
const DELAI_ARRET_MS = 25_000;

interface EtatWorker {
  stopping: boolean;
  enCours: number;
  attentesIdle: Array<() => void>;
}

/**
 * Worker pool sur Redis BRPOP — N consumers parallèles tirent des jobs de la liste.
 * À chaque pop : exécute scrape() avec retry exponential, envoie résultat via
 * /internal/scraper-result HMAC, tick healthcheck.
 *
 * ARRÊT GRACIEUX (ajouté le 2026-08-18) : auparavant `consumeLoop` bouclait
 * `for (;;)` sans aucune sortie, et seul `browser/launcher.ts` écoutait
 * SIGTERM — pour fermer le navigateur SOUS le job en cours. Poser un écouteur
 * sur SIGTERM supprime en outre la terminaison par défaut de Node : le worker
 * continuait donc à tirer des jobs jusqu'au SIGKILL, et le job en vol était
 * perdu (retiré de Redis, jamais renvoyé au CRM). Désormais :
 *   - un drapeau d'arrêt est lu EN TÊTE de boucle ;
 *   - un job tiré alors que l'arrêt est déjà demandé est REMIS en file ;
 *   - le job en cours va au bout, puis les ressources sont fermées.
 */
export function startWorker(queue: QueueName, impl: ScraperImplementation, deps: StartWorkerDeps = {}): WorkerHandle {
  const concurrency = resoudreConcurrence(deps.concurrency);
  const redis = deps.redis ?? (getRedis() as unknown as RedisLike);
  const envoyer = deps.send ?? sendResult;

  const etat: EtatWorker = { stopping: false, enCours: 0, attentesIdle: [] };

  const signalerIdle = (): void => {
    if (etat.enCours !== 0) return;
    for (const resoudre of etat.attentesIdle.splice(0)) resoudre();
  };

  // Deux chemins mènent à la fermeture des ressources (sortie des boucles, ou
  // signal reçu alors que les boucles sont encore dans leur BRPOP) : elle ne
  // doit avoir lieu qu'UNE fois.
  let fermetureFaite = false;
  const fermerUneSeuleFois = async (): Promise<void> => {
    if (fermetureFaite) return;
    fermetureFaite = true;
    await deps.onShutdown?.();
  };

  log.info({ queue, concurrency }, 'Worker pool starting');

  const boucles: Array<Promise<void>> = [];
  for (let i = 0; i < concurrency; i++) {
    boucles.push(consumeLoop(queue, impl, i, redis, envoyer, etat, signalerIdle));
  }

  const drained = Promise.all(boucles)
    .then(fermerUneSeuleFois)
    .catch((err: unknown) => {
      log.error({ err }, 'Erreur pendant le drain du worker');
    });

  const handle: WorkerHandle = {
    stop(): void {
      if (etat.stopping) return;
      etat.stopping = true;
      log.info({ queue, enCours: etat.enCours }, 'Arrêt demandé — plus aucun job ne sera tiré');
    },
    drained,
    whenIdle(): Promise<void> {
      if (etat.enCours === 0) return Promise.resolve();
      return new Promise<void>((resolve) => {
        etat.attentesIdle.push(resolve);
      });
    },
  };

  if (deps.handleSignals ?? true) {
    installerSignaux(handle, fermerUneSeuleFois);
  }

  return handle;
}

/**
 * `Math.max(1, Number('auto'))` vaut NaN, et `for (let i = 0; i < NaN; i++)` ne
 * tourne PAS une seule fois : une valeur illisible donnait ZÉRO consommateur —
 * un worker qui démarre, répond 200 sur /healthz, et ne consomme jamais rien.
 */
function resoudreConcurrence(explicite?: number): number {
  const brut = explicite ?? Number(process.env['WORKER_CONCURRENCY'] ?? 2);
  if (!Number.isFinite(brut) || brut < 1) {
    log.warn({ valeur: explicite ?? process.env['WORKER_CONCURRENCY'] }, 'Concurrence illisible — repli sur 1');
    return 1;
  }
  return Math.floor(brut);
}

async function consumeLoop(
  queue: string,
  impl: ScraperImplementation,
  consumerId: number,
  redis: RedisLike,
  envoyer: (result: ScrapeResult) => Promise<void>,
  etat: EtatWorker,
  signalerIdle: () => void,
): Promise<void> {
  while (!etat.stopping) {
    try {
      const popped = await redis.brpop(queue, 30);
      if (!popped) continue;
      const [, raw] = popped;

      // Course réelle : le signal arrive ENTRE le BRPOP et le début du scrape.
      // Sans cette remise en file, le job est déjà sorti de Redis et personne
      // ne le retraitera jamais.
      if (etat.stopping) {
        await redis.lpush(queue, raw);
        log.info({ consumerId }, 'Job remis en file : arrêt demandé avant traitement');
        return;
      }

      const job = JSON.parse(raw) as ScrapeRequestJob;
      tickJob();

      etat.enCours += 1;
      const start = Date.now();
      try {
        const out = await impl.scrape(job);
        await envoyer({
          run_id: job.run_id,
          source: job.source,
          ...out,
          latency_ms: Date.now() - start,
          fetched_at: new Date().toISOString(),
        });
      } catch (err) {
        const attempts = (job.attempts ?? 0) + 1;
        const max = job.max_attempts ?? 3;
        log.warn({ err, run_id: job.run_id, attempts, max, consumerId }, 'Job failed');

        if (attempts < max) {
          await redis.lpush(queue, JSON.stringify({ ...job, attempts }));
        } else {
          await envoyer({
            run_id: job.run_id,
            source: job.source,
            status: 'failed',
            error: err instanceof Error ? err.message : 'unknown',
            latency_ms: Date.now() - start,
            fetched_at: new Date().toISOString(),
          });
        }
      } finally {
        etat.enCours -= 1;
        signalerIdle();
      }
    } catch (err) {
      log.error({ err, consumerId }, 'Consumer loop error, retrying in 5s');
      await new Promise((r) => setTimeout(r, 5000));
    }
  }
}

/**
 * SIGTERM/SIGINT : demander l'arrêt, attendre la fin des jobs EN COURS (pas la
 * fin des BRPOP, qui peuvent bloquer jusqu'à 30 s), fermer les ressources,
 * sortir. Un second signal sort immédiatement.
 */
function installerSignaux(handle: WorkerHandle, onShutdown?: () => void | Promise<void>): void {
  let dejaDemande = false;

  const gerer = (signal: string): void => {
    if (dejaDemande) {
      log.warn({ signal }, 'Second signal — sortie immédiate');
      process.exit(1);
    }
    dejaDemande = true;
    log.info({ signal }, 'Signal reçu — arrêt gracieux');
    handle.stop();

    const delai = new Promise<'delai'>((resolve) => {
      const t = setTimeout(() => resolve('delai'), DELAI_ARRET_MS);
      t.unref();
    });

    void Promise.race([handle.whenIdle().then(() => 'idle' as const), delai])
      .then(async (issue) => {
        if (issue === 'delai') {
          log.warn("Délai d'arrêt dépassé — sortie avec des jobs encore en cours");
        }
        await onShutdown?.();
        const { closeBrowser } = await import('../browser/launcher');
        await closeBrowser();
      })
      .catch((err: unknown) => {
        log.error({ err }, "Erreur pendant l'arrêt");
      })
      .finally(() => process.exit(0));
  };

  process.once('SIGTERM', () => gerer('SIGTERM'));
  process.once('SIGINT', () => gerer('SIGINT'));
}
