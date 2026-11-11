/**
 * DM2-INF — démo des boîtes, domaines, serveurs, configuration et chauffe.
 * Jouée par `.github/workflows/demo.yml` sur le build de démo (Chromium).
 *
 *  - P5 : Ctrl K, « Ajouter une adresse IP », « Ajouter et appliquer » (3 clics),
 *    et le geste survit au rechargement ;
 *  - P9 : « Tout arrêter » en 2 clics (le bouton, puis la confirmation) ;
 *  - chaque page du lot s'ouvre, passe axe (0 violation critique) et le
 *    téléphone (360 px sans défilement horizontal) ;
 *  - un domaine ajouté et une boîte ajoutée apparaissent après rechargement.
 *
 * La tâche T6 du cahier (« créer l'espace d'un nouveau client ») appartient à
 * un autre lot : elle n'est pas jouée ici.
 */
import { expect, test } from '@playwright/test';
import { controleAxe, ouvrirDemo, passage360 } from './outils';

const PAGES: ReadonlyArray<[string, string]> = [
  ['/envoi/boites', 'Boîtes d’envoi'],
  ['/envoi/boites/boi-camille', 'camille@prospection-b.example'],
  ['/envoi/domaines', 'Domaines'],
  ['/envoi/domaines/dom-b', 'prospection-b.example'],
  ['/envoi/serveurs', 'Serveurs et adresses IP'],
  ['/envoi/serveurs/configuration', 'Configuration'],
  ['/envoi/chauffe', 'Chauffe'],
];

test.describe('DM2-INF — infrastructure d’envoi', () => {
  for (const [chemin, titre] of PAGES) {
    test(`${chemin} s’ouvre, passe axe et le téléphone`, async ({ page }) => {
      await ouvrirDemo(page, chemin, { offre: 'envoi' });
      await expect(page.getByRole('heading', { level: 1, name: titre })).toBeVisible();
      await expect(page.getByTestId('page-en-attente')).toHaveCount(0);
      await controleAxe(page);
      await passage360(page);
    });
  }

  test('P5 : la recherche (Ctrl K), Ajouter une adresse IP, Ajouter et appliquer (3 clics)', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/boites', { offre: 'envoi' });
    // Clic 1 : la recherche (le raccourci Ctrl K ouvre la même palette).
    await page.getByRole('button', { name: 'Recherche globale' }).click();
    await page.getByPlaceholder(/Rechercher/).fill('adresse IP');
    await page.getByRole('option', { name: /Ajouter une adresse IP/ }).click();
    await expect(page).toHaveURL(/\/envoi\/serveurs/);
    const fenetre = page.getByRole('dialog');
    await expect(fenetre).toBeVisible();
    await expect(fenetre.getByLabel('Adresse IP')).toHaveValue('192.0.2.12');
    await fenetre.getByRole('button', { name: 'Ajouter et appliquer' }).click();
    await expect(fenetre).toBeHidden();
    const tableau = page.getByRole('table', { name: /Adresses IP de Serveur d’envoi 1/ });
    await expect(tableau.getByText('192.0.2.12')).toBeVisible();
    // Le geste a modifié la base de la démo : il se voit après rechargement.
    await page.reload();
    await expect(page.getByRole('table', { name: /Adresses IP de Serveur d’envoi 1/ }).getByText('192.0.2.12')).toBeVisible();
  });

  test('une adresse hors des plages de documentation est refusée, en français', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/serveurs', { offre: 'envoi' });
    await page.getByRole('button', { name: 'Ajouter une adresse IP' }).click();
    const fenetre = page.getByRole('dialog');
    await fenetre.getByLabel('Adresse IP').fill(['10', '20', '30', '40'].join('.'));
    await fenetre.getByRole('button', { name: 'Ajouter et appliquer' }).click();
    await expect(fenetre.getByRole('alert')).toContainText('plages de documentation');
  });

  test('P9 : Tout arrêter en 2 clics', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/boites', { offre: 'envoi' });
    await page.getByRole('button', { name: 'Tout arrêter' }).click();
    await page.getByRole('button', { name: 'Arrêter tous les envois' }).click();
    await expect(page.getByRole('button', { name: /Reprendre/ }).first()).toBeVisible();
  });

  test('un domaine ajouté reste après rechargement, avec ses DNS à créer', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/domaines', { offre: 'envoi' });
    await page.getByRole('button', { name: 'Ajouter un domaine' }).click();
    const fenetre = page.getByRole('dialog');
    await fenetre.getByLabel('Nom du domaine').fill('prospection-e.example');
    await fenetre.getByRole('button', { name: 'Ajouter le domaine' }).click();
    await expect(fenetre.getByRole('list', { name: 'Enregistrements DNS' })).toBeVisible();
    await fenetre.getByRole('button', { name: 'Terminer' }).click();
    await page.reload();
    await expect(page.getByText('prospection-e.example')).toBeVisible();
  });

  test('DMARC progressif : la fiche avance et la liste noire a sa fiche « que faire »', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/domaines/dom-b', { offre: 'envoi' });
    await expect(page.getByText('passer à quarantaine le 26 janv.')).toBeVisible();
    await expect(page.getByRole('link', { name: /Demander le retrait/ })).toBeVisible();
    await page.getByRole('button', { name: /Passer à « quarantaine » maintenant/ }).click();
    await expect(page.getByText('passer à rejet le 9 févr.')).toBeVisible();
    await page.reload();
    await expect(page.getByText('passer à rejet le 9 févr.')).toBeVisible();
  });

  test('configuration : appliquer la version prête, puis revenir en arrière', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/serveurs/configuration', { offre: 'envoi' });
    await page.getByRole('button', { name: 'Appliquer la version 15' }).click();
    await expect(page.getByText('Version 15 en service')).toBeVisible();
    await page.getByRole('button', { name: 'Revenir à la version 14' }).click();
    await expect(page.getByText('Version 16 en service')).toBeVisible();
  });

  test('chauffe : reculer d’un palier ramène le domaine au palier précédent', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/chauffe', { offre: 'envoi' });
    const carte = page.getByRole('region', { name: 'Chauffe de prospection-c.example' });
    await carte.getByRole('button', { name: /Reculer d’un palier/ }).click();
    await expect(carte.getByText('palier P1, jour 4').first()).toBeVisible();
  });

  test('états vide et erreur, propres', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/boites', { offre: 'envoi', etat: 'vide' });
    await expect(page.getByRole('heading', { name: 'Aucune boîte d’envoi' })).toBeVisible();
    await ouvrirDemo(page, '/envoi/domaines', { offre: 'envoi', etat: 'erreur' });
    await expect(page.getByRole('button', { name: /Réessayer/ })).toBeVisible();
  });

  // Captures de la PR (1440 et 360 px), déposées par `demo.yml` en artefact « captures-demo ».
  test('captures 1440 et 360 px des sept pages', async ({ page }) => {
    for (const [largeur, hauteur] of [[1440, 900], [360, 780]] as const) {
      await page.setViewportSize({ width: largeur, height: hauteur });
      for (const [chemin, titre] of PAGES) {
        await ouvrirDemo(page, chemin, { offre: 'envoi' });
        await expect(page.getByRole('heading', { level: 1, name: titre })).toBeVisible();
        await page.waitForLoadState('networkidle');
        const nom = chemin.replace(/^\/envoi\//, '').replace(/\//g, '-');
        await page.screenshot({ path: `captures-demo/dm2-inf-${nom}-${largeur}.png`, fullPage: true });
      }
    }
  });
});
