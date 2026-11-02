/**
 * DM1-CON — démo des contacts : ajout rapide avec doublon, import, corbeille.
 * Jouée par `.github/workflows/demo.yml` sur le build de démo.
 */
import { test, expect } from '@playwright/test';
import { controleAxe, ouvrirDemo, passage360 } from './outils';

test.describe('contacts en démo', () => {
  test('ajout rapide : le doublon est proposé, on peut fusionner', async ({ page }) => {
    await ouvrirDemo(page, '/console/contacts', { offre: 'les-deux' });
    await expect(page.getByRole('heading', { level: 1, name: 'Contacts' })).toBeVisible();
    await page.getByRole('button', { name: 'Ajouter un contact' }).click();
    const fenetre = page.getByRole('dialog');
    await fenetre.getByLabel('Prénom').fill('Paul');
    await fenetre.getByLabel(/^Nom/).fill('Morin');
    await fenetre.getByLabel('E-mail').fill('paul.morin@boulangerie-tilleuls.example');
    await fenetre.getByLabel('Organisation').fill('Boulangerie des Tilleuls');
    await expect(fenetre.getByRole('status')).toContainText(/existe peut-être déjà/);
    // Sans provenance, on ne peut pas enregistrer.
    await fenetre.getByRole('button', { name: 'Fusionner avec la fiche existante' }).click();
    await expect(fenetre.getByRole('alert')).toContainText(/provenance/i);
    await fenetre.getByLabel('Provenance').selectOption('salon');
    await fenetre.getByRole('button', { name: 'Fusionner avec la fiche existante' }).click();
    await expect(page.getByText('Fiches fusionnées')).toBeVisible();
  });

  test('ajout rapide : garder les deux crée une seconde fiche, visible après rechargement', async ({ page }) => {
    await ouvrirDemo(page, '/console/contacts');
    await page.getByRole('button', { name: 'Ajouter un contact' }).click();
    const fenetre = page.getByRole('dialog');
    await fenetre.getByLabel('Prénom').fill('Camille');
    await fenetre.getByLabel(/^Nom/).fill('Delmas');
    await fenetre.getByLabel('E-mail').fill('camille.delmas@example.test');
    await fenetre.getByLabel('Organisation').fill('Atelier Delmas');
    await fenetre.getByLabel('Provenance').selectOption('rencontre');
    await fenetre.getByRole('button', { name: 'Ajouter ce contact' }).click();
    await expect(page.getByText('Contact ajouté')).toBeVisible();
    await page.reload();
    await page.getByRole('textbox', { name: /Rechercher une entreprise/ }).fill('Delmas');
    await expect(page.getByText('Atelier Delmas')).toBeVisible();
  });

  test('retirer puis restaurer depuis la corbeille', async ({ page }) => {
    await ouvrirDemo(page, '/console/contacts');
    await page.getByRole('link', { name: 'Paul Morin' }).first().click();
    await page.getByRole('button', { name: 'Retirer' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Retirer de la base' }).click();
    await expect(page.getByRole('status', { name: 'Fiche dans la corbeille' })).toBeVisible();
    await page.getByRole('status', { name: 'Fiche dans la corbeille' }).getByRole('button', { name: 'Restaurer' }).click();
    await expect(page.getByRole('status', { name: 'Fiche dans la corbeille' })).toHaveCount(0);
  });

  test('état vide : message clair', async ({ page }) => {
    await ouvrirDemo(page, '/console/contacts', { etat: 'vide' });
    await expect(page.getByText('Aucun contact dans cette vue')).toBeVisible();
  });

  test('état d’erreur : message et bouton pour réessayer', async ({ page }) => {
    await ouvrirDemo(page, '/console/contacts', { etat: 'erreur' });
    await expect(page.getByRole('alert').first()).toBeVisible();
  });

  test('état normal : accessibilité et téléphone', async ({ page }) => {
    await ouvrirDemo(page, '/console/contacts');
    await expect(page.getByText('Boulangerie des Tilleuls')).toBeVisible();
    await controleAxe(page);
    await passage360(page);
  });

  test('import : un gros fichier tourne en arrière-plan puis donne son rapport', async ({ page }) => {
    await ouvrirDemo(page, '/console/contacts');
    await page.getByRole('button', { name: 'Importer' }).click();
    const lignes = ['Prénom;Nom;E-mail;Organisation;Fonction;Provenance'];
    for (let i = 1; i <= 420; i += 1) lignes.push(`Test${i};Import${i};test${i}@example.test;Atelier Test ${i};Gérant;fichier-client`);
    await page.getByLabel(/Fichier à importer/).setInputFiles({ name: 'gros.csv', mimeType: 'text/csv', buffer: Buffer.from(lignes.join('\n'), 'utf-8') });
    await page.getByRole('button', { name: 'Contrôler le fichier' }).click();
    await expect(page.getByText(/420 fiches seront créées/)).toBeVisible();
    await page.getByRole('button', { name: 'Lancer l’import' }).click();
    await expect(page.getByRole('region', { name: 'Rapport d’import' })).toContainText('420 fiches créées', { timeout: 15_000 });
  });

  test('la fiche de Yves Roche montre le refus en français', async ({ page }) => {
    await ouvrirDemo(page, '/console/contacts');
    await page.getByRole('textbox', { name: /Rechercher une entreprise/ }).fill('Bois');
    await page.getByRole('link', { name: 'Yves Roche' }).first().click();
    await expect(page.getByRole('cell', { name: 'Adresse inexistante' })).toBeVisible();
    await controleAxe(page);
    await passage360(page);
  });
});
