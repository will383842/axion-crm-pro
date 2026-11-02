/**
 * Socle de la démo (DM0-DEMO) : bandeau, connexion par code de démo, offres,
 * remise à zéro, accessibilité et téléphone. Jouée par `.github/workflows/demo.yml`
 * sur le build de démo.
 */
import { createServer } from 'node:http';
import type { AddressInfo } from 'node:net';
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

  // Captures de la PR (1440 et 360 px), déposées par `demo.yml` en artefact
  // « captures-demo » : la description d'une PR ne peut pas recevoir d'image par l'API.
  test('captures 1440 et 360 px : connexion et tableau de bord', async ({ page }) => {
    for (const [largeur, hauteur] of [[1440, 900], [360, 780]] as const) {
      await page.setViewportSize({ width: largeur, height: hauteur });
      await ouvrirDemo(page, '/login', { connecte: false });
      await expect(page.getByRole('region', { name: 'Démonstration' })).toBeVisible();
      await page.screenshot({ path: `captures-demo/connexion-${largeur}.png`, fullPage: true });
      await ouvrirDemo(page, '/');
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
      await page.waitForLoadState('networkidle');
      await page.screenshot({ path: `captures-demo/tableau-de-bord-${largeur}.png`, fullPage: true });
    }
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

  // Hôte qui RÉPOND vraiment (autre port = autre origine) et qui compte ses appels.
  // Pas de `page.route` : Playwright contournerait le service worker et le test ne
  // mesurerait plus MSW. Sans la garde réseau, la requête PART et le compteur monte.
  async function avecHoteJoignable(
    utiliser: (adresse: string, appels: () => number) => Promise<void>,
  ): Promise<void> {
    let appels = 0;
    const serveur = createServer((_requete, reponse) => {
      appels += 1;
      reponse.writeHead(200, { 'Access-Control-Allow-Origin': '*', 'Content-Type': 'text/plain' });
      reponse.end('ok');
    });
    await new Promise<void>((ok) => serveur.listen(0, '127.0.0.1', ok));
    const { port } = serveur.address() as AddressInfo;
    try {
      await utiliser(`http://127.0.0.1:${port}/tuile.png`, () => appels);
    } finally {
      await new Promise((ok) => serveur.close(ok));
    }
  }

  test('aucune requête ne sort : un hôte joignable n’est jamais appelé', async ({ page }) => {
    await ouvrirDemo(page, '/');
    await avecHoteJoignable(async (adresse, appels) => {
      const resultat = await page.evaluate(async (url) => {
        try {
          await fetch(url, { mode: 'no-cors' });
          return 'partie';
        } catch {
          return 'refusée';
        }
      }, adresse);
      expect(resultat).toBe('refusée');
      expect(appels()).toBe(0);
    });
  });

  test('une requête vers un autre hôte (tuile de carte) est refusée et signalée par la garde', async ({ page }) => {
    const avertissements: string[] = [];
    page.on('console', (m) => {
      if (m.type() === 'warning') avertissements.push(m.text());
    });
    await ouvrirDemo(page, '/');
    await avecHoteJoignable(async (adresse, appels) => {
      await page.evaluate((url) => fetch(url, { mode: 'no-cors' }).catch(() => null), adresse);
      expect(appels()).toBe(0);
      // Message de la GARDE (pas celui du filet `onUnhandledRequest`).
      expect(avertissements.some((t) => t.includes('Requête refusée') && t.includes(adresse))).toBe(true);
      expect(avertissements.some((t) => t.includes('non gérée'))).toBe(false);
    });
  });
});
