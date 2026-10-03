import { test, expect } from '@playwright/test';

/**
 * ACCUEIL EN BLOCS (maquette validée par Will, 03/10/2026) — le build réel,
 * API simulée. Ce que ce spec prouve en navigateur, et qu'un test jsdom ne
 * peut pas prouver : les blocs s'affichent, et à 390 px de large tout
 * s'empile SANS défilement horizontal.
 */
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
          companies_total: 4_350_000,
          companies_enriched_24h: 12,
          contacts_qualified: 89,
          scraper_runs_24h: 5,
          llm_cost_eur_month: 42.5,
          quality_distribution: { complete: 100, partielle: 200, basique: 100 },
          quality_avg: 18,
          quality_a_recalculer_pct: 0,
          size_distribution: { tpe: 4_042_240, pme: 185_414, eti: 74_753, grand_groupe: 28_027 },
          companies_enriched: 826_500,
          prospects_joignables: 410_515,
          prospects_joignables_idf: 141_769,
        },
      }),
    );
    await page.route('**/api/v1/audiences', (route) =>
      route.fulfill({
        json: {
          data: [
            { id: 1, name: 'Prospects contactables', description: null, criteria: {}, is_active: true, auto_refresh: true, member_count: 410_515, refreshed_at: '2026-10-03T02:00:00Z', created_at: '2026-10-01T00:00:00Z' },
            { id: 2, name: 'Prospects contactables — Île-de-France', description: null, criteria: {}, is_active: true, auto_refresh: true, member_count: 141_769, refreshed_at: '2026-10-03T02:00:00Z', created_at: '2026-10-01T00:00:00Z' },
          ],
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

  test('les blocs de l’accueil sont affichés', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('heading', { level: 1, name: 'Bonjour A' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Ma base' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Par taille' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Mes audiences' })).toBeVisible();
  });

  test('à 390 px : tout s’empile, aucun défilement horizontal', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/');
    await expect(page.getByRole('heading', { name: 'Mes audiences' })).toBeVisible();
    const debord = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(debord).toBeLessThanOrEqual(0);
  });
});
