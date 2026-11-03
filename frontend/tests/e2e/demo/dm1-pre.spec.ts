/**
 * DM1-PRE — démo de la presse et des réseaux : médias, journalistes, communiqués,
 * fédérations, événements. Jouée par `.github/workflows/demo.yml` sur le build de démo.
 *
 * Tâche T3 : retrouver les journalistes du Courrier du Rhône, repérer celui qui
 * s'est opposé aux envois, consigner une relance sur la fiche de Léa Fournier.
 */
import { test, expect } from '@playwright/test';
import { controleAxe, ouvrirDemo, passage360 } from './outils';

test.describe('presse et réseaux en démo', () => {
  test('T3 : le Courrier du Rhône, l’opposé repéré, une relance consignée (et visible après rechargement)', async ({ page }) => {
    await ouvrirDemo(page, '/media', { offre: 'les-deux' });
    await expect(page.getByRole('heading', { level: 1, name: 'Médias' })).toBeVisible();
    await page.getByRole('link', { name: 'Le Courrier du Rhône' }).first().click();
    await expect(page.getByRole('heading', { level: 1, name: 'Le Courrier du Rhône' })).toBeVisible();
    // L'opposé est repéré dans le tableau des journalistes de la rédaction.
    await expect(page.getByText('opposé aux envois')).toBeVisible();
    await ouvrirDemo(page, '/journalists');
    await page.getByRole('link', { name: /Fournier/ }).first().click();
    await expect(page.getByRole('heading', { level: 1, name: 'Léa Fournier' })).toBeVisible();
    await page.locator('#main').getByRole('combobox').selectOption('press_followup');
    await page.getByPlaceholder(/En une ligne/).fill('Relance après l’article du 9 octobre');
    await page.getByRole('button', { name: 'Consigner' }).click();
    await expect(page.getByText('Relance après l’article du 9 octobre')).toBeVisible();
    await page.reload();
    await expect(page.getByText('Relance après l’article du 9 octobre')).toBeVisible();
  });

  test('la fiche d’un journaliste opposé affiche l’opposition', async ({ page }) => {
    await ouvrirDemo(page, '/media/1');
    await expect(page.getByText('opposé aux envois')).toBeVisible();
    await controleAxe(page);
    await passage360(page);
  });

  test('le registre des communiqués : compteurs, filtre par geste, et pas de bouton Envoyer sans Envoi', async ({ page }) => {
    await ouvrirDemo(page, '/presse/envois', { offre: 'crm' });
    await expect(page.getByRole('heading', { level: 1, name: 'Communiqués envoyés' })).toBeVisible();
    await expect(page.getByTestId('compteurs-communiques')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Envoyer un communiqué' })).toHaveCount(0);
    await page.locator('#main').getByRole('combobox').first().selectOption('press_coverage');
    await expect(page.getByRole('cell', { name: 'Retombée publiée' }).first()).toBeVisible();
    await controleAxe(page);
    await passage360(page);
  });

  test('avec Envoi, « Envoyer un communiqué » ouvre la création de campagne', async ({ page }) => {
    await ouvrirDemo(page, '/presse/envois', { offre: 'les-deux' });
    await page.getByRole('link', { name: 'Envoyer un communiqué' }).click();
    await expect(page).toHaveURL(/\/envoi\/campagnes\/nouvelle/);
  });

  test('fédération : faire avancer le partenariat se voit après rechargement', async ({ page }) => {
    await ouvrirDemo(page, '/federations');
    await page.getByRole('link', { name: 'Fédération BTP Rhône' }).first().click();
    await page.getByLabel('Étape').selectOption('accepte');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Démarche enregistrée')).toBeVisible();
    await page.reload();
    await expect(page.getByLabel('Étape')).toHaveValue('accepte');
    await controleAxe(page);
    await passage360(page);
  });

  test('événement : le salon des artisans de Vienne, participation mise à jour', async ({ page }) => {
    await ouvrirDemo(page, '/evenements');
    await page.getByRole('link', { name: 'Salon des artisans de Vienne' }).first().click();
    await page.getByLabel('Ma participation').selectOption('rencontre');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Démarche enregistrée')).toBeVisible();
    await page.reload();
    await expect(page.getByLabel('Ma participation')).toHaveValue('rencontre');
    await controleAxe(page);
    await passage360(page);
  });

  for (const chemin of ['/media', '/journalists', '/presse/envois', '/federations', '/evenements']) {
    test(`état vide propre : ${chemin}`, async ({ page }) => {
      await ouvrirDemo(page, chemin, { etat: 'vide' });
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
      await expect(page.getByRole('alert')).toHaveCount(0);
    });
    test(`état d’erreur propre : ${chemin}`, async ({ page }) => {
      await ouvrirDemo(page, chemin, { etat: 'erreur' });
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    });
    test(`état normal accessible : ${chemin}`, async ({ page }) => {
      await ouvrirDemo(page, chemin);
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
      await controleAxe(page);
      await passage360(page);
    });
  }
});
