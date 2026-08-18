import { test, expect } from '@playwright/test';

test.describe('LLM pages', () => {
  test.beforeEach(async ({ page }) => {
    await page.route('**/api/v1/auth/me', (route) =>
      route.fulfill({
        json: { user: { id: 'u1', email: 'a@b.c', name: 'A', current_workspace_id: 'w1', onboarding_tour_completed_at: '2026-01-01T00:00:00Z' }, roles: ['owner'] },
      }),
    );
    await page.route('**/api/v1/llm/use-cases*', (route) =>
      route.fulfill({ json: { data: [] } }),
    );
    await page.route('**/api/v1/llm/usage*', (route) =>
      route.fulfill({ json: { data: [] } }),
    );
    await page.route('**/api/v1/proxy-providers*', (route) =>
      route.fulfill({ json: { data: [] } }),
    );
    await page.route('**/api/v1/rotations*', (route) =>
      route.fulfill({ json: { data: [] } }),
    );
  });

  test('LLM router page loads', async ({ page }) => {
    await page.goto('/llm/router');
    await expect(page).toHaveURL(/router/);
  });

  test('LLM proxy providers page loads', async ({ page }) => {
    await page.goto('/llm/proxy-providers');
    await expect(page).toHaveURL(/proxy-providers/);
  });

  test('LLM rotations page loads', async ({ page }) => {
    await page.goto('/llm/rotations');
    await expect(page).toHaveURL(/rotations/);
  });
});

test.describe('Audit logs page', () => {
  test.beforeEach(async ({ page }) => {
    await page.route('**/api/v1/auth/me', (route) =>
      route.fulfill({
        json: { user: { id: 'u1', email: 'a@b.c', name: 'A', current_workspace_id: 'w1', onboarding_tour_completed_at: '2026-01-01T00:00:00Z' }, roles: ['owner'] },
      }),
    );
    await page.route('**/api/v1/audit-logs*', (route) =>
      route.fulfill({ json: { data: [] } }),
    );
  });

  test('audit logs page loads', async ({ page }) => {
    await page.goto('/audit-logs');
    await expect(page).toHaveURL(/audit-logs/);
  });
});

test.describe('Phase 2 pages (stubs)', () => {
  test.beforeEach(async ({ page }) => {
    await page.route('**/api/v1/auth/me', (route) =>
      route.fulfill({
        json: { user: { id: 'u1', email: 'a@b.c', name: 'A', current_workspace_id: 'w1', onboarding_tour_completed_at: '2026-01-01T00:00:00Z' }, roles: ['owner'] },
      }),
    );
  });

  test('campaigns page loads', async ({ page }) => {
    await page.goto('/campaigns');
    await expect(page).toHaveURL(/campaigns/);
  });

  test('cold-email page loads', async ({ page }) => {
    await page.goto('/cold-email');
    await expect(page).toHaveURL(/cold-email/);
  });

  test('linkedin page loads', async ({ page }) => {
    await page.goto('/linkedin');
    await expect(page).toHaveURL(/linkedin/);
  });

  // F7 (étape 0) — les écrans bouchons `/crm` et `/analytics` ont été
  // SUPPRIMÉS : leurs noms sont ceux du chantier CRM cible. Les deux cas qui
  // vérifiaient qu'ils « chargeaient » testaient un comportement retiré
  // volontairement. On vérifie désormais l'inverse : ces deux adresses ne
  // servent plus d'écran bouchon, elles tombent sur le 404 applicatif.
  test('/crm ne sert plus d’écran bouchon', async ({ page }) => {
    await page.goto('/crm');
    await expect(page.getByText(/CRM pipeline/i)).toHaveCount(0);
  });

  test('/analytics ne sert plus d’écran bouchon', async ({ page }) => {
    await page.goto('/analytics');
    await expect(page.getByText(/^Analytics$/)).toHaveCount(0);
  });
});
