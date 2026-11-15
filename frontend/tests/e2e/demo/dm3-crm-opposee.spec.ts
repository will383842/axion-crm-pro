/**
 * DM3-OPPOSEE (G4-UI, D14) — le badge « opposée » dans les écrans CRM, en démo (faux serveur dans le navigateur).
 *
 * I1 la liste des personnes affiche le badge (la liste des contacts de l'ancien écran, `/contacts`, redirige vers le hub en
 *    démo : elle est couverte par `tests/screens/BadgeOpposee.test.tsx`) ;
 * I2 la fiche d'une personne opposée porte le badge avec l'infobulle du motif regroupé ;
 * I3 un filtre de la liste ne fait pas disparaître le badge.
 * Lecture seule : aucun envoi, aucune écriture.
 */
import { test, expect } from '@playwright/test';
import { controleAxe, ouvrirDemo } from './outils';

test.describe('adresse opposée (G4-UI)', () => {
  test('I1 · liste des personnes : le mot « opposée » est écrit', async ({ page }) => {
    await ouvrirDemo(page, '/console/lettre-et-guide');
    await expect(page.getByText('opposée').first()).toBeVisible();
    await controleAxe(page);
  });

  test('I2 · la fiche d’une personne opposée : badge et infobulle du motif regroupé', async ({ page }) => {
    await ouvrirDemo(page, '/console/lettre-et-guide');
    const ligne = page.getByRole('listitem').filter({ hasText: 'opposée' }).first();
    await ligne.getByRole('link').first().click();
    const badge = page.getByText('opposée').first();
    await expect(badge).toBeVisible();
    await expect(badge).toHaveAttribute('title', /STOP/);
  });

  test('I3 · un filtre de la liste ne fait pas disparaître le badge', async ({ page }) => {
    await ouvrirDemo(page, '/console/lettre-et-guide');
    await expect(page.getByText('opposée').first()).toBeVisible();
    await page.getByLabel('Type d’adresse').selectOption('pro');
    await expect(page.getByText('opposée').first()).toBeVisible();
  });
});
