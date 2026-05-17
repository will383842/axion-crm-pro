import { test, expect } from '@playwright/test';

test.describe('Dashboard', () => {
  test.beforeEach(async ({ page }) => {
    await page.route('**/api/v1/auth/me', (route) =>
      route.fulfill({
        json: { user: { id: 'u1', email: 'a@b.c', name: 'A', current_workspace_id: 'w1', onboarding_tour_completed_at: '2026-01-01T00:00:00Z' }, roles: ['owner'] },
      }),
    );
    await page.route('**/api/v1/dashboard/stats', (route) =>
      route.fulfill({
        json: {
          companies_total: 1234,
          companies_enriched_24h: 12,
          contacts_qualified: 89,
          scraper_runs_24h: 5,
          llm_cost_eur_month: 42.5,
          quality_distribution: { complete: 100, partielle: 200, basique: 100 },
          size_distribution: { artisan: 10, tpe: 100, pme: 50, eti: 20, grande_entreprise: 5 },
        },
      }),
    );
  });

  test('dashboard page loads', async ({ page }) => {
    await page.goto('/');
    await expect(page).toHaveURL('/');
  });

  test('dashboard displays main heading', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('main')).toBeVisible();
  });
});
