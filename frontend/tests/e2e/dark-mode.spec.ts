import { test, expect } from '@playwright/test';

test.describe('Dark mode toggle', () => {
  test.beforeEach(async ({ page }) => {
    await page.route('**/api/v1/auth/me', (route) =>
      route.fulfill({
        json: {
          user: { id: 'u1', email: 'a@b.c', name: 'A', current_workspace_id: 'w1', onboarding_tour_completed_at: '2026-01-01T00:00:00Z' },
          roles: ['owner'],
        },
      }),
    );
  });

  test('default theme is system (system aria-pressed)', async ({ page }) => {
    await page.goto('/');
    const systemBtn = page.getByRole('button', { name: /Theme system/i });
    await expect(systemBtn).toHaveAttribute('aria-pressed', 'true');
  });

  test('click on dark switches html.dark class', async ({ page }) => {
    await page.goto('/');
    await page.getByRole('button', { name: /Theme dark/i }).click();
    await expect(page.locator('html')).toHaveClass(/dark/);
  });

  test('click on light removes html.dark class', async ({ page }) => {
    await page.goto('/');
    await page.getByRole('button', { name: /Theme dark/i }).click();
    await page.getByRole('button', { name: /Theme light/i }).click();
    await expect(page.locator('html')).not.toHaveClass(/dark/);
  });

  test('theme persists in localStorage', async ({ page }) => {
    await page.goto('/');
    await page.getByRole('button', { name: /Theme dark/i }).click();
    const stored = await page.evaluate(() => localStorage.getItem('axion-theme'));
    expect(stored).toBe('dark');
  });
});
