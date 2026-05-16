import { test, expect } from '@playwright/test';

test.describe('Coverage map', () => {
  test('coverage page renders map and mode buttons', async ({ page }) => {
    await page.goto('/coverage');
    await expect(page.getByRole('heading', { name: /couverture France/i })).toBeVisible();
    await expect(page.getByRole('button', { name: /^Visu$/ })).toBeVisible();
    await expect(page.getByRole('button', { name: /^Recherche$/ })).toBeVisible();
    await expect(page.getByRole('button', { name: /^Action$/ })).toBeVisible();
  });

  test('level switcher region/department/city', async ({ page }) => {
    await page.goto('/coverage');
    await page.getByRole('button', { name: 'region' }).click();
    await expect(page.getByRole('button', { name: 'region' })).toHaveClass(/bg-slate-900/);
  });
});
