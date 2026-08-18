import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  startWorker,
  type ScraperImplementation,
  type ScrapeRequestJob,
  type RedisLike,
  type StartWorkerDeps,
  type WorkerHandle,
} from '../src/scrapers/base';
import type { ScrapeResult } from '../src/bridge/result-sender';

/**
 * LE MOTEUR — `src/scrapers/base.ts`. Cœur du système, zéro test jusqu'ici :
 * boucle BRPOP, concurrence, retry, appel de l'implémentation, envoi du
 * résultat, arrêt. Un job perdu ou rejoué en boucle n'aurait été vu par
 * personne : les workers n'ont AUCUN accès base, tout passe par ce fichier.
 *
 * Redis et l'envoi HTTP sont INJECTÉS (pas de réseau). `ioredis` est en outre
 * simulé : si l'injection venait à être court-circuitée, le test rougirait au
 * lieu de tenter une vraie connexion et de rester suspendu.
 */
vi.mock('ioredis', () => ({
  default: class {
    brpop(): never {
      throw new Error('ioredis réel utilisé dans un test : le client injecté a été court-circuité.');
    }
  },
}));

const FILE = 'axion:scrape:google-maps';
const RACINE_WORKERS = resolve(dirname(fileURLToPath(import.meta.url)), '..');

/**
 * Redis de test : sert les jobs d'une liste, enregistre les remises en file.
 *
 * Le BRPOP simulé cède d'abord la main (`setTimeout 0`) : sans cela le premier
 * pop de chaque consommateur s'exécuterait AVANT même que `startWorker` ait
 * rendu son handle, et les tests ne pourraient pas s'y raccrocher.
 *
 * Quand la liste est vide, le test demande l'arrêt — sinon la boucle
 * attendrait indéfiniment de nouveaux jobs, ce qui est le comportement ATTENDU
 * d'un worker en production (constat vérifié : sans ce crochet, la suite reste
 * suspendue jusqu'au délai de garde).
 */
function faireRedis(jobs: string[]): RedisLike & {
  remisEnFile: Array<{ file: string; valeur: string }>;
  popsFaits: number;
  quandVide?: (() => void) | undefined;
  avantDeRendre?: (() => void) | undefined;
} {
  const remisEnFile: Array<{ file: string; valeur: string }> = [];
  const r = {
    remisEnFile,
    popsFaits: 0,
    quandVide: undefined as (() => void) | undefined,
    avantDeRendre: undefined as (() => void) | undefined,
    async brpop(file: string, _timeout: number): Promise<[string, string] | null> {
      await new Promise((resolve) => setTimeout(resolve, 0));
      r.popsFaits += 1;
      const j = jobs.shift();
      if (j === undefined) {
        r.quandVide?.();
        return null;
      }
      r.avantDeRendre?.();
      return [file, j];
    },
    async lpush(file: string, valeur: string): Promise<number> {
      remisEnFile.push({ file, valeur });
      return remisEnFile.length;
    },
  };
  return r;
}

/** Démarre le worker et branche « file vide → arrêt » sur son handle. */
function demarrer(
  redis: ReturnType<typeof faireRedis>,
  impl: ScraperImplementation,
  deps: Omit<StartWorkerDeps, 'redis' | 'handleSignals'> = {},
): WorkerHandle {
  const h = startWorker(FILE, impl, { ...deps, redis, handleSignals: false });
  redis.quandVide = () => {
    h.stop();
  };
  return h;
}

function job(partiel: Partial<ScrapeRequestJob> = {}): string {
  return JSON.stringify({
    run_id: 'run-1',
    source: 'google-maps',
    target_url: 'https://exemple.fr',
    ...partiel,
  });
}

function implQuiReussit(scrapes: ScrapeRequestJob[]): ScraperImplementation {
  return {
    name: 'test-ok',
    async scrape(req) {
      scrapes.push(req);
      return { status: 'success', payload: { vu: req.run_id }, emails: ['a@b.fr'] };
    },
  };
}

function implQuiEchoue(message = 'boum'): ScraperImplementation {
  return {
    name: 'test-ko',
    scrape() {
      return Promise.reject(new Error(message));
    },
  };
}

/** Laisse tourner la boucle d'événements quelques tours. */
const respirer = (ms = 10): Promise<void> => new Promise((r) => setTimeout(r, ms));

const rienEnvoyer = async (): Promise<void> => {};

beforeEach(() => {
  vi.unstubAllEnvs();
});
afterEach(() => {
  vi.unstubAllEnvs();
});

describe('moteur — chemin nominal', () => {
  it('tire un job, appelle scrape(), puis envoie le résultat enrichi', async () => {
    const scrapes: ScrapeRequestJob[] = [];
    const envoyes: ScrapeResult[] = [];
    const redis = faireRedis([job({ run_id: 'run-42', source: 'pages-jaunes' })]);

    const h = demarrer(redis, implQuiReussit(scrapes), {
      concurrency: 1,
      send: async (r) => {
        envoyes.push(r);
      },
    });
    await h.drained;

    expect(scrapes).toHaveLength(1);
    expect(scrapes[0]!.run_id).toBe('run-42');
    expect(envoyes).toHaveLength(1);
    expect(envoyes[0]).toMatchObject({
      run_id: 'run-42',
      source: 'pages-jaunes',
      status: 'success',
      payload: { vu: 'run-42' },
      emails: ['a@b.fr'],
    });
    // Les deux champs que SEUL le moteur peut poser.
    expect(typeof envoyes[0]!.latency_ms).toBe('number');
    expect(envoyes[0]!.fetched_at).toMatch(/^\d{4}-\d{2}-\d{2}T.*Z$/);
  });

  it('un BRPOP vide (délai écoulé) ne casse rien : la boucle repart', async () => {
    const scrapes: ScrapeRequestJob[] = [];
    const redis = faireRedis([]);
    const h = demarrer(redis, implQuiReussit(scrapes), { concurrency: 1, send: rienEnvoyer });
    await h.drained;
    expect(redis.popsFaits).toBeGreaterThanOrEqual(1);
    expect(scrapes).toHaveLength(0);
  });

  it('traite tous les jobs de la file', async () => {
    const scrapes: ScrapeRequestJob[] = [];
    const envoyes: ScrapeResult[] = [];
    const redis = faireRedis([job({ run_id: 'a' }), job({ run_id: 'b' }), job({ run_id: 'c' })]);
    const h = demarrer(redis, implQuiReussit(scrapes), {
      concurrency: 1,
      send: async (r) => {
        envoyes.push(r);
      },
    });
    await h.drained;
    expect(scrapes.map((s) => s.run_id)).toEqual(['a', 'b', 'c']);
    expect(envoyes).toHaveLength(3);
  });
});

describe('moteur — reprise sur échec', () => {
  it("remet le job en file avec attempts+1 tant que max_attempts n'est pas atteint", async () => {
    const envoyes: ScrapeResult[] = [];
    const redis = faireRedis([job({ run_id: 'r1', attempts: 0, max_attempts: 3 })]);
    const h = demarrer(redis, implQuiEchoue(), {
      concurrency: 1,
      send: async (r) => {
        envoyes.push(r);
      },
    });
    await h.drained;

    expect(redis.remisEnFile).toHaveLength(1);
    expect(redis.remisEnFile[0]!.file).toBe(FILE);
    expect(JSON.parse(redis.remisEnFile[0]!.valeur)).toMatchObject({ run_id: 'r1', attempts: 1 });
    // Surtout : AUCUN résultat n'est envoyé tant qu'il reste des tentatives —
    // sinon le CRM enregistrerait un échec pour un job encore en course.
    expect(envoyes).toHaveLength(0);
  });

  it("à la dernière tentative, envoie un résultat « failed » portant le message d'erreur", async () => {
    const envoyes: ScrapeResult[] = [];
    const redis = faireRedis([job({ run_id: 'r2', attempts: 2, max_attempts: 3 })]);
    const h = demarrer(redis, implQuiEchoue('403 Forbidden'), {
      concurrency: 1,
      send: async (r) => {
        envoyes.push(r);
      },
    });
    await h.drained;

    expect(redis.remisEnFile).toHaveLength(0);
    expect(envoyes).toHaveLength(1);
    expect(envoyes[0]).toMatchObject({
      run_id: 'r2',
      source: 'google-maps',
      status: 'failed',
      error: '403 Forbidden',
    });
  });

  it('max_attempts par défaut = 3', async () => {
    const envoyes: ScrapeResult[] = [];
    const redis = faireRedis([job({ run_id: 'r3', attempts: 2 })]);
    const h = demarrer(redis, implQuiEchoue(), {
      concurrency: 1,
      send: async (r) => {
        envoyes.push(r);
      },
    });
    await h.drained;
    expect(envoyes.map((e) => e.status)).toEqual(['failed']);
  });
});

describe('moteur — concurrence', () => {
  it('démarre autant de consommateurs que WORKER_CONCURRENCY', async () => {
    vi.stubEnv('WORKER_CONCURRENCY', '4');
    const redis = faireRedis([]);
    const h = demarrer(redis, implQuiReussit([]), { send: rienEnvoyer });
    await h.drained;
    // Chaque consommateur fait au moins un BRPOP avant de voir la file vide.
    expect(redis.popsFaits).toBe(4);
  });

  it('WORKER_CONCURRENCY illisible ne doit JAMAIS donner ZÉRO consommateur', async () => {
    // DÉFAUT MESURÉ : `Math.max(1, Number('auto'))` vaut NaN, et
    // `for (let i = 0; i < NaN; i++)` ne tourne pas une seule fois. Le worker
    // démarre, /healthz répond 200, et il ne consomme RIEN — pour toujours.
    vi.stubEnv('WORKER_CONCURRENCY', 'auto');
    const redis = faireRedis([]);
    const h = demarrer(redis, implQuiReussit([]), { send: rienEnvoyer });
    await h.drained;
    expect(redis.popsFaits).toBeGreaterThanOrEqual(1);
  });

  it('WORKER_CONCURRENCY=0 ou négatif est ramené à au moins un consommateur', async () => {
    vi.stubEnv('WORKER_CONCURRENCY', '0');
    const redis = faireRedis([]);
    const h = demarrer(redis, implQuiReussit([]), { send: rienEnvoyer });
    await h.drained;
    expect(redis.popsFaits).toBeGreaterThanOrEqual(1);
  });
});

describe('arrêt gracieux', () => {
  it("après stop(), plus AUCUN nouveau job n'est tiré", async () => {
    const scrapes: ScrapeRequestJob[] = [];
    const restants = [job({ run_id: 'j1' }), job({ run_id: 'j2' }), job({ run_id: 'j3' })];
    const redis = faireRedis(restants);
    let h: WorkerHandle;

    const impl: ScraperImplementation = {
      name: 'stoppeur',
      async scrape(req) {
        scrapes.push(req);
        h.stop(); // arrêt demandé PENDANT le premier job
        return { status: 'success' };
      },
    };

    h = demarrer(redis, impl, { concurrency: 1, send: rienEnvoyer });
    await h.drained;

    expect(scrapes.map((s) => s.run_id)).toEqual(['j1']);
    expect(restants, 'j2 et j3 doivent rester disponibles pour un autre worker').toHaveLength(2);
  });

  it('le job en cours va au bout : son résultat est envoyé avant la fin du drain', async () => {
    const envoyes: ScrapeResult[] = [];
    const redis = faireRedis([job({ run_id: 'long' })]);
    let libererScrape: (() => void) | undefined;
    let h: WorkerHandle;

    const impl: ScraperImplementation = {
      name: 'lent',
      scrape() {
        h.stop(); // l'arrêt est demandé pendant que le job tourne
        return new Promise((resolve) => {
          libererScrape = () => resolve({ status: 'success', payload: { fini: true } });
        });
      },
    };

    h = demarrer(redis, impl, {
      concurrency: 1,
      send: async (r) => {
        envoyes.push(r);
      },
    });

    await respirer(20);
    let draine = false;
    void h.drained.then(() => {
      draine = true;
    });
    await respirer(20);
    expect(draine, 'le drain ne doit PAS se terminer tant que le job tourne').toBe(false);
    expect(envoyes).toHaveLength(0);

    libererScrape!();
    await h.drained;
    expect(envoyes).toHaveLength(1);
    expect(envoyes[0]).toMatchObject({ run_id: 'long', status: 'success' });
  });

  it("un job tiré ALORS QUE l'arrêt est déjà demandé est REMIS en file, pas perdu", async () => {
    // La course réelle : SIGTERM arrive entre le BRPOP et le début du scrape.
    // Sans remise en file, le job est retiré de Redis et n'est jamais traité.
    const scrapes: ScrapeRequestJob[] = [];
    const redis = faireRedis([job({ run_id: 'perdu' })]);
    const h = demarrer(redis, implQuiReussit(scrapes), { concurrency: 1, send: rienEnvoyer });
    redis.avantDeRendre = () => {
      h.stop();
    };
    await h.drained;

    expect(scrapes, "aucun scrape ne doit démarrer après la demande d'arrêt").toHaveLength(0);
    expect(redis.remisEnFile, 'le job tiré doit être remis en file').toHaveLength(1);
    expect(JSON.parse(redis.remisEnFile[0]!.valeur)).toMatchObject({ run_id: 'perdu' });
  });

  it('les ressources externes (navigateur) sont fermées APRÈS la fin du job en cours', async () => {
    const ordre: string[] = [];
    const redis = faireRedis([job({ run_id: 'x' })]);
    let h: WorkerHandle;

    const impl: ScraperImplementation = {
      name: 'ordonne',
      async scrape() {
        h.stop();
        await respirer(15);
        ordre.push('fin-du-scrape');
        return { status: 'success' };
      },
    };

    h = demarrer(redis, impl, {
      concurrency: 1,
      send: async () => {
        ordre.push('resultat-envoye');
      },
      onShutdown: () => {
        ordre.push('navigateur-ferme');
      },
    });
    await h.drained;

    expect(ordre).toEqual(['fin-du-scrape', 'resultat-envoye', 'navigateur-ferme']);
  });

  it('stop() est idempotent et drained reste résolu', async () => {
    const redis = faireRedis([]);
    const h = demarrer(redis, implQuiReussit([]), { concurrency: 1, send: rienEnvoyer });
    h.stop();
    h.stop();
    await expect(h.drained).resolves.toBeUndefined();
    await expect(h.drained).resolves.toBeUndefined();
  });

  it("le lanceur de navigateur ne pose plus de gestionnaire de signal concurrent", () => {
    // Il y en avait un, enregistré à l'import — donc AVANT celui du moteur, donc
    // gagnant. Il fermait le navigateur sous le job en cours. Si quelqu'un le
    // réintroduit, l'arrêt gracieux redevient décoratif sans qu'aucun test de
    // comportement ne bronche : d'où cette garde statique.
    const source = readFileSync(resolve(RACINE_WORKERS, 'src/browser/launcher.ts'), 'utf-8');
    const sansCommentaires = source.replace(/\/\/[^\n]*/g, '').replace(/\/\*[\s\S]*?\*\//g, '');
    expect(sansCommentaires).not.toMatch(/process\s*\.\s*on(ce)?\s*\(/);
  });

  it("whenIdle() se résout immédiatement quand aucun job n'est en cours", async () => {
    const redis = faireRedis([]);
    const h = demarrer(redis, implQuiReussit([]), { concurrency: 1, send: rienEnvoyer });
    await expect(h.whenIdle()).resolves.toBeUndefined();
    await h.drained;
  });
});
