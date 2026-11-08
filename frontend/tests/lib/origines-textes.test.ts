/** PR-C — textes des écrans neufs : interdits de communication et règle de fer (aucune suppression). */
import { describe, it, expect } from 'vitest';
import { readdirSync, readFileSync, existsSync } from 'node:fs';
import path from 'node:path';

const SRC = path.resolve(__dirname, '../../src');
const FICHIERS = [
  ...(existsSync(path.join(SRC, 'features/origines')) ? readdirSync(path.join(SRC, 'features/origines')).map((f) => `features/origines/${f}`) : []),
  'features/rgpd/EffacementsDemandesPage.tsx',
  'demo/donnees/crm/alimenter.ts',
  'demo/handlers/crm/alimenter.handlers.ts',
];
const lire = () => FICHIERS.map((f) => [f, existsSync(path.join(SRC, f)) ? readFileSync(path.join(SRC, f), 'utf8') : null] as const);

describe('textes des écrans origines / effacements', () => {
  it('les fichiers existent', () => {
    expect(lire().filter(([, t]) => t === null).map(([f]) => f)).toEqual([]);
  });
  it('« Tour France IA » (jamais « Tour de France IA »), pas de Grenoble, pas de « Aucun gain garanti »', () => {
    for (const [f, t] of lire()) {
      if (t === null) continue;
      expect(t, f).not.toMatch(/Tour de France IA/i);
      expect(t, f).not.toMatch(/grenoble|aucun gain garanti/i);
    }
  });
  it('aucun prix', () => {
    for (const [f, t] of lire()) if (t !== null) expect(t, f).not.toMatch(/\d\s?(€|euros?)|\bEUR\b/i);
  });
  it('aucune suppression : pas de DELETE, pas de « Supprimer »', () => {
    for (const [f, t] of lire()) {
      if (t === null || f.startsWith('demo/')) continue;
      expect(t, f).not.toMatch(/api\.delete|method:\s*'DELETE'|Supprimer/);
    }
  });
  it('aucune adresse réelle dans les écrans : seuls les domaines .invalid', () => {
    for (const [f, t] of lire()) {
      if (t === null || f.startsWith('demo/')) continue;
      for (const m of t.matchAll(/[\w.•-]+@([\w.•-]+)/g)) expect(m[1], f).toMatch(/\.invalid$|^•+/);
    }
  });
});
