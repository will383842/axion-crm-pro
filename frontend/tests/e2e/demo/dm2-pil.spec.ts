/**
 * DM2-PIL — Résultats, Santé des envois, Chercher un envoi, Feu vert (démo).
 * Parcours P4 (« un domaine va mal ») et P10 (« pourquoi cette adresse n’a rien reçu ? »)
 * en 2 clics ; Ctrl K « Chercher un envoi ». Jouée par `demo.yml`.
 */
import { test, expect } from '@playwright/test';
import { controleAxe, ouvrirDemo, passage360 } from './outils';

test.describe('pilotage des envois (démo)', () => {
  test('P4 : de la santé à la fiche du domaine ambre en un clic', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/sante', { offre: 'les-deux' });
    await expect(page.getByRole('heading', { level: 1, name: 'Santé des envois' })).toBeVisible();
    await page.getByRole('link', { name: /prospection-b\.example/ }).click();
    await expect(page).toHaveURL(/\/envoi\/domaines\/dom-b/);
  });

  test('P10 : une adresse refusée s’explique en français', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/sante/chercher', { offre: 'les-deux' });
    await page.getByRole('searchbox', { name: 'Adresse e-mail' }).fill('yves');
    await page.getByRole('button', { name: 'Chercher', exact: true }).click();
    await expect(page.getByText(/Refusé : adresse inexistante \(550 5\.1\.1\)/)).toBeVisible();
  });

  test('Ctrl K « Chercher un envoi » ouvre la page', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/campagnes', { offre: 'les-deux' });
    await expect(page.getByRole('button', { name: 'Recherche globale' })).toBeVisible();
    await page.locator('body').press('Control+k');
    await expect(page.getByRole('dialog', { name: 'Recherche globale' })).toBeVisible();
    await page.getByRole('dialog', { name: 'Recherche globale' }).getByPlaceholder(/Rechercher/).fill('Chercher un envoi');
    await page.getByRole('option', { name: /Chercher un envoi/ }).first().click();
    await expect(page).toHaveURL(/\/envoi\/sante\/chercher/);
  });

  test('Résultats : pas de clics, tableau par boîte, accessibilité', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/campagnes/resultats', { offre: 'les-deux' });
    await expect(page.getByRole('table', { name: 'Par boîte' })).toBeVisible();
    await expect(page.locator('main')).not.toContainText(/clic/i);
    await controleAxe(page);
    await passage360(page);
  });

  test('Feu vert : marquer un point fait survit au rechargement', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/sante/feu-vert', { offre: 'les-deux' });
    await expect(page.getByText('14 critères sur 18')).toBeVisible();
    await page.getByRole('button', { name: /Marquer fait : Boucles de retour/ }).click();
    await expect(page.getByText('15 critères sur 18')).toBeVisible();
    await page.reload();
    await expect(page.getByText('15 critères sur 18')).toBeVisible();
    await controleAxe(page);
    await passage360(page);
  });

  test('les quatre états de Santé', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/sante', { offre: 'les-deux', etat: 'vide' });
    await expect(page.getByText('Aucun envoi pour l’instant')).toBeVisible();
    await ouvrirDemo(page, '/envoi/sante', { offre: 'les-deux', etat: 'erreur' });
    await expect(page.getByText('État des envois inconnu')).toBeVisible();
  });
});
