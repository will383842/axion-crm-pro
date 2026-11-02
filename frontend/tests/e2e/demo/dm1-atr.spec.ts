/**
 * Démo de la section « À traiter » (DM1-ATR) : doublons, personnes à rattacher,
 * propositions. Tâche T2 du test de Will (fusion, refus, validation d'une
 * proposition) en moins de 3 minutes, gestes conservés après rechargement,
 * 4 états, accessibilité et téléphone.
 */
import { test, expect } from '@playwright/test';
import { controleAxe, ouvrirDemo, passage360 } from './outils';

test.describe('démo : À traiter', () => {
  test('T2 : fusionner (et annuler), écarter, valider une proposition, en moins de 3 minutes', async ({ page }) => {
    const debut = Date.now();
    page.on('dialog', (d) => void d.accept());

    await ouvrirDemo(page, '/doublons', { offre: 'les-deux' });
    await expect(page.getByRole('heading', { level: 1, name: 'Doublons à vérifier' })).toBeVisible();
    const paires = page.locator('[data-testid^="paire-"]');
    await expect(paires).toHaveCount(12);

    // Fusion, puis annulation : la paire revient.
    await page.getByRole('button', { name: 'Fusionner' }).first().click();
    await expect(paires).toHaveCount(11);
    await page.getByRole('button', { name: /Annuler la fusion/ }).click();
    await expect(paires).toHaveCount(12);

    // Fusion définitive, puis refus d'une autre paire.
    await page.getByRole('button', { name: 'Fusionner' }).first().click();
    await expect(paires).toHaveCount(11);
    await page.getByRole('button', { name: 'Ce ne sont pas des doublons' }).first().click();
    await expect(paires).toHaveCount(10);

    // Les gestes survivent au rechargement.
    await page.reload();
    await expect(page.locator('[data-testid^="paire-"]')).toHaveCount(10);

    // Proposition : accepter.
    await page.goto('/console/propositions');
    await expect(page.getByText('4 propositions à valider')).toBeVisible();
    await page.getByRole('button', { name: /^Accepter :/ }).first().click();
    await expect(page.getByText('3 propositions à valider')).toBeVisible();

    expect(Date.now() - debut).toBeLessThan(180_000);
  });

  test('personnes à rattacher : écarter avec un motif', async ({ page }) => {
    await ouvrirDemo(page, '/console/arbitrage', { offre: 'les-deux' });
    await expect(page.getByText('7 événement(s) reçus sans SIREN')).toBeVisible();
    await page.getByLabel('Motif d’écartement').first().fill('entreprise introuvable');
    await page.getByRole('button', { name: 'Écarter' }).first().click();
    await expect(page.getByText('6 événement(s) reçus sans SIREN')).toBeVisible();
  });

  test('la pastille du menu suit les gestes et ne compte que le CRM', async ({ page }) => {
    await ouvrirDemo(page, '/doublons', { offre: 'les-deux' });
    await expect(page.getByRole('img', { name: '12 doublons à vérifier' }).first()).toBeVisible();
    page.on('dialog', (d) => void d.accept());
    await page.getByRole('button', { name: 'Fusionner' }).first().click();
    await expect(page.getByRole('img', { name: '11 doublons à vérifier' }).first()).toBeVisible();
  });

  for (const chemin of ['/doublons', '/console/arbitrage', '/console/propositions']) {
    test(`${chemin} : état vide, erreur, accessibilité et 360 px`, async ({ page }) => {
      await ouvrirDemo(page, chemin, { offre: 'les-deux', etat: 'vide' });
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
      await expect(page.getByText(/^Aucun/).first()).toBeVisible();

      await ouvrirDemo(page, chemin, { offre: 'les-deux', etat: 'erreur' });
      await expect(page.getByRole('alert').first()).toBeVisible();

      await ouvrirDemo(page, chemin, { offre: 'les-deux' });
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
      await controleAxe(page);
      await passage360(page);
    });
  }
});
