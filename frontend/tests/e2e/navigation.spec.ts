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

  test('sidebar : entreprises link', async ({ page }) => {
    await page.goto('/');
    await ouvrir(page, 'Contacts');
    await expect(page.getByRole('link', { name: 'Entreprises' })).toBeVisible();
  });

  test('sidebar : contacts link', async ({ page }) => {
    await page.goto('/');
    await ouvrir(page, 'Contacts');
    await expect(page.getByRole('link', { name: 'Contacts' })).toBeVisible();
  });

  test('sidebar : médias link', async ({ page }) => {
    await page.goto('/');
    await ouvrir(page, 'Contacts');
    await expect(page.getByRole('link', { name: 'Médias (presse)' })).toBeVisible();
  });

  test('sidebar : journalistes link', async ({ page }) => {
    await page.goto('/');
    await ouvrir(page, 'Contacts');
    await expect(page.getByRole('link', { name: 'Journalistes' })).toBeVisible();
  });

  test('page médias : se charge sans erreur', async ({ page }) => {
    await page.goto('/media');
    await expect(page.getByRole('heading', { name: 'Médias' })).toBeVisible();
  });

  test('sidebar : couverture France link', async ({ page }) => {
    await page.goto('/');
    await ouvrir(page, 'Collecte');
    await expect(page.getByRole('link', { name: /Couverture France/ })).toBeVisible();
  });

  test('sidebar : LLM Router sous Réglages', async ({ page }) => {
    await page.goto('/');
    await ouvrir(page, 'Réglages');
    await expect(page.getByRole('link', { name: 'LLM Router' })).toBeVisible();
  });

  test('sidebar : Conformité', async ({ page }) => {
    await page.goto('/');
    await ouvrir(page, 'Conformité');
    await expect(page.getByRole('link', { name: 'Requêtes RGPD' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Registre AI Act' })).toBeVisible();
  });

  test('sidebar : Réglages', async ({ page }) => {
    await page.goto('/');
    await ouvrir(page, 'Réglages');
    await expect(page.getByRole('link', { name: 'Utilisateurs' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Paramètres' })).toBeVisible();
  });

  test('sidebar : rangée (étape 0, F17) — six groupes, une seule entrée Contacts, aucun cadenas', async ({ page }) => {
    await page.goto('/');
    // Les six groupes, dans l'ordre de la journée.
    for (const titre of ["Aujourd'hui", 'Contacts', 'Collecte', 'Pilotage', 'Conformité', 'Réglages']) {
      await expect(page.getByRole('button', { name: titre })).toBeVisible();
    }
    // Les mots réservés aux e-mails (L7) ne désignent plus la collecte.
    await ouvrir(page, 'Collecte');
    await expect(page.getByRole('link', { name: 'Collectes' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Journaux de collecte' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Campagnes' })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Runs de scraping' })).toHaveCount(0);
    // Une seule entrée « Contacts » (le hub ou l'ancienne liste, jamais les deux),
    // et aucun cadenas nulle part — on ouvre chaque section pour en être sûr.
    for (const titre of ["Aujourd'hui", 'Contacts', 'Collecte', 'Pilotage', 'Conformité', 'Réglages']) {
      await ouvrir(page, titre);
      await expect(page.locator('[aria-label="Bientôt disponible"]')).toHaveCount(0);
      for (const retire of ['Templates email', 'Envois email', 'E-mails à froid', 'Prospection LinkedIn', 'Pipeline CRM', 'Analytique']) {
        await expect(page.getByRole('link', { name: retire })).toHaveCount(0);
      }
    }
    await ouvrir(page, 'Contacts');
    await expect(page.getByRole('link', { name: 'Contacts', exact: true })).toHaveCount(1);
  });

  test('header : recherche globale visible', async ({ page }) => {
    await page.goto('/');
    // GlobalSearch présente
    await expect(page.locator('[data-tour="global-search"]')).toBeVisible();
  });

  test('header : dark mode toggle', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('[data-tour="dark-mode"]')).toBeVisible();
  });

  test('skip-link a11y présent', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByText('Aller au contenu')).toBeAttached();
  });
});
