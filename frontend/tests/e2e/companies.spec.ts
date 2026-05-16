import { test, expect } from '@playwright/test';

test.describe('Companies list', () => {
  test('renders empty state when no companies', async ({ page }) => {
    await page.route('**/api/v1/companies*', (route) =>
      route.fulfill({ json: { data: [], meta: { total: 0, last_page: 1, current_page: 1, per_page: 50 } } }),
    );
    await page.goto('/companies');
    await expect(page.getByText(/aucune entreprise/i)).toBeVisible();
  });

  test('renders table with company rows', async ({ page }) => {
    await page.route('**/api/v1/companies*', (route) =>
      route.fulfill({
        json: {
          data: [
            { id: 1, siren: '123456789', denomination: 'Acme Inc', naf: '6201Z', size_category: 'pme', quality_score: 92, city: 'Paris' },
          ],
          meta: { total: 1, last_page: 1, current_page: 1, per_page: 50 },
        },
      }),
    );
    await page.goto('/companies');
    await expect(page.getByText('Acme Inc')).toBeVisible();
    await expect(page.getByText('123456789')).toBeVisible();
    await expect(page.getByText('PME')).toBeVisible();
  });

  test('search filter updates URL params', async ({ page }) => {
    await page.route('**/api/v1/companies*', (route) =>
      route.fulfill({ json: { data: [], meta: { total: 0, last_page: 1, current_page: 1, per_page: 50 } } }),
    );
    await page.goto('/companies');
    await page.getByPlaceholder(/Rechercher/).fill('acme');
    // Wait for debounced re-fetch
    await page.waitForRequest((req) => req.url().includes('filter[denomination]=acme'));
  });
});
