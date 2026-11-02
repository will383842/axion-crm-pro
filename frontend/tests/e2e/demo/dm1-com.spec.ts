/**
 * E2E de démo — DM1-COM : écrans communs, connexion par code e-mail, accès par produit.
 */
import { test, expect } from '@playwright/test';
import { controleAxe, ouvrirDemo, passage360 } from './outils';

test('connexion de démo avec le code affiché, de bout en bout', async ({ page }) => {
  await ouvrirDemo(page, '/login', { connecte: false });
  await page.getByLabel('Adresse e-mail').fill('demo@example.test');
  await page.getByLabel('Mot de passe', { exact: true }).fill('demo');
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByLabel('Code à 6 chiffres')).toBeVisible();
  const code = (await page.getByTestId('code-demo').textContent())?.trim() ?? '';
  await page.getByLabel('Code à 6 chiffres').fill(code);
  await page.getByRole('button', { name: /Vérifier le code/ }).click();
  await expect(page).toHaveURL(/\/$|\/crm|\/envoi/);
});

test('un accès retiré à une personne se voit après rechargement', async ({ page }) => {
  await ouvrirDemo(page, '/users');
  const caseEnvoi = page.getByRole('checkbox', { name: 'Accès à Envoi pour Sophie Mercier' });
  await expect(caseEnvoi).toBeChecked();
  await caseEnvoi.click();
  await expect(caseEnvoi).not.toBeChecked();
  await page.reload();
  await expect(page.getByRole('checkbox', { name: 'Accès à Envoi pour Sophie Mercier' })).not.toBeChecked();
  await controleAxe(page);
  await passage360(page);
});

test('Mon compte, Paramètres, RGPD, Journal et page introuvable sont propres', async ({ page }) => {
  for (const chemin of ['/compte', '/settings', '/rgpd/requests', '/audit-logs', '/page-qui-n-existe-pas']) {
    await ouvrirDemo(page, chemin);
    await controleAxe(page);
    await passage360(page);
  }
});
