/**
 * DM2-CNF — démo des désinscriptions, alertes, réglages d'envoi et page
 * publique. Parcours P8 : Ctrl K → « Puis-je écrire à… ? ». Jouée par
 * `.github/workflows/demo.yml` sur le build de démo.
 */
import { test, expect } from '@playwright/test';
import { controleAxe, ouvrirDemo, passage360 } from './outils';

test.describe('DM2-CNF : conformité et réglages d’envoi', () => {
  test('P8 : Ctrl K mène à « Puis-je écrire à… ? » et répond avec le motif', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/campagnes', { offre: 'les-deux' });
    await page.keyboard.press('Control+k');
    await page.getByRole('combobox').fill('Puis-je écrire');
    await page.getByRole('option', { name: /Puis-je écrire à/ }).first().click();
    await expect(page).toHaveURL(/\/envoi\/desinscriptions/);
    await expect(page.getByRole('heading', { level: 1, name: 'Désinscriptions' })).toBeVisible();

    await page.getByRole('textbox', { name: 'Adresse ou domaine' }).fill('yves@bois-forme.example');
    await page.getByRole('button', { name: 'Vérifier' }).click();
    const reponse = page.getByRole('status');
    await expect(reponse).toContainText('Non');
    await expect(reponse).toContainText(/aucune mesure des ouvertures/i);
    await controleAxe(page);
  });

  test('l’export du registre télécharge un fichier', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/desinscriptions', { offre: 'les-deux' });
    const telechargement = page.waitForEvent('download');
    await page.getByRole('button', { name: /Exporter le registre/ }).click();
    const fichier = await telechargement;
    expect(fichier.suggestedFilename()).toMatch(/\.csv$/);
  });

  test('une inscription au registre se voit après rechargement', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/desinscriptions', { offre: 'les-deux' });
    await page.getByRole('button', { name: /Ajouter au registre/ }).click();
    await page.getByRole('textbox', { name: 'Adresse ou domaine à inscrire' }).fill('test-e2e@client.example');
    await page.getByRole('button', { name: 'Inscrire' }).click();
    await expect(page.getByText('test-e2e@client.example')).toBeVisible();
    await page.reload();
    await expect(page.getByText('test-e2e@client.example')).toBeVisible();
  });

  test('l’offre « Envoi seul » montre Destinataires, pas l’offre avec CRM', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/campagnes', { offre: 'envoi' });
    await expect(page.getByRole('link', { name: 'Destinataires' })).toBeVisible();
    await page.getByRole('link', { name: 'Destinataires' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Destinataires' })).toBeVisible();
    await controleAxe(page);

    await ouvrirDemo(page, '/envoi/campagnes', { offre: 'les-deux' });
    await expect(page.getByRole('link', { name: 'Destinataires' })).toHaveCount(0);
  });

  test('les alertes montrent « ce que le système a fait » et se règlent', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/alertes', { offre: 'les-deux' });
    await expect(page.getByRole('heading', { level: 1, name: 'Alertes d’envoi' })).toBeVisible();
    await expect(page.getByText('Ce que le système a fait').first()).toBeVisible();
    await page.getByRole('button', { name: /Marquer comme réglée/ }).first().click();
    await expect(page.getByText(/réglée/i).first()).toBeVisible();
    await controleAxe(page);
  });

  test('réglages : alertes (console et e-mail seulement), règles, identité SAS, connexion au CRM', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/reglages/alertes', { offre: 'les-deux' });
    await expect(page.getByRole('columnheader', { name: 'Console' })).toBeVisible();
    await expect(page.getByRole('columnheader', { name: 'E-mail' })).toBeVisible();
    await expect(page.getByText(/telegram|sms/i)).toHaveCount(0);
    await page.getByRole('button', { name: 'Envoyer une alerte de test' }).click();
    await expect(page.getByRole('status')).toContainText(/alerte de test/i);

    await page.goto(page.url().replace('/alertes', '/identite'));
    await expect(page.getByText(/SAS/).first()).toBeVisible();
    await expect(page.getByText(/estonie|estonien/i)).toHaveCount(0);
    await expect(page.getByText(/mesure des ouvertures/i).first()).toBeVisible();
    await expect(page.getByText(/aucun clic/i).first()).toBeVisible();
    await controleAxe(page);

    await page.goto(page.url().replace('/identite', '/regles'));
    await expect(page.getByRole('spinbutton', { name: /adresses refusées/i })).toBeVisible();
    await controleAxe(page);

    await page.goto(page.url().replace('/regles', '/connexion-crm'));
    await expect(page.getByText('CRM Pro').first()).toBeVisible();
    await controleAxe(page);
  });

  test('page publique : un clic, sans connexion, ni lien vers le site de l’éditeur', async ({ page }) => {
    await ouvrirDemo(page, '/desinscription/demo-tilleuls', { offre: 'les-deux', connecte: false });
    await expect(page.getByRole('heading', { level: 1, name: 'Se désinscrire' })).toBeVisible();
    await expect(page.getByRole('main').getByRole('link')).toHaveCount(0);
    await controleAxe(page);
    await passage360(page);
    await page.getByRole('button', { name: 'Confirmer la désinscription' }).click();
    await expect(page.getByText(/ne recevra plus rien de notre part/i)).toBeVisible();
  });

  test('360 px : les pages du lot ne défilent pas à l’horizontale', async ({ page }) => {
    for (const chemin of [
      '/envoi/desinscriptions',
      '/envoi/alertes',
      '/envoi/reglages',
      '/envoi/reglages/identite',
      '/envoi/reglages/alertes',
      '/envoi/reglages/regles',
      '/envoi/reglages/connexion-crm',
    ]) {
      await ouvrirDemo(page, chemin, { offre: 'les-deux' });
      await passage360(page);
    }
  });
});
