import { execFileSync } from 'node:child_process';
import { mkdtempSync, readdirSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * maplibre-gl 6 sous Vite : sans `setWorkerUrl()`, le fichier worker n'est pas
 * émis et la carte reste vide (jsdom ne le voit pas). Ce test exige, après un
 * vrai `vite build`, un fichier worker dans les assets, et l'appel dans le code.
 */
describe('maplibre-gl : worker livré par le build', () => {
  it('appelle setWorkerUrl avec le worker importé par Vite', () => {
    const src = readFileSync(resolve(__dirname, '../../src/features/coverage/FranceCoverageMap.tsx'), 'utf8');
    expect(src).toContain("from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url'");
    expect(src).toMatch(/setWorkerUrl\(\s*workerUrl\s*\)/);
  });

  it('émet un fichier *worker* dans dist/assets', () => {
    const out = mkdtempSync(join(tmpdir(), 'build-worker-'));
    try {
      execFileSync('pnpm', ['exec', 'vite', 'build', '--outDir', out, '--emptyOutDir'], {
        cwd: resolve(__dirname, '../..'),
        stdio: 'pipe',
      });
      const fichiers = readdirSync(join(out, 'assets'));
      expect(fichiers.some((f) => /worker/i.test(f))).toBe(true);
    } finally {
      rmSync(out, { recursive: true, force: true });
    }
  }, 180_000);
});
