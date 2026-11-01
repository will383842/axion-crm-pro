/**
 * Socle de la démo (DM0-DEMO) : bandeau, connexion par code de démo, offres,
 * remise à zéro, accessibilité et téléphone. Jouée par `.github/workflows/demo.yml`
 * sur le build de démo.
 */
import { test, expect } from '@playwright/test';
import { adresseDemo, appelerApi, controleAxe, ouvrirDemo, passage360 } from './outils';

interface Features {
  produits: { crm: string; envoi: string };
}
interface EtatEnvois {
  etat: string;
}

test.describe('socle de démo', () => {
  test('le bandeau est sur l’écran de connexion, et le code de démo connecte', async ({ page }) => {
    await ouvrirDemo(page, '/login', { connecte: false });
    const bandeau = page.getByRole('region', { name: 'Démonstration' });
    await expect(bandeau).toContainText('Démonstration : données fictives');
    await expect(bandeau).toContainText('demo@example.test');
    const code = (await bandeau.getByTestId('code-demo').innerText()).trim();
    expect(code).toMatch(/^\d{6}$/);

    await page.getByRole('textbox', { name: 'Adresse e-mail' }).fill('demo@example.test');
    await page.getByRole('textbox', { name: 'Mot de passe' }).fill('demo');
    await page.getByRole('button', { name: 'Se connecter' }).click();
    await expect(page).toHaveURL(/\/2fa/);
    await expect(page.getByRole('region', { name: 'Démonstration' })).toBeVisible();
    await page.getByRole('textbox', { name: 'Code à 6 chiffres' }).fill(code);
    await page.getByRole('button', { name: 'Vérifier' }).click();
    await expect(page).toHaveURL(/\/$/);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Démonstration' })).toBeVisible();
  });

  test('un mauvais code est refusé', async ({ page }) => {
    await ouvrirDemo(page, '/login', { connecte: false });
    await page.getByRole('textbox', { name: 'Adresse e-mail' }).fill('demo@example.test');
    await page.getByRole('textbox', { name: 'Mot de passe' }).fill('demo');
    await page.getByRole('button', { name: 'Se connecter' }).click();
    await page.getByRole('textbox', { name: 'Code à 6 chiffres' }).fill('000000');
    await page.getByRole('button', { name: 'Vérifier' }).click();
    await expect(page).toHaveURL(/\/2fa/);
  });

  test('le bandeau est dans l’application', async ({ page }) => {
    await ouvrirDemo(page, '/');
    await expect(page.getByRole('region', { name: 'Démonstration' })).toContainText('Démonstration : données fictives');
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
  });

  test('« Voir comme : Envoi seul » cache le CRM', async ({ page }) => {
    await ouvrirDemo(page, '/');
    const avant = await appelerApi<Features>(page, 'GET', '/config/features');
    expect(avant.produits).toEqual({ crm: 'actif', envoi: 'actif' });

    await page.getByRole('combobox', { name: 'Voir comme' }).selectOption('envoi');
    await expect(page.getByRole('combobox', { name: 'Voir comme' })).toHaveValue('envoi');
    const apres = await appelerApi<Features>(page, 'GET', '/config/features');
    expect(apres.produits).toEqual({ crm: 'absent', envoi: 'actif' });

    await page.getByRole('combobox', { name: 'Voir comme' }).selectOption('crm');
    await expect(page.getByRole('combobox', { name: 'Voir comme' })).toHaveValue('crm');
    const crm = await appelerApi<Features>(page, 'GET', '/config/features');
    expect(crm.produits).toEqual({ crm: 'actif', envoi: 'absent' });
  });

  test('« Remettre la démo à zéro » restaure les données', async ({ page }) => {
    await ouvrirDemo(page, '/');
    expect((await appelerApi<EtatEnvois>(page, 'GET', '/envoi/etat')).etat).toBe('actif');
    await appelerApi(page, 'POST', '/envoi/tout-arreter');
    expect((await appelerApi<EtatEnvois>(page, 'GET', '/envoi/etat')).etat).toBe('arrete');

    // Un rechargement GARDE le geste (persistance de session).
    await page.reload();
    await expect(page.getByRole('region', { name: 'Démonstration' })).toBeVisible();
    expect((await appelerApi<EtatEnvois>(page, 'GET', '/envoi/etat')).etat).toBe('arrete');

    await page.getByRole('button', { name: 'Remettre la démo à zéro' }).click();
    await expect(page.getByRole('region', { name: 'Démonstration' })).toBeVisible();
    await expect
      .poll(async () => (await appelerApi<EtatEnvois>(page, 'GET', '/envoi/etat')).etat)
      .toBe('actif');
  });

  test('aucune violation axe critique, connexion et application', async ({ page }) => {
    await ouvrirDemo(page, '/login', { connecte: false });
    await expect(page.getByRole('textbox', { name: 'Adresse e-mail' })).toBeVisible();
    await controleAxe(page);
    await ouvrirDemo(page, '/');
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await controleAxe(page);
  });

  test('à 360 px, aucun défilement horizontal', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 780 });
    await ouvrirDemo(page, '/login', { connecte: false });
    await passage360(page);
    await ouvrirDemo(page, '/');
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await passage360(page);
  });

  test('aucune requête ne sort du navigateur', async ({ page }) => {
    const sorties: string[] = [];
    page.on('request', (r) => {
      const url = new URL(r.url());
      if (url.hostname !== '127.0.0.1' && url.hostname !== 'demo.invalid' && !url.protocol.startsWith('data')) {
        sorties.push(r.url());
      }
    });
    // `demo.invalid` ne se résout jamais : si une requête y arrivait vraiment,
    // elle échouerait. Le faux serveur doit TOUT servir.
    const echecs: string[] = [];
    page.on('requestfailed', (r) => echecs.push(r.url()));
    await page.goto(adresseDemo('/'));
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    expect(sorties).toEqual([]);
    expect(echecs.filter((u) => u.includes('demo.invalid/sanctum') || u.includes('/auth/me'))).toEqual([]);
  });
});
