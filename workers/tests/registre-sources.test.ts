import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { QUEUES } from '../src/bridge/queues';

/**
 * REGISTRE DES SOURCES — vu depuis les workers.
 *
 * Quatre listes décrivent les mêmes sources, dans trois langages, sans qu'aucun
 * outil ne les compare :
 *   1. `workers/src/main.ts`      → l'union `WorkerType` + les clés de `REGISTRY`
 *   2. `workers/src/bridge/queues.ts` → les noms de files Redis
 *   3. `backend/app/Services/Dedup/DeduplicationService.php` → `SOURCE_TTL_DAYS`
 *   4. `backend/database/seeders/ScrapingSourcesSeeder.php`  → `SOURCES` (registre de vérité)
 *
 * Une divergence ne casse RIEN au build : elle produit un worker qui écoute une
 * file que personne n'alimente, ou une source qui arrive au funnel sans TTL de
 * revalidation. C'est exactement le genre de dérive qu'on ne voit qu'en prod.
 *
 * ÉCART CONSTATÉ ET ASSUMÉ (mesuré le 2026-08-18) : 11 ⊂ 14 ⊂ 17. Les workers
 * Node ne couvrent QUE les sources scrapées ; `insee`, `bodacc`,
 * `annuaire-entreprises`, `gplaces` sont des API appelées côté PHP, et
 * `mentions-legales` / `implantations-fr-etranger` des étapes PHP/import. Les
 * inclusions sont donc l'invariant légitime — PAS l'égalité des cardinalités.
 */

const WORKERS_DIR = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const REPO_ROOT = resolve(WORKERS_DIR, '..');

/** Lecture STRICTE : un fichier absent fait ROUGIR, il ne fait pas sauter le test. */
function lire(cheminRelatifAuDepot: string): string {
  const chemin = resolve(REPO_ROOT, cheminRelatifAuDepot);
  try {
    // Normalisation CRLF → LF : sous Windows le dépôt est chargé en `\r\n`, et
    // toute expression régulière qui cherche un `\n` LITTÉRAL y est aveugle —
    // rouge en local, verte en CI Linux (ou l'inverse). On coupe le problème.
    return readFileSync(chemin, 'utf-8').replace(/\r\n/g, '\n');
  } catch {
    throw new Error(
      `Fichier introuvable : ${chemin}. Ce test compare les listes de sources Node et PHP ; ` +
        `si l'arborescence a bougé, corriger le chemin — ne pas neutraliser le test.`,
    );
  }
}

/** Clés de l'objet `REGISTRY` de main.ts (le registre en dur des workers). */
function registryDeMain(): string[] {
  const src = lire('workers/src/main.ts');
  const bloc = /const REGISTRY: Record<WorkerType, \(\) => Promise<void>> = \{([\s\S]*?)\n\};/.exec(src);
  if (!bloc?.[1]) {
    throw new Error("Bloc `const REGISTRY: Record<WorkerType, ...> = { ... };` introuvable dans workers/src/main.ts");
  }
  return [...bloc[1].matchAll(/^\s*'([a-z0-9-]+)':/gm)].map((m) => m[1]!);
}

/** Membres de l'union de type `WorkerType` de main.ts. */
function unionWorkerType(): string[] {
  const src = lire('workers/src/main.ts');
  const bloc = /type WorkerType =([\s\S]*?);\n/.exec(src);
  if (!bloc?.[1]) throw new Error('Union `type WorkerType = ...` introuvable dans workers/src/main.ts');
  return [...bloc[1].matchAll(/'([a-z0-9-]+)'/g)].map((m) => m[1]!);
}

/** Clés de `DeduplicationService::SOURCE_TTL_DAYS`. */
function sourceTtlDays(): string[] {
  const src = lire('backend/app/Services/Dedup/DeduplicationService.php');
  const bloc = /public const SOURCE_TTL_DAYS = \[([\s\S]*?)\n {4}\];/.exec(src);
  if (!bloc?.[1]) throw new Error('`public const SOURCE_TTL_DAYS = [...]` introuvable dans DeduplicationService.php');
  return [...bloc[1].matchAll(/'([a-z0-9-]+)'\s*=>\s*\d+/g)].map((m) => m[1]!);
}

/** Clés de premier niveau de `ScrapingSourcesSeeder::SOURCES` (le registre de vérité). */
function sourcesDuSeeder(): string[] {
  const src = lire('backend/database/seeders/ScrapingSourcesSeeder.php');
  const bloc = /private const SOURCES = \[([\s\S]*?)\n {4}\];/.exec(src);
  if (!bloc?.[1]) throw new Error('`private const SOURCES = [...]` introuvable dans ScrapingSourcesSeeder.php');
  // Les clés de premier niveau sont indentées de 8 espaces et suivies de `=> [`.
  return [...bloc[1].matchAll(/^ {8}'([a-z0-9-]+)' => \[$/gm)].map((m) => m[1]!);
}

describe('registre des sources — cohérence Node ↔ Node', () => {
  it("l'union WorkerType et les clés de REGISTRY décrivent le même ensemble", () => {
    const union = unionWorkerType();
    const registry = registryDeMain();
    expect(union.length).toBeGreaterThan(0);
    expect([...registry].sort()).toEqual([...union].sort());
  });

  it('chaque WORKER_TYPE a une file Redis dans QUEUES, et réciproquement', () => {
    const types = registryDeMain();
    const files = Object.values(QUEUES);

    // Sens 1 : aucun worker ne peut démarrer sans file.
    for (const t of types) {
      expect(files, `WORKER_TYPE « ${t} » n'a pas de file dans QUEUES`).toContain(`axion:scrape:${t}`);
    }
    // Sens 2 : aucune file orpheline (Laravel pousserait dans le vide).
    for (const f of files) {
      const t = f.replace('axion:scrape:', '');
      expect(types, `La file « ${f} » n'a aucun WORKER_TYPE pour la consommer`).toContain(t);
    }
    expect(files).toHaveLength(types.length);
  });

  it('les noms de file suivent tous le préfixe du contrat de pont', () => {
    for (const f of Object.values(QUEUES)) {
      expect(f).toMatch(/^axion:scrape:[a-z0-9-]+$/);
    }
  });
});

describe('registre des sources — cohérence Node ↔ backend Laravel', () => {
  it('chaque WORKER_TYPE existe dans DeduplicationService::SOURCE_TTL_DAYS', () => {
    const ttl = sourceTtlDays();
    expect(ttl.length).toBeGreaterThan(0);
    for (const t of registryDeMain()) {
      expect(ttl, `Source « ${t} » scrapée par un worker mais SANS TTL de revalidation côté PHP`).toContain(t);
    }
  });

  it('chaque WORKER_TYPE existe dans le registre de vérité ScrapingSourcesSeeder::SOURCES', () => {
    const seeder = sourcesDuSeeder();
    expect(seeder.length).toBeGreaterThan(0);
    for (const t of registryDeMain()) {
      expect(seeder, `Source « ${t} » scrapée par un worker mais ABSENTE du registre des sources`).toContain(t);
    }
  });

  it('tout TTL de revalidation porte sur une source réellement déclarée au registre', () => {
    const seeder = sourcesDuSeeder();
    for (const s of sourceTtlDays()) {
      expect(seeder, `TTL déclaré pour « ${s} », qui n'existe dans aucune source du registre`).toContain(s);
    }
  });

  it("l'écart de cardinalité constaté reste EXPLIQUÉ (11 workers ⊂ 14 TTL ⊂ 17 sources)", () => {
    const types = registryDeMain();
    const ttl = sourceTtlDays();
    const seeder = sourcesDuSeeder();

    // Sources déclarées mais NON scrapées par un worker Node : elles doivent
    // toutes être d'un `kind` qui ne passe pas par Node (api / import).
    const src = lire('backend/database/seeders/ScrapingSourcesSeeder.php');
    const horsNode = seeder.filter((s) => !types.includes(s));
    for (const s of horsNode) {
      const entree = new RegExp(`'${s}' => \\[[\\s\\S]*?'kind' => '([a-z_]+)'`).exec(src);
      expect(entree?.[1], `Source « ${s} » : 'kind' illisible dans le seeder`).toBeDefined();
      expect(
        ['api', 'import', 'http_scrape'],
        `Source « ${s} » est de kind « ${entree?.[1]} » : si elle doit être scrapée par un navigateur, ` +
          `il lui manque un worker Node et une file Redis.`,
      ).toContain(entree?.[1]);
      expect(
        entree?.[1],
        `Source « ${s} » est de kind « browser_scrape » mais n'a AUCUN worker Node — elle ne sera jamais collectée.`,
      ).not.toBe('browser_scrape');
    }

    // Le rapport d'inclusion lui-même : Node ⊆ TTL ⊆ seeder.
    expect(types.every((t) => ttl.includes(t))).toBe(true);
    expect(ttl.every((t) => seeder.includes(t))).toBe(true);
  });
});
