import { test, expect } from '@playwright/test';

test.describe('Auth flow', () => {
  test('login page displays form with email + password', async ({ page }) => {
    await page.goto('/login');
    await expect(page.getByRole('heading', { name: /connexion/i })).toBeVisible();
    await expect(page.getByLabel(/adresse e-mail/i)).toBeVisible();
    await expect(page.getByLabel(/mot de passe/i)).toBeVisible();
    await expect(page.getByRole('button', { name: /se connecter/i })).toBeVisible();
  });

  test('magic link page shows email input', async ({ page }) => {
    await page.goto('/magic-link');
    await expect(page.getByRole('heading', { name: /lien magique/i })).toBeVisible();
  });

  test('2FA page shows 6-digit input', async ({ page }) => {
    await page.goto('/2fa');
    await expect(page.getByLabel(/6-digit code/i)).toBeVisible();
  });

  test('password reset page renders', async ({ page }) => {
    await page.goto('/password-reset');
    await expect(page.getByRole('heading', { name: /réinitialiser/i })).toBeVisible();
  });
});
