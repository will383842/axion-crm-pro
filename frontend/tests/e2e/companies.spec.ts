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
    // P6-UI-008 (2026-08-20) — `getByText('PME')` seul VIOLE le mode strict.
    // Mesure sur le build servi par `vite preview` (chromium 1234) : avec une
    // seule ligne de resultat, 3 elements portent ce texte sur /companies —
    //   1) la tuile de synthese « TOP TAILLE » (div.text-2xl)
    //   2) l'<option value="pme"> du selecteur « Filtre taille »
    //   3) le badge de la ligne du tableau (span.bg-indigo-100)
    // Playwright echoue alors AVANT de regarder le badge. Le sujet du test est
    // la LIGNE, on s'ancre donc sur le `rowgroup` (le <tbody>), unique ici.
    // C'est un defaut de la GARDE, pas du produit : le badge s'affichait
    // correctement pendant tout le temps ou ce test est reste rouge en
    // silence — aucun workflow ne le jouait (14 suites sur 17 dans ce cas).
    await expect(page.getByRole('rowgroup').getByText('PME')).toBeVisible();
  });

  test('search filter updates URL params', async ({ page }) => {
    await page.route('**/api/v1/companies*', (route) =>
      route.fulfill({ json: { data: [], meta: { total: 0, last_page: 1, current_page: 1, per_page: 50 } } }),
    );
    await page.goto('/companies');
    await page.getByPlaceholder(/Rechercher/).fill('acme');
    // Wait for debounced re-fetch
    //
    // P6-UI-008 (2026-08-20) — ce controle cherchait `filter[denomination]=acme`
    // avec des CROCHETS BRUTS. Or axios encode les crochets. Requetes reellement
    // emises, relevees a la sonde sur le build :
    //   1) https://api.localhost/api/v1/companies?page=1&per_page=100
    //   2) https://api.localhost/api/v1/companies?page=1&per_page=100
    //      &filter%5Bdenomination%5D=acme
    // La 2e EST la bonne requete : le produit filtre bien. La garde ne pouvait
    // simplement jamais la reconnaitre et expirait a 30 s. On decode avant de
    // comparer — ainsi le controle reste lisible et ne depend pas de la forme
    // d'encodage choisie par le client HTTP.
    await page.waitForRequest((req) => decodeURIComponent(req.url()).includes('filter[denomination]=acme'));
  });
});
