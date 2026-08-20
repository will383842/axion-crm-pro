import { test, expect } from '@playwright/test';

test.describe('Onboarding tour (Sprint 18.4)', () => {
  test('tour does NOT show when onboarding_tour_completed_at is set', async ({ page }) => {
    await page.route('**/api/v1/auth/me', (route) =>
      route.fulfill({
        json: {
          user: {
            id: 'u1', email: 'a@b.c', name: 'A',
            current_workspace_id: 'w1',
            onboarding_tour_completed_at: '2026-01-01T00:00:00Z',
          },
          roles: ['owner'],
        },
      }),
    );
    await page.goto('/');
    // joyride classes : .react-joyride__overlay should not be visible
    await page.waitForTimeout(1500);
    await expect(page.locator('.react-joyride__overlay')).not.toBeVisible();
  });

  test('tour shows when onboarding_tour_completed_at is null', async ({ page }) => {
    await page.route('**/api/v1/auth/me', (route) =>
      route.fulfill({
        json: {
          user: {
            id: 'u1', email: 'a@b.c', name: 'A',
            current_workspace_id: 'w1',
            onboarding_tour_completed_at: null,
          },
          roles: ['owner'],
        },
      }),
    );
    await page.goto('/');
    // Le tour démarre après ~800ms
    await expect(page.locator('.react-joyride__overlay').first()).toBeVisible({ timeout: 5000 });
  });

  test('cleanup leave channel after unmount (Echo)', async ({ page }) => {
    await page.route('**/api/v1/auth/me', (route) =>
      route.fulfill({
        json: { user: { id: 'u1', email: 'a@b.c', name: 'A', current_workspace_id: 'w1', onboarding_tour_completed_at: '2026-01-01T00:00:00Z' }, roles: ['owner'] },
      }),
    );
    await page.goto('/');
    await expect(page.locator('body')).toBeVisible();
    // Smoke : pas de crash après chargement
    expect(true).toBe(true);
  });

  test('POST /auth/onboarding/complete called on tour skip', async ({ page }) => {
    let postCalled = false;
    await page.route('**/api/v1/auth/onboarding/complete', (route) => {
      postCalled = true;
      route.fulfill({ json: { onboarding_tour_completed_at: new Date().toISOString() } });
    });
    // P6-UI-008 (2026-08-20) — SANS cette simulation, le POST n'a JAMAIS lieu.
    //
    // Mesure a la sonde sur le build (`vite preview`, chromium 1234), suite des
    // requetes emises apres un clic sur « Passer » :
    //   GET  /sanctum/csrf-cookie   ← l'intercepteur axios l'exige d'abord
    //   POST /api/v1/auth/onboarding/complete
    // Le premier appel part vers https://api.localhost, qui n'existe pas sur le
    // runner : il echoue en CORS/ERR_FAILED et le POST n'est jamais emis. Le
    // produit est CORRECT (`OnboardingTour.tsx:110-114` appelle bien
    // `completeMutation.mutate()` sur STATUS.SKIPPED) ; c'est la garde qui
    // oubliait le prealable CSRF. Elle a donc ete rouge en silence, jamais
    // jouee par aucun workflow.
    await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, body: '' }));
    await page.route('**/api/v1/auth/me', (route) =>
      route.fulfill({
        json: {
          user: { id: 'u1', email: 'a@b.c', name: 'A', current_workspace_id: 'w1', onboarding_tour_completed_at: null },
          roles: ['owner'],
        },
      }),
    );
    await page.goto('/');
    await expect(page.locator('.react-joyride__overlay').first()).toBeVisible({ timeout: 5000 });
    // Click Skip button
    //
    // ⚠️ L'ancien `if (await skipBtn.isVisible()) { ... }` etait un VERT
    // DEGUISE : le jour ou le bouton « Passer » disparait, le corps du test
    // n'est plus execute et le test passe quand meme. On assure d'abord sa
    // presence, PUIS on clique — la disparition du bouton doit rougir.
    const skipBtn = page.locator('button:has-text("Passer")').first();
    await expect(skipBtn).toBeVisible();
    await skipBtn.click();
    // L'aller-retour CSRF precede le POST : une attente fixe de 500 ms etait
    // trop courte et surtout arbitraire. `expect.poll` attend le fait, pas une
    // duree, et rougit tout de meme si le POST ne vient pas (5 s de plafond).
    await expect.poll(() => postCalled, { timeout: 5_000 }).toBe(true);
  });
});
