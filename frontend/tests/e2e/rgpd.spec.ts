import { test, expect } from '@playwright/test';

test.describe('RGPD', () => {
  test('rgpd requests page renders', async ({ page }) => {
    await page.route('**/api/v1/rgpd/requests*', (route) =>
      route.fulfill({ json: { data: [], total: 0 } }),
    );
    await page.goto('/rgpd/requests');
    await expect(page.getByRole('heading', { name: /requêtes RGPD/i })).toBeVisible();
  });

  test('ai-act register page renders', async ({ page }) => {
    await page.route('**/api/v1/ai-act/register*', (route) =>
      route.fulfill({ json: { data: [] } }),
    );
    await page.goto('/rgpd/ai-act');
    await expect(page.getByRole('heading', { name: /AI Act/i })).toBeVisible();
  });

  test('audit logs page renders', async ({ page }) => {
    await page.route('**/api/v1/audit-logs*', (route) =>
      route.fulfill({ json: { data: [] } }),
    );
    await page.goto('/audit-logs');
    await expect(page.getByRole('heading', { name: /Journaux d.audit/i })).toBeVisible();
  });
});
