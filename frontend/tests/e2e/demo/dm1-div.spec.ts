/**
 * DM1-DIV — démo de la lettre et du guide, des candidats, des collectes et de
 * la carte de France. Jouée par `.github/workflows/demo.yml` sur le build de démo.
 */
import { test, expect, type Page } from '@playwright/test';
import { ORIGINE_DEMO } from '../../../playwright.demo.config';
import { controleAxe, ouvrirDemo, passage360 } from './outils';

/** Note toute requête qui sort de l'origine de la démo (il ne doit y en avoir aucune). */
function surveillerRequetesExternes(page: Page): string[] {
  const externes: string[] = [];
  page.on('request', (r) => {
    const url = r.url();
    if (url.startsWith('data:') || url.startsWith('blob:')) return;
    if (!url.startsWith(ORIGINE_DEMO) && !url.startsWith('https://demo.invalid')) externes.push(url);
  });
  return externes;
}

test.describe('collectes en démo', () => {
  test('ouvrir une collecte en cours : sa progression est visible', async ({ page }) => {
    await ouvrirDemo(page, '/collectes');
    await expect(page.getByRole('heading', { level: 1, name: 'Collectes' })).toBeVisible();
    await page.getByRole('link', { name: /Rhône — PME artisanat/ }).first().click();
    await expect(page.getByRole('heading', { level: 1, name: /Rhône — PME artisanat/ })).toBeVisible();
    await expect(page.getByText(/620/).first()).toBeVisible();
    await expect(page.getByText(/1\s?000/).first()).toBeVisible();
    await controleAxe(page);
  });

  test('mettre en pause : la pause se voit après rechargement', async ({ page }) => {
    await ouvrirDemo(page, '/collectes');
    await page.getByRole('link', { name: /Rhône — PME artisanat/ }).first().click();
    await page.getByRole('button', { name: 'Pause' }).first().click();
    await expect(page.getByText('En pause').first()).toBeVisible();
    await page.reload();
    await expect(page.getByText('En pause').first()).toBeVisible();
  });

  test('la liste montre tous les statuts, et l’état vide est propre', async ({ page }) => {
    await ouvrirDemo(page, '/collectes');
    for (const statut of ['En cours', 'En pause', 'Terminée']) {
      await expect(page.getByText(statut).first()).toBeVisible();
    }
    await ouvrirDemo(page, '/collectes', { etat: 'vide' });
    await expect(page.getByText(/Aucune collecte/).first()).toBeVisible();
    await controleAxe(page);
  });

  test('l’état d’erreur propose de réessayer, 360 px sans défilement', async ({ page }) => {
    await ouvrirDemo(page, '/collectes', { etat: 'erreur' });
    await expect(page.getByRole('button', { name: 'Réessayer' })).toBeVisible();
    await ouvrirDemo(page, '/collectes');
    await passage360(page);
  });
});

test.describe('carte de France en démo', () => {
  test('la carte s’affiche sans aucune requête hors de la démo', async ({ page }) => {
    const externes = surveillerRequetesExternes(page);
    await ouvrirDemo(page, '/coverage');
    await expect(page.getByRole('heading', { level: 1, name: 'Carte de France' })).toBeVisible();
    await expect(page.locator('canvas').first()).toBeVisible();
    await expect(page.getByText('Top zones')).toBeVisible();
    await page.waitForTimeout(800);
    expect(externes).toEqual([]);
    await controleAxe(page);
  });

  test('choisir un niveau et lancer une récupération demande confirmation', async ({ page }) => {
    await ouvrirDemo(page, '/coverage');
    await page.getByRole('button', { name: 'Régions' }).click();
    await expect(page.getByText('Top zones')).toBeVisible();
    await page.getByRole('button', { name: 'Départements' }).click();
    await passage360(page);
  });
});

test.describe('newsletter et guide en démo', () => {
  test('la liste, un filtre, puis une personne rattachée à une entreprise', async ({ page }) => {
    await ouvrirDemo(page, '/console/lettre-et-guide');
    await expect(page.getByRole('heading', { level: 1, name: 'Newsletter et guide' })).toBeVisible();
    await expect(page.getByText('Abonnés à la newsletter').first()).toBeVisible();
    await page.getByLabel('Rattachement').selectOption('non');
    await page.getByRole('link', { name: 'Hugo Marchand' }).click();
    await expect(page.getByText('h.marchand@cabinet-alpha.example').first()).toBeVisible();
  });

  test('poser une tâche : elle se voit après rechargement', async ({ page }) => {
    await ouvrirDemo(page, '/console/lettre-et-guide');
    await page.getByRole('link', { name: 'Hugo Marchand' }).click();
    await page.getByLabel(/Tâche/).first().fill('Retrouver l’entreprise de Hugo');
    await page.getByRole('button', { name: 'Poser' }).click();
    await page.reload();
    await expect(page.getByText('Retrouver l’entreprise de Hugo')).toBeVisible();
  });

  test('état vide, accessibilité et 360 px', async ({ page }) => {
    await ouvrirDemo(page, '/console/lettre-et-guide', { etat: 'vide' });
    await expect(page.getByText('Aucune personne dans ce segment')).toBeVisible();
    await ouvrirDemo(page, '/console/lettre-et-guide');
    await controleAxe(page);
    await passage360(page);
  });
});

test.describe('candidats en démo', () => {
  test('la liste, les onglets par famille et la recherche', async ({ page }) => {
    await ouvrirDemo(page, '/console/vivier');
    await expect(page.getByRole('heading', { level: 1, name: 'Candidats' })).toBeVisible();
    await expect(page.getByText('Inès Garnier')).toBeVisible();
    await page.getByRole('tab', { name: /Vidéo/ }).click();
    await expect(page.getByText('Rémi Caron')).toBeVisible();
    await expect(page.getByText('Inès Garnier')).toHaveCount(0);
  });

  test('état vide, accessibilité et 360 px', async ({ page }) => {
    await ouvrirDemo(page, '/console/vivier', { etat: 'vide' });
    await expect(page.getByText(/Aucun candidat/).first()).toBeVisible();
    await ouvrirDemo(page, '/console/vivier');
    await controleAxe(page);
    await passage360(page);
  });
});
