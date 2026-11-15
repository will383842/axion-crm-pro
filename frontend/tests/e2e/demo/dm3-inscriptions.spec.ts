/** DM3 — Inscriptions (K14-ECRAN) : cinq services lus du feu vert, lecture seule. Jouée par `demo.yml`. */
import { test, expect } from '@playwright/test';
import { controleAxe, ouvrirDemo, passage360 } from './outils';

test.describe('inscriptions (démo)', () => {
  test('G1 : cinq services, état lu du feu vert', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/sante/inscriptions', { offre: 'les-deux' });
    await expect(page.getByRole('heading', { level: 1, name: 'Inscriptions' })).toBeVisible();
    await expect(page.getByRole('heading', { level: 3 })).toHaveCount(5);
    await expect(page.getByRole('listitem').filter({ hasText: 'Signal Spam' })).toContainText('Fait');
    await expect(page.getByRole('listitem').filter({ hasText: 'Google Postmaster Tools' })).toContainText('À faire');
    await controleAxe(page);
    await passage360(page);
  });

  test('G2 : aucun bouton d’écriture', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/sante/inscriptions', { offre: 'les-deux' });
    await expect(page.getByRole('heading', { level: 3 })).toHaveCount(5);
    await expect(page.getByRole('main').getByRole('button')).toHaveCount(0);
    await expect(page.getByRole('main').getByRole('textbox')).toHaveCount(0);
  });

  test('G3 : l’onglet « Inscriptions » est atteignable depuis Santé', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/sante', { offre: 'les-deux' });
    await page.getByRole('link', { name: 'Inscriptions', exact: true }).click();
    await expect(page).toHaveURL(/\/envoi\/sante\/inscriptions/);
    await expect(page.getByRole('heading', { level: 3 })).toHaveCount(5);
  });
});
