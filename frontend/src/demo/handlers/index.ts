/**
 * Agrégateur des handlers de la démo — NE PAS MODIFIER pour ajouter un écran.
 *
 * Chaque lot dépose `handlers/<produit>/<zone>.handlers.ts` qui exporte
 * `export const handlers: HttpHandler[]` ; ce fichier les ramasse tout seul
 * (`import.meta.glob`, chargement immédiat). Un fichier sans export
 * `handlers`, ou une route déclarée deux fois, fait échouer le rassemblement
 * (et `tests/lib/demo-handlers-charges.test.ts`).
 *
 * Le MÊME tableau sert au faux serveur du navigateur (`demarrer.ts`), aux
 * tests Vitest (`renderScreen(..., { handlers })`) et aux e2e de démo.
 */
import type { HttpHandler } from 'msw';

/** Clé d'une route : « MÉTHODE motif ». */
export function cleRoute(h: HttpHandler): string {
  return `${String(h.info.method)} ${String(h.info.path)}`;
}

export function rassemblerHandlers(modules: Record<string, Record<string, unknown>>): HttpHandler[] {
  const tous: HttpHandler[] = [];
  const vues = new Map<string, string>();
  for (const fichier of Object.keys(modules).sort()) {
    const liste = modules[fichier]?.['handlers'];
    if (!Array.isArray(liste) || liste.length === 0) {
      throw new Error(`${fichier} : « export const handlers: HttpHandler[] » manquant ou vide.`);
    }
    for (const h of liste as HttpHandler[]) {
      const cle = cleRoute(h);
      const deja = vues.get(cle);
      if (deja !== undefined) {
        throw new Error(`Route déclarée deux fois : ${cle} (${deja} et ${fichier}).`);
      }
      vues.set(cle, fichier);
      tous.push(h);
    }
  }
  return tous;
}

const modules = import.meta.glob<Record<string, unknown>>('./**/*.handlers.ts', { eager: true });

export const handlers: HttpHandler[] = rassemblerHandlers(modules);
