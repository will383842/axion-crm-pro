/** DM3 — import des listes de refus (F-LN) : simulation, confirmation, aucune adresse réaffichée, fichier trop gros refusé. Jouée par `demo.yml`. */
import { test, expect, type Page } from '@playwright/test';
import { controleAxe, ouvrirDemo } from './outils';

const CONTENU = 'import-un@societe-fictive-a.example.test\n=piege@societe-fictive-b.example.test\ngmail.com\nimport-deux@societe-fictive-c.example.test\n';

async function ouvrirImport(page: Page) {
  await ouvrirDemo(page, '/envoi/desinscriptions', { offre: 'les-deux' });
  await page.getByRole('button', { name: 'Importer une liste de refus' }).click();
  return page.getByRole('dialog');
}

const choisir = (page: Page, contenu: string) =>
  page.getByLabel(/Fichier de la liste/).setInputFiles({ name: 'liste.csv', mimeType: 'text/csv', buffer: Buffer.from(contenu) });

test.describe('listes de refus (démo)', () => {
  test('H1 : choisir un fichier, simuler ⇒ compte rendu (valides, déjà présentes, refusées par numéro de ligne)', async ({ page }) => {
    const fenetre = await ouvrirImport(page);
    await choisir(page, CONTENU);
    await fenetre.getByRole('button', { name: 'Simuler l’import' }).click();
    await expect(fenetre).toContainText('2 adresses valides');
    await expect(fenetre).toContainText('0 déjà présente');
    await expect(fenetre).toContainText('2 refusées');
    await expect(fenetre).toContainText('Ligne 2');
    await expect(fenetre).toContainText('Ligne 3');
    await controleAxe(page);
  });

  test('H2 : confirmer ⇒ le total du registre augmente', async ({ page }) => {
    const fenetre = await ouvrirImport(page);
    const total = async () => Number((await page.getByText(/ oppositions, jamais effacées/).first().textContent())?.match(/(\d+) oppositions/)?.[1]);
    const avant = await total();
    await choisir(page, CONTENU);
    await fenetre.getByRole('button', { name: 'Simuler l’import' }).click();
    await expect(fenetre).toContainText('2 adresses valides');
    await fenetre.getByRole('button', { name: 'Confirmer l’import' }).click();
    await expect(fenetre).toContainText('Import confirmé');
    await fenetre.getByRole('button', { name: 'Fermer' }).click();
    await expect.poll(total).toBe(avant + 2);
  });

  test('H3 : aucune adresse du fichier n’est réaffichée à l’écran', async ({ page }) => {
    const fenetre = await ouvrirImport(page);
    await choisir(page, CONTENU);
    await fenetre.getByRole('button', { name: 'Simuler l’import' }).click();
    await expect(fenetre).toContainText('2 adresses valides');
    await expect(fenetre).not.toContainText(/societe-fictive|gmail\.com/);
    await expect(page.locator('body')).not.toContainText(/ouverture/i);
  });

  test('H4 : un fichier trop gros est refusé', async ({ page }) => {
    const fenetre = await ouvrirImport(page);
    await choisir(page, 'a'.repeat(1_048_577));
    await expect(fenetre.getByRole('alert')).toContainText('trop gros');
    await expect(fenetre.getByRole('button', { name: 'Simuler l’import' })).toBeDisabled();
  });
});
