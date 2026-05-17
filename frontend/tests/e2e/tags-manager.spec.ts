import { test, expect } from '@playwright/test';

/**
 * Sprint H4 — Smoke E2E sur le gestionnaire de tags.
 * Affiche la liste groupée par catégorie + amorce création nouveau tag.
 */
test.describe('Tags manager (Sprint H4)', () => {
  test('renders tags list grouped by category', async ({ page }) => {
    await page.route('**/api/v1/tags*', (route) =>
      route.fulfill({
        json: {
          data: [
            { id: 1, slug: 'sector-it-saas', name: 'Secteur : it saas', category: 'sector', kind: 'auto', color: 'violet' },
            { id: 2, slug: 'dept-75',         name: 'Département 75',     category: 'geo',    kind: 'auto', color: 'sky' },
            { id: 3, slug: 'custom-vip',      name: 'VIP',                category: 'custom', kind: 'manual', color: 'emerald' },
          ],
        },
      }),
    );

    await page.goto('/tags');

    await expect(page).toHaveURL(/\/tags/);
    // Au minimum un tag rendu (smoke = page se charge + affiche au moins une catégorie)
    await expect(page.getByText(/sect|géo|custom/i).first()).toBeVisible({ timeout: 10_000 });
  });
});
