import { test, expect } from '@playwright/test';

test.describe('Global search ⌘K', () => {
  test.beforeEach(async ({ page }) => {
    await page.route('**/api/v1/auth/me', (route) =>
      route.fulfill({
        json: {
          user: { id: 'u1', email: 'a@b.c', name: 'A', current_workspace_id: 'w1', onboarding_tour_completed_at: '2026-01-01T00:00:00Z' },
          roles: ['owner'],
        },
      }),
    );
    await page.route('**/api/v1/search*', (route) =>
      route.fulfill({
        json: {
          companies: [{ id: 1, siren: '111111111', denomination: 'Acme Search Result' }],
          contacts: [],
          tags: [{ id: 1, slug: 'urgent', name: 'Urgent' }],
        },
      }),
    );
  });

  test('Cmd+K opens the search palette', async ({ page }) => {
    await page.goto('/');
    await page.keyboard.press('ControlOrMeta+k');
    // CMDK lib uses [cmdk-input]
    await expect(page.locator('[cmdk-input]')).toBeVisible({ timeout: 5000 });
  });

  test('Escape closes the search palette', async ({ page }) => {
    await page.goto('/');
    await page.keyboard.press('ControlOrMeta+k');
    await page.keyboard.press('Escape');
    await expect(page.locator('[cmdk-input]')).not.toBeVisible();
  });

  test('Typing 2+ chars triggers search', async ({ page }) => {
    await page.goto('/');
    await page.keyboard.press('ControlOrMeta+k');
    await page.locator('[cmdk-input]').fill('acme');
    await expect(page.getByText('Acme Search Result')).toBeVisible({ timeout: 5000 });
  });

  test('Less than 2 chars does not trigger search', async ({ page }) => {
    let requested = false;
    await page.route('**/api/v1/search*', (route) => {
      requested = true;
      route.fulfill({ json: { companies: [], contacts: [], tags: [] } });
    });
    await page.goto('/');
    await page.keyboard.press('ControlOrMeta+k');
    await page.locator('[cmdk-input]').fill('a');
    await page.waitForTimeout(500);
    expect(requested).toBeFalsy();
  });
});
