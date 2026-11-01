/**
 * GARDE — les handlers de la démo sont chargés tout seuls, et sans doublon.
 *
 * Chaque lot d'écran dépose SON fichier `src/demo/handlers/<produit>/<zone>.handlers.ts`
 * sans toucher `handlers/index.ts` (qui les ramasse par `import.meta.glob`).
 * Deux pièges, tenus ici :
 *  · un fichier qui oublie `export const handlers` serait ignoré en silence ;
 *  · deux lots qui déclarent la même route : MSW prend la première, l'autre
 *    écran affiche des données qui ne sont pas les siennes.
 */
import { describe, it, expect } from 'vitest';
import type { HttpHandler } from 'msw';
import { handlers, rassemblerHandlers, cleRoute } from '@/demo/handlers';

const modules = import.meta.glob<Record<string, unknown>>('/src/demo/handlers/**/*.handlers.ts', { eager: true });

describe('handlers de démo — chargement', () => {
  it('trouve les fichiers du socle', () => {
    const noms = Object.keys(modules);
    expect(noms.length).toBeGreaterThanOrEqual(4);
    expect(noms.some((n) => n.includes('/handlers/socle/'))).toBe(true);
  });

  it('chaque fichier *.handlers.ts exporte un tableau `handlers` non vide', () => {
    const fautifs = Object.entries(modules)
      .filter(([, m]) => !Array.isArray(m['handlers']) || (m['handlers'] as unknown[]).length === 0)
      .map(([n]) => n);
    expect(fautifs).toEqual([]);
  });

  it('l’agrégateur sert tous les handlers de tous les fichiers', () => {
    const total = Object.values(modules).reduce((n, m) => n + (m['handlers'] as unknown[]).length, 0);
    expect(handlers.length).toBe(total);
  });

  it('aucune route n’est déclarée deux fois', () => {
    const vues = new Map<string, number>();
    for (const h of handlers) vues.set(cleRoute(h), (vues.get(cleRoute(h)) ?? 0) + 1);
    expect([...vues].filter(([, n]) => n > 1).map(([cle]) => cle)).toEqual([]);
  });

  it('sert les routes du socle', () => {
    const cles = handlers.map(cleRoute);
    for (const attendue of [
      'GET */sanctum/csrf-cookie',
      'GET */api/v1/auth/me',
      'POST */api/v1/auth/login',
      'POST */api/v1/auth/2fa/verify',
      'GET */api/v1/config/features',
      'GET */api/v1/notifications',
      'GET */api/v1/envoi/etat',
      'POST */api/v1/envoi/tout-arreter',
      'POST */api/v1/envoi/reprendre',
      'GET */api/v1/envoi/compteurs',
    ]) {
      expect(cles, attendue).toContain(attendue);
    }
  });
});

describe('handlers de démo — TÉMOIN', () => {
  it('un fichier sans export `handlers` fait échouer le rassemblement', () => {
    expect(() =>
      rassemblerHandlers({ '/src/demo/handlers/crm/oubli.handlers.ts': { autreChose: [] } }),
    ).toThrow(/oubli\.handlers\.ts/);
  });

  it('une route déclarée deux fois fait échouer le rassemblement', () => {
    const [un] = handlers as [HttpHandler];
    expect(() =>
      rassemblerHandlers({
        '/src/demo/handlers/crm/a.handlers.ts': { handlers: [un] },
        '/src/demo/handlers/crm/b.handlers.ts': { handlers: [un] },
      }),
    ).toThrow(/deux fois/);
  });
});
