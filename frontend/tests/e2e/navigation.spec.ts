import { test, expect } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * Sprint 18.7 — Navigation smoke tests : verify each protected route loads
 * with mocked /auth/me returning a valid user. Goal is to catch broken routes,
 * not to test full features.
 */
/**
 * La barre est un ACCORDÉON depuis la PR #84 : une seule section ouverte à la
 * fois, celle de la page courante. Un lien d'une autre section n'est donc pas
 * visible tant qu'on n'a pas ouvert sa section — les assertions ci-dessous
 * ouvrent d'abord. (Ce fichier n'était pas exécuté en CI ; il était rouge en
 * silence depuis #84. Étape 0, F17 : corrigé et branché dans `a11y.yml`.)
 */
async function ouvrir(page: Page, section: string): Promise<void> {
  const bouton = page.getByRole('button', { name: section, exact: true });
  await expect(bouton).toBeVisible();
  if ((await bouton.getAttribute('aria-expanded')) !== 'true') {
    await bouton.click();
  }
}

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
    await page.route('**/api/v1/media*', (route) =>
      route.fulfill({ json: { data: [], meta: { total: 0, last_page: 1, current_page: 1, per_page: 100 } } }),
    );
    await page.route('**/api/v1/journalists*', (route) =>
      route.fulfill({ json: { data: [], meta: { total: 0, last_page: 1, current_page: 1, per_page: 100 } } }),
    );
  });

  test('sidebar : dashboard link visible', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('link', { name: /Tableau de bord/i })).toBeVisible();
  });

  // Lot 2 UX (2026-10-02) — la barre RÉORGANISÉE : le tableau de bord seul en
  // tête, les sections (À traiter, Ma base, Presse, Réseaux, Ciblage,
  // Alimenter la base, Réglages) et une section « Technique » repliée, en dernier.
  test('sidebar : entreprises et contacts sous « Ma base »', async ({ page }) => {
    await page.goto('/');
    await ouvrir(page, 'Ma base');
    await expect(page.getByRole('link', { name: 'Entreprises', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Contacts', exact: true })).toHaveCount(1);
  });

  // Audit UX lot 18 (2026-10-03) — la Roumanie vit sous « Technique », repliée.
  test('sidebar : entreprises en Roumanie sous « Technique »', async ({ page }) => {
    await page.goto('/');
    await ouvrir(page, 'Technique');
    await expect(page.getByRole('link', { name: 'Entreprises en Roumanie' })).toBeVisible();
  });

  test('sidebar : médias et journalistes sous « Presse »', async ({ page }) => {
    await page.goto('/');
    await ouvrir(page, 'Presse');
    await expect(page.getByRole('link', { name: 'Médias', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Journalistes' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Communiqués envoyés' })).toBeVisible();
  });

  test('page médias : se charge sans erreur', async ({ page }) => {
    await page.goto('/media');
    await expect(page.getByRole('heading', { name: 'Médias', exact: true })).toBeVisible();
  });

  test('sidebar : audiences et listes sous « Ciblage »', async ({ page }) => {
    await page.goto('/');
    await ouvrir(page, 'Ciblage');
    await expect(page.getByRole('link', { name: 'Audiences', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Listes', exact: true })).toBeVisible();
  });

  test('sidebar : carte de France et collectes sous « Alimenter la base »', async ({ page }) => {
    await page.goto('/');
    await ouvrir(page, 'Alimenter la base');
    await expect(page.getByRole('link', { name: 'Carte de France' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Collectes' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Campagnes' })).toHaveCount(0);
  });

  test('sidebar : « Technique » est REPLIÉE à l’arrivée, et parle français', async ({ page }) => {
    await page.goto('/');
    const technique = page.getByRole('button', { name: 'Technique', exact: true });
    await expect(technique).toHaveAttribute('aria-expanded', 'false');
    await expect(page.getByRole('link', { name: 'Moteurs d’IA' })).toBeHidden();
    await ouvrir(page, 'Technique');
    await expect(page.getByRole('link', { name: 'Moteurs d’IA' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Serveurs relais' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Santé du système' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'LLM Router' })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Proxies' })).toHaveCount(0);
  });

  test('sidebar : Réglages', async ({ page }) => {
    await page.goto('/');
    await ouvrir(page, 'Réglages');
    await expect(page.getByRole('link', { name: 'Utilisateurs' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Paramètres' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Demandes RGPD' })).toBeVisible();
  });

  test('sidebar : rangée (lot 2 UX) — sections + Technique, aucun cadenas', async ({ page }) => {
    await page.goto('/');
    const sections = ['À traiter', 'Ma base', 'Presse', 'Réseaux', 'Ciblage', 'Alimenter la base', 'Réglages', 'Technique'];
    for (const titre of sections) {
      await expect(page.getByRole('button', { name: titre, exact: true })).toBeVisible();
    }
    for (const titre of sections) {
      await ouvrir(page, titre);
      await expect(page.locator('[aria-label="Bientôt disponible"]')).toHaveCount(0);
      for (const retire of ['Templates email', 'Envois email', 'E-mails à froid', 'Prospection LinkedIn', 'Pipeline CRM', 'Analytique']) {
        await expect(page.getByRole('link', { name: retire })).toHaveCount(0);
      }
    }
  });

  test('header : recherche globale visible', async ({ page }) => {
    await page.goto('/');
    // GlobalSearch présente
    await expect(page.locator('[data-tour="global-search"]')).toBeVisible();
  });

  test('header : AUCUN sélecteur de thème (pas de mode sombre)', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('[data-tour="dark-mode"]')).toHaveCount(0);
    await expect(page.getByRole('button', { name: /theme/i })).toHaveCount(0);
    await expect(page.locator('html')).not.toHaveClass(/dark/);
  });

  test('skip-link a11y présent', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByText('Aller au contenu')).toBeAttached();
  });
});
