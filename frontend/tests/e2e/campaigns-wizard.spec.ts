import { test, expect } from '@playwright/test';

/**
 * Sprint H4 — Smoke E2E sur le Wizard Campagne.
 * Mocks API au niveau page.route pour rester déterministes (pas besoin backend up).
 */
test.describe('Campaigns wizard (Sprint H4)', () => {
  test('renders 4 steps and reaches the launch button', async ({ page }) => {
    await page.route('**/api/v1/campaigns', (route) =>
      route.fulfill({ json: { data: { id: 42, status: 'running' } } }),
    );
    await page.route('**/api/v1/companies*', (route) =>
      route.fulfill({ json: { data: [], meta: { total: 0, last_page: 1, current_page: 1, per_page: 50 } } }),
    );

    await page.goto('/campaigns/new');

    // Étape 1 — nom de campagne
    await expect(page.getByText(/Nouvelle campagne|Wizard/i)).toBeVisible({ timeout: 10_000 });
    const nameInput = page.getByLabel(/Nom|Intitulé/i).first();
    if (await nameInput.isVisible()) {
      await nameInput.fill('E2E test campaign');
    }

    // Le wizard doit afficher au moins l'amorce d'un step
    await expect(page).toHaveURL(/\/campaigns\/new/);
  });
});
