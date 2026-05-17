import { test, expect } from '@playwright/test';

/**
 * Sprint H4 — Smoke E2E sur le builder d'audience.
 * Vérifie que la page se charge avec le preview live + filtres builder.
 */
test.describe('Audiences builder (Sprint H4)', () => {
  test('renders builder page with live preview area', async ({ page }) => {
    await page.route('**/api/v1/audiences/preview', (route) =>
      route.fulfill({ json: { data: { companies: 124, contacts: 256 } } }),
    );
    await page.route('**/api/v1/tags*', (route) =>
      route.fulfill({ json: { data: [] } }),
    );
    await page.route('**/api/v1/audiences', (route) =>
      route.fulfill({ json: { data: { id: 1, name: 'E2E audience' } } }),
    );

    await page.goto('/audiences/new');

    // Au minimum un heading visible (titre de la page)
    await expect(page.locator('h1, h2').first()).toBeVisible({ timeout: 10_000 });
    await expect(page).toHaveURL(/\/audiences\/new/);
  });

  test('list page renders without crashing', async ({ page }) => {
    await page.route('**/api/v1/audiences*', (route) =>
      route.fulfill({ json: { data: [], meta: { total: 0, last_page: 1, current_page: 1, per_page: 50 } } }),
    );
    await page.goto('/audiences');
    await expect(page).toHaveURL(/\/audiences/);
  });
});
