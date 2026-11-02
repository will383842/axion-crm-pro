/**
 * DM1-ENT — Entreprises et fiche entreprise en démo.
 * Tâche T1 du test de Will : « Boulangerie des Tilleuls », poser l'étiquette
 * `salon-artisans-2026`, exporter la sélection, en moins de 3 minutes.
 */
import { test, expect } from '@playwright/test';
import { controleAxe, ouvrirDemo, passage360 } from './outils';

test.describe('entreprises en démo', () => {
  test('T1 : cocher des fiches, poser l’étiquette, exporter la sélection (moins de 3 min)', async ({ page }) => {
    const debut = Date.now();
    await ouvrirDemo(page, '/companies');
    await expect(page.getByText('Boulangerie des Tilleuls')).toBeVisible();

    await page.getByRole('checkbox', { name: 'Sélectionner Boulangerie des Tilleuls' }).check();
    await page.getByRole('checkbox', { name: 'Sélectionner Atelier Bois & Forme' }).check();
    await expect(page.getByText('2 sélectionnées')).toBeVisible();

    await page.getByRole('textbox', { name: 'Étiquette à poser ou retirer' }).fill('salon-artisans-2026');
    await page.getByRole('button', { name: 'Poser l’étiquette' }).click();
    await expect(page.getByRole('status').filter({ hasText: /fiche\(s\) modifiée\(s\)/ })).toBeVisible();

    // La sélection est vidée par l'action de masse : on la refait pour l'export.
    await page.getByRole('checkbox', { name: 'Sélectionner Boulangerie des Tilleuls' }).check();
    await page.getByRole('checkbox', { name: 'Sélectionner Atelier Bois & Forme' }).check();
    const telechargement = page.waitForEvent('download');
    await page.getByRole('button', { name: 'Exporter la sélection' }).click();
    const fichier = await telechargement;
    expect(fichier.suggestedFilename()).toBe('entreprises-selection-2.csv');
    expect(Date.now() - debut).toBeLessThan(3 * 60_000);
  });

  test('une vue enregistrée survit au rechargement', async ({ page }) => {
    await ouvrirDemo(page, '/companies?priority=haute');
    await page.getByRole('button', { name: 'Enregistrer cette vue' }).click();
    await page.getByRole('textbox', { name: 'Nom de la vue' }).fill('Priorité haute du mois');
    await page.getByRole('button', { name: 'Enregistrer', exact: true }).click();
    await page.reload();
    await expect(page.getByRole('combobox', { name: 'Vues enregistrées' }).getByRole('option', { name: 'Priorité haute du mois' })).toHaveCount(1);
  });

  test('la fiche montre le suivi, les liens et les envois d’Envoi', async ({ page }) => {
    await ouvrirDemo(page, '/companies/1', { offre: 'les-deux' });
    await expect(page.getByRole('heading', { level: 1, name: 'Boulangerie des Tilleuls' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Envois reçus' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Liens entre entreprises' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Ajouter à une campagne' })).toBeVisible();
    await controleAxe(page);
  });

  test('« CRM seul » : la fiche ne parle pas d’Envoi', async ({ page }) => {
    await ouvrirDemo(page, '/companies/1', { offre: 'crm' });
    await expect(page.getByRole('heading', { level: 1, name: 'Boulangerie des Tilleuls' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Envois reçus' })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Ajouter à une campagne' })).toHaveCount(0);
  });

  test('les 4 états de la liste', async ({ page }) => {
    await ouvrirDemo(page, '/companies', { etat: 'vide' });
    await expect(page.getByText('Aucune entreprise')).toBeVisible();
    await ouvrirDemo(page, '/companies', { etat: 'erreur' });
    await expect(page.getByText(/momentanément indisponible|n’a pas pu|Réessayer/).first()).toBeVisible();
  });

  test('accessibilité et téléphone', async ({ page }) => {
    await ouvrirDemo(page, '/companies');
    await expect(page.getByText('Boulangerie des Tilleuls')).toBeVisible();
    await controleAxe(page);
    await passage360(page);
    await ouvrirDemo(page, '/companies/1');
    await expect(page.getByRole('heading', { level: 1, name: 'Boulangerie des Tilleuls' })).toBeVisible();
    await passage360(page);
  });

  // Captures de la PR (1440 et 360 px), déposées en artefact par `demo.yml`.
  test('captures 1440 et 360 px : liste et fiche', async ({ page }) => {
    for (const [nom, chemin] of [['entreprises', '/companies'], ['entreprise', '/companies/1']] as const) {
      for (const largeur of [1440, 360]) {
        await page.setViewportSize({ width: largeur, height: largeur === 1440 ? 900 : 780 });
        await ouvrirDemo(page, chemin);
        await expect(page.getByText('Boulangerie des Tilleuls').first()).toBeVisible();
        await page.screenshot({ path: `captures-demo/dm1-ent-${nom}-${largeur}.png`, fullPage: true });
      }
    }
  });
});
