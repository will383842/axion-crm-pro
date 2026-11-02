/**
 * DM1-CIB — ciblage en démo : parcours P1 (Étiquettes → Campagne vers cette
 * étiquette, 2 clics), éditeur ET / OU / SAUF, listes, préparation de campagne.
 * Jouée par `.github/workflows/demo.yml` sur le build de démo.
 */
import { test, expect } from '@playwright/test';
import { controleAxe, ouvrirDemo, passage360 } from './outils';

test.describe('DM1-CIB — ciblage', () => {
  test('P1 : Étiquettes puis « Campagne vers cette étiquette » en 2 clics', async ({ page }) => {
    await ouvrirDemo(page, '/', { offre: 'les-deux' });
    const entree = page.getByRole('link', { name: 'Étiquettes', exact: true }).first();
    if (!(await entree.isVisible())) await page.getByRole('button', { name: /Réglages/ }).first().click();
    await entree.click(); // clic 1 : le menu
    await expect(page.getByRole('heading', { level: 1, name: 'Étiquettes' })).toBeVisible();
    await page.getByRole('link', { name: 'Campagne vers cette étiquette' }).click(); // clic 2 : le bouton
    await expect(page).toHaveURL(/\/envoi\/campagnes\/nouvelle\?.*etiquette=salon-artisans-2026/);
  });

  test('CRM seul : pas de bouton de campagne sur la page Étiquettes', async ({ page }) => {
    await ouvrirDemo(page, '/tags', { offre: 'crm' });
    await expect(page.getByRole('heading', { level: 1, name: 'Étiquettes' })).toBeVisible();
    await expect(page.getByRole('link', { name: /Campagne vers/ })).toHaveCount(0);
  });

  test('l’éditeur ET / OU / SAUF estime la taille et la geste est gardé après création', async ({ page }) => {
    await ouvrirDemo(page, '/audiences/new', { offre: 'les-deux' });
    const groupe = (n: number) => page.getByRole('group', { name: new RegExp(`^Critère ${n} `) });
    await groupe(1).getByLabel('Champ').selectOption('sector_main');
    await groupe(1).getByLabel('Valeur').selectOption('btp');
    await page.getByRole('button', { name: '+ SAUF' }).click();
    await groupe(2).getByLabel('Champ').selectOption('tags');
    await groupe(2).getByLabel('Valeur').fill('clients-2026');
    await expect(page.getByRole('status', { name: 'Taille estimée' })).toContainText(/≈ \d+ fiche/);
    await expect(page.getByRole('status', { name: 'Critères en une phrase' })).toContainText('SAUF Étiquette contient clients-2026');

    await page.getByRole('textbox', { name: /^Nom/ }).fill('BTP hors clients');
    await page.getByRole('button', { name: /Créer/ }).first().click();
    await expect(page).toHaveURL(/\/audiences\/\d+/);
    await page.reload();
    await expect(page.getByRole('heading', { level: 1, name: 'BTP hors clients' })).toBeVisible();
  });

  test('la préparation de campagne montre la taille joignable et mène à Envoi', async ({ page }) => {
    await ouvrirDemo(page, '/audiences/2', { offre: 'les-deux' });
    await page.getByRole('tab', { name: 'Préparation campagne' }).click();
    await expect(page.getByLabel('Adresses joignables')).toBeVisible();
    await page.getByRole('link', { name: 'Lancer une campagne' }).click();
    await expect(page).toHaveURL(/\/envoi\/campagnes\/nouvelle\?.*audience=2/);
  });

  test('une liste : retirer une fiche, rechargement, la modification reste', async ({ page }) => {
    await ouvrirDemo(page, '/listes/1', { offre: 'crm' });
    const sous = page.getByText(/organisation\(s\) · .* personne\(s\)/);
    await expect(sous).toBeVisible();
    const avant = await sous.innerText();
    await page.getByRole('checkbox').nth(1).check();
    page.once('dialog', (d) => void d.accept());
    await page.getByRole('button', { name: 'Retirer de la liste' }).click();
    await expect(page.getByText(/retirée\(s\) de la liste/)).toBeVisible();
    await page.reload();
    await expect(page.getByText(/organisation\(s\) · .* personne\(s\)/)).not.toHaveText(avant);
  });

  for (const etat of ['vide', 'erreur', 'chargement'] as const) {
    test(`états : Audiences et Listes en « ${etat} »`, async ({ page }) => {
      for (const chemin of ['/audiences', '/listes']) {
        await ouvrirDemo(page, chemin, { offre: 'crm', etat });
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
      }
    });
  }

  test('accessibilité et 360 px', async ({ page }) => {
    for (const chemin of ['/audiences', '/audiences/new', '/listes', '/tags']) {
      await ouvrirDemo(page, chemin, { offre: 'les-deux' });
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
      await controleAxe(page);
      await passage360(page);
    }
  });
});
