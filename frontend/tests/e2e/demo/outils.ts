/**
 * Aides des e2e de démo (DM0-DEMO) — à UTILISER par chaque lot d'écran.
 *
 *   await ouvrirDemo(page, '/envoi/campagnes', { offre: 'envoi' });
 *   await controleAxe(page);       // 0 violation critique
 *   await passage360(page);        // aucun défilement horizontal à 360 px
 *
 * `ouvrirDemo` ouvre la page déjà connecté (sauf `connecte: false`), scénarios
 * rejoués COUPÉS (`?demo-scenarios=non`) pour que rien ne bouge pendant un test.
 */
import { expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { ORIGINE_DEMO } from '../../../playwright.demo.config';

export type OffreDemo = 'les-deux' | 'crm' | 'envoi';
export type EtatDemo = 'vide' | 'erreur' | 'chargement';

export interface OptionsDemo {
  offre?: OffreDemo;
  etat?: EtatDemo;
  /** Connecté d'office (défaut : oui). `false` : on arrive en visiteur. */
  connecte?: boolean;
  /** Scénarios rejoués (défaut : non, pour des tests stables). */
  scenarios?: boolean;
}

/** L'adresse complète d'une page de démo, avec ses réglages en paramètres. */
export function adresseDemo(chemin: string, options: OptionsDemo = {}): string {
  const url = new URL(chemin, ORIGINE_DEMO);
  url.searchParams.set('demo-scenarios', options.scenarios === true ? 'oui' : 'non');
  if (options.offre !== undefined) url.searchParams.set('demo-offre', options.offre);
  if (options.etat !== undefined) url.searchParams.set('demo-etat', options.etat);
  if (options.connecte !== false) url.searchParams.set('demo-session', 'connecte');
  return `${url.pathname}${url.search}`;
}

/** Ouvre une page de la démo et attend que le faux serveur et le bandeau soient prêts. */
export async function ouvrirDemo(page: Page, chemin: string, options: OptionsDemo = {}): Promise<void> {
  await page.goto(adresseDemo(chemin, options));
  await expect(page.getByRole('region', { name: 'Démonstration' })).toBeVisible({ timeout: 15_000 });
}

/** Contrôle axe : aucune violation CRITIQUE sur la page affichée. */
export async function controleAxe(page: Page): Promise<void> {
  // Pas d'analyse pendant le squelette de chargement : on attend les données.
  await expect(page.locator('[aria-busy="true"]')).toHaveCount(0);
  await expect(page.locator('[role="status"][aria-label^="Chargement"]')).toHaveCount(0);
  const resultat = await new AxeBuilder({ page }).analyze();
  const critiques = resultat.violations.filter((v) => v.impact === 'critical');
  expect(critiques.map((v) => `${v.id} : ${v.help}`)).toEqual([]);
}

/** Passage à 360 px : la page ne défile pas horizontalement. */
export async function passage360(page: Page): Promise<void> {
  await page.setViewportSize({ width: 360, height: 780 });
  await page.waitForTimeout(150);
  const deborde = await page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
  );
  expect(deborde).toBeLessThanOrEqual(0);
}

/** Appelle le faux serveur depuis la page (la requête est servie par MSW, rien ne sort). */
export async function appelerApi<T>(page: Page, methode: 'GET' | 'POST', chemin: string): Promise<T> {
  return page.evaluate(
    async ({ m, c }) => {
      const r = await fetch(`https://demo.invalid/api/v1${c}`, { method: m, credentials: 'include' });
      return (await r.json()) as T;
    },
    { m: methode, c: chemin },
  );
}
