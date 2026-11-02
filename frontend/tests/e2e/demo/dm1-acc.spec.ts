/**
 * DM1-ACC — tableau de bord, Mes tâches, Affaires, carte « Envoi aujourd'hui ».
 * Jouée par `.github/workflows/demo.yml` sur le build de démo (offre « les deux »).
 */
import { test, expect } from '@playwright/test';
import { controleAxe, ouvrirDemo, passage360 } from './outils';

test.describe('DM1-ACC — accueil de la démo', () => {
  test('tableau de bord → Mes tâches → Affaires par les onglets', async ({ page }) => {
    await ouvrirDemo(page, '/', { offre: 'les-deux' });
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

    const onglets = page.getByRole('navigation', { name: 'Tableau de bord' });
    await onglets.getByRole('link', { name: /Mes tâches/ }).click();
    await expect(page).toHaveURL(/\/taches$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Mes tâches' })).toBeVisible();
    await expect(page.getByRole('region', { name: /En retard/ })).toBeVisible();
    await controleAxe(page);

    await onglets.getByRole('link', { name: /Affaires/ }).click();
    await expect(page).toHaveURL(/\/affaires$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Affaires' })).toBeVisible();
    await expect(page.getByRole('region', { name: /^Devis/ })).toBeVisible();
    await controleAxe(page);
  });

  test('cocher une tâche agit et survit au rechargement', async ({ page }) => {
    await ouvrirDemo(page, '/taches');
    // L'écran réagit tout de suite ; on attend que le faux serveur ait enregistré le geste.
    const enregistre = page.waitForResponse((r) => r.request().method() === 'PATCH' && r.url().includes('/crm/taches/'));
    await page.getByRole('checkbox', { name: /Rappeler Paul Morin/ }).check();
    await enregistre;
    await expect(page.getByRole('region', { name: /Faites/ }).getByText('Rappeler Paul Morin')).toBeVisible();

    await page.reload();
    await expect(page.getByRole('region', { name: /Faites/ }).getByText('Rappeler Paul Morin')).toBeVisible();
  });

  test('déplacer une affaire au clavier, puis la perdre avec une raison', async ({ page }) => {
    await ouvrirDemo(page, '/affaires');
    const liste = page.getByRole('combobox', { name: /Déplacer Boulangerie des Tilleuls/ });
    await liste.selectOption({ label: 'Rendez-vous' });
    await expect(page.getByRole('region', { name: /^Rendez-vous/ }).getByText('Boulangerie des Tilleuls')).toBeVisible();

    await page.getByRole('combobox', { name: /Déplacer Imprimerie du Lac/ }).selectOption({ label: 'Perdu' });
    const dialogue = page.getByRole('dialog');
    await dialogue.getByRole('combobox', { name: /Raison de la perte/ }).selectOption({ label: 'Prix trop élevé' });
    await dialogue.getByRole('button', { name: 'Confirmer la perte' }).click();
    await expect(page.getByRole('region', { name: /^Perdu/ }).getByText('Imprimerie du Lac')).toBeVisible();
  });

  test('la carte « Envoi aujourd’hui » suit l’offre', async ({ page }) => {
    await ouvrirDemo(page, '/', { offre: 'les-deux' });
    await expect(page.getByTestId('carte-envoi-aujourdhui')).toBeVisible();

    await ouvrirDemo(page, '/', { offre: 'crm' });
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.getByTestId('carte-envoi-aujourdhui')).toHaveCount(0);
  });

  test('les quatre états : vide, erreur', async ({ page }) => {
    await ouvrirDemo(page, '/taches', { etat: 'vide' });
    await expect(page.getByText(/Rien à faire aujourd.hui/)).toBeVisible();
    await ouvrirDemo(page, '/affaires', { etat: 'vide' });
    await expect(page.getByText('Aucune affaire')).toBeVisible();
    await ouvrirDemo(page, '/affaires', { etat: 'erreur' });
    await expect(page.getByRole('button', { name: /Réessayer/ })).toBeVisible();
  });

  for (const chemin of ['/', '/taches', '/affaires']) {
    test(`téléphone 360 px : ${chemin} ne défile pas horizontalement`, async ({ page }) => {
      await ouvrirDemo(page, chemin, { offre: 'les-deux' });
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
      await passage360(page);
      await controleAxe(page);
    });
  }

  // Captures de la PR (1440 et 360 px), déposées par `demo.yml` en artefact « captures-demo ».
  test('captures 1440 et 360 px : tableau de bord, Mes tâches, Affaires', async ({ page }) => {
    for (const [largeur, hauteur] of [[1440, 900], [360, 780]] as const) {
      await page.setViewportSize({ width: largeur, height: hauteur });
      for (const [nom, chemin] of [['dm1-acc-tableau-de-bord', '/'], ['dm1-acc-mes-taches', '/taches'], ['dm1-acc-affaires', '/affaires']] as const) {
        await ouvrirDemo(page, chemin, { offre: 'les-deux' });
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await page.waitForLoadState('networkidle');
        await page.screenshot({ path: `captures-demo/${nom}-${largeur}.png`, fullPage: true });
      }
    }
  });

  test('la date de l’accueil est celle de la démo : mardi 12 janvier', async ({ page }) => {
    await ouvrirDemo(page, '/', { offre: 'les-deux' });
    await expect(page.getByText(/Mardi 12 janvier/)).toBeVisible();
  });
});
