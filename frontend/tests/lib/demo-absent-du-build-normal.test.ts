/**
 * GARDE — le build NORMAL ne contient aucun octet de la démo (DM0-DEMO).
 *
 * Chaque fusion sur `main` déploie le CRM réel (plan 30 § 5, risque 1). Si le
 * faux serveur ou le jeu factice entrait dans ce bundle, le CRM de production
 * embarquerait un intercepteur de requêtes et des données inventées. La démo
 * n'entre que par un import DYNAMIQUE gardé par `import.meta.env.VITE_DEMO`,
 * et `public/mockServiceWorker.js` est retiré de la sortie hors du mode `demo`.
 *
 * Ce test CONSTRUIT pour de vrai (`vite build`, mode production) dans un dossier
 * temporaire, puis cherche les traces dans tous les fichiers produits, cartes
 * de source comprises (elles portent le code d'origine en clair : un module de
 * démo embarqué s'y lirait même minifié).
 */
import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import { mkdtempSync, readdirSync, readFileSync, rmSync, statSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';

const ICI = path.dirname(fileURLToPath(import.meta.url));
const RACINE_FRONT = path.resolve(ICI, '..', '..');

export const TRACES_DEMO = ['mockServiceWorker', 'demoDb', 'BandeauDemo', 'src/demo/'];

function lister(dossier: string, sortie: string[] = []): string[] {
  for (const entree of readdirSync(dossier)) {
    const complet = path.join(dossier, entree);
    if (statSync(complet).isDirectory()) lister(complet, sortie);
    else sortie.push(complet);
  }
  return sortie;
}

export function tracesDans(dossier: string): string[] {
  const trouvees: string[] = [];
  for (const f of lister(dossier)) {
    const nom = path.relative(dossier, f);
    if (TRACES_DEMO.some((t) => nom.includes(t))) trouvees.push(`fichier ${nom}`);
    if (!/\.(js|mjs|css|html|map|json|txt)$/.test(f)) continue;
    const contenu = readFileSync(f, 'utf8');
    for (const t of TRACES_DEMO) if (contenu.includes(t)) trouvees.push(`${nom} contient « ${t} »`);
  }
  return trouvees;
}

let sortie = '';

beforeAll(() => {
  sortie = mkdtempSync(path.join(tmpdir(), 'build-normal-'));
  // Build dans un PROCESSUS À PART : esbuild refuse de tourner sous jsdom
  // (environnement de toute la suite, `tests/setup.ts`).
  const paquet = createRequire(import.meta.url).resolve('vite/package.json');
  const vite = path.join(path.dirname(paquet), 'bin', 'vite.js');
  const env = { ...process.env };
  delete env['VITE_DEMO'];
  delete env['VITE_API_BASE_URL'];
  execFileSync(process.execPath, [vite, 'build', '--mode', 'production', '--outDir', sortie, '--emptyOutDir', '--logLevel', 'error'], {
    cwd: RACINE_FRONT,
    env,
    stdio: 'pipe',
  });
}, 240_000);

afterAll(() => {
  if (sortie !== '') rmSync(sortie, { recursive: true, force: true });
});

describe('build normal — la démo est absente', () => {
  it('le build a bien produit une application', () => {
    const fichiers = lister(sortie);
    expect(fichiers.some((f) => f.endsWith('index.html'))).toBe(true);
    expect(fichiers.filter((f) => f.endsWith('.js')).length).toBeGreaterThanOrEqual(3);
    // Les cartes de source existent : sans elles, la recherche de noms ne vaudrait rien.
    expect(fichiers.some((f) => f.endsWith('.js.map'))).toBe(true);
  });

  it('ni mockServiceWorker, ni demoDb, ni BandeauDemo, ni src/demo/', () => {
    expect(tracesDans(sortie)).toEqual([]);
  });

  it('TÉMOIN : les noms cherchés existent bien dans le code de démo', () => {
    const base = readFileSync(path.join(RACINE_FRONT, 'src', 'demo', 'base.ts'), 'utf8');
    const bandeau = readFileSync(path.join(RACINE_FRONT, 'src', 'demo', 'BandeauDemo.tsx'), 'utf8');
    expect(base).toContain('export const demoDb');
    expect(bandeau).toContain('export function BandeauDemo');
    expect(lister(path.join(RACINE_FRONT, 'public')).some((f) => f.endsWith('mockServiceWorker.js'))).toBe(true);
  });
});
