import { test, expect } from '@playwright/test';

test.describe('Settings page', () => {
  test.beforeEach(async ({ page }) => {
    await page.route('**/api/v1/auth/me', (route) =>
      route.fulfill({
        json: {
          user: { id: 'u1', email: 'a@b.c', name: 'A', current_workspace_id: 'w1', totp_enabled_at: null, onboarding_tour_completed_at: '2026-01-01T00:00:00Z' },
          roles: ['owner'],
        },
      }),
    );
    await page.route('**/api/v1/workspace', (route) =>
      route.fulfill({
        json: { id: 'w1', slug: 'axion-ia', name: 'Axion-IA', settings: {}, cost_cap_eur: 1000, is_active: true },
      }),
    );
  });

  test('settings page loads', async ({ page }) => {
    await page.goto('/settings');
    await expect(page).toHaveURL(/settings/);
  });

  test('settings shows page content', async ({ page }) => {
    await page.goto('/settings');
    // La page settings affiche quelque chose (heading)
    await expect(page.locator('main')).toBeVisible();
  });
});

test.describe('Users page', () => {
  test.beforeEach(async ({ page }) => {
    await page.route('**/api/v1/auth/me', (route) =>
      route.fulfill({
        json: { user: { id: 'u1', email: 'a@b.c', name: 'A', current_workspace_id: 'w1', onboarding_tour_completed_at: '2026-01-01T00:00:00Z' }, roles: ['owner'] },
      }),
    );
    await page.route('**/api/v1/users*', (route) =>
      route.fulfill({ json: { data: [{ id: 'u1', email: 'a@b.c', name: 'A' }] } }),
    );
  });

  test('users page loads', async ({ page }) => {
    await page.goto('/users');
    await expect(page).toHaveURL(/users/);
  });
});
