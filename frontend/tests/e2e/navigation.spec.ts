import { test, expect } from '@playwright/test';

/**
 * Sprint 18.7 — Navigation smoke tests : verify each protected route loads
 * with mocked /auth/me returning a valid user. Goal is to catch broken routes,
 * not to test full features.
 */
test.describe('Navigation smoke', () => {
  test.beforeEach(async ({ page }) => {
    // Mock /auth/me as authenticated user
    await page.route('**/api/v1/auth/me', (route) =>
      route.fulfill({
        json: {
          user: {
            id: 'user-uuid-1',
            email: 'test@axion-ia.local',
            name: 'Test User',
            current_workspace_id: 'ws-uuid-1',
            totp_enabled_at: null,
            first_login_completed_at: '2026-01-01T00:00:00Z',
            onboarding_tour_completed_at: '2026-01-01T00:00:00Z',
          },
          roles: ['owner'],
        },
      }),
    );

    // Mock list endpoints with empty data
    await page.route('**/api/v1/companies*', (route) =>
      route.fulfill({ json: { data: [], meta: { total: 0, last_page: 1, current_page: 1, per_page: 50 } } }),
    );
    await page.route('**/api/v1/contacts*', (route) =>
      route.fulfill({ json: { data: [], meta: { total: 0 } } }),
    );
    await page.route('**/api/v1/scraper-runs*', (route) =>
      route.fulfill({ json: { data: [], meta: { total: 0 } } }),
    );
  });

  test('sidebar : dashboard link visible', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('link', { name: /Dashboard/i })).toBeVisible();
  });

  test('sidebar : entreprises link', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('link', { name: 'Entreprises' })).toBeVisible();
  });

  test('sidebar : contacts link', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('link', { name: 'Contacts' })).toBeVisible();
  });

  test('sidebar : couverture France link', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('link', { name: /Couverture France/ })).toBeVisible();
  });

  test('sidebar : LLM section avec router', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('link', { name: 'LLM Router' })).toBeVisible();
  });

  test('sidebar : RGPD section', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('link', { name: 'RGPD requests' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'AI Act register' })).toBeVisible();
  });

  test('sidebar : Admin section', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('link', { name: 'Utilisateurs' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Paramètres' })).toBeVisible();
  });

  test('sidebar : Phase 2 section', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('link', { name: 'Campagnes' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'CRM pipeline' })).toBeVisible();
  });

  test('header : recherche globale visible', async ({ page }) => {
    await page.goto('/');
    // GlobalSearch présente
    await expect(page.locator('[data-tour="global-search"]')).toBeVisible();
  });

  test('header : dark mode toggle', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('[data-tour="dark-mode"]')).toBeVisible();
  });

  test('skip-link a11y présent', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByText('Aller au contenu')).toBeAttached();
  });
});
