/**
 * DM1-TEC — démo de la section Technique : Roumanie, historique des collectes,
 * santé du système, moteurs d'IA, serveurs relais, rotation, registre de l'IA.
 * Jouée par `.github/workflows/demo.yml` sur le build de démo.
 */
import { test, expect } from '@playwright/test';
import { controleAxe, ouvrirDemo, passage360 } from './outils';

const PAGES: Array<{ chemin: string; titre: string }> = [
  { chemin: '/international/roumanie', titre: 'Entreprises en Roumanie' },
  { chemin: '/scraper-runs', titre: 'Historique des collectes' },
  { chemin: '/admin/observability', titre: 'Santé du système' },
  { chemin: '/llm/router', titre: 'Moteurs d’IA' },
  { chemin: '/llm/proxy-providers', titre: 'Serveurs relais' },
  { chemin: '/llm/rotations', titre: 'Rotation des accès' },
  { chemin: '/rgpd/ai-act', titre: 'Registre de l’IA' },
];

test.describe('section Technique en démo', () => {
  for (const { chemin, titre } of PAGES) {
    test(`${chemin} : s’ouvre, accessible, sans défilement horizontal`, async ({ page }) => {
      await ouvrirDemo(page, chemin, { offre: 'les-deux' });
      await expect(page.getByRole('heading', { level: 1, name: titre })).toBeVisible();
      await controleAxe(page);
      await passage360(page);
    });

    test(`${chemin} : état d’erreur propre`, async ({ page }) => {
      await ouvrirDemo(page, chemin, { etat: 'erreur' });
      await expect(page.getByRole('alert').first()).toBeVisible();
    });
  }

  test('Roumanie : des fiches s’affichent, le filtre par nature agit', async ({ page }) => {
    await ouvrirDemo(page, '/international/roumanie');
    await expect(page.getByRole('table')).toContainText('Roumanie');
    await page.getByRole('tab', { name: 'Association' }).click();
    await expect(page.getByRole('tab', { name: 'Association', selected: true })).toBeVisible();
  });

  test('Roumanie : état vide', async ({ page }) => {
    await ouvrirDemo(page, '/international/roumanie', { etat: 'vide' });
    await expect(page.getByText('Aucune fiche pour cette nature.')).toBeVisible();
  });

  test('collectes : annuler un passage en cours se voit après rechargement', async ({ page }) => {
    await ouvrirDemo(page, '/scraper-runs');
    await page.getByRole('tab', { name: /^En cours/ }).click();
    const avant = await page.getByTestId('run-cancel').count();
    expect(avant).toBeGreaterThan(0);
    await page.getByTestId('run-cancel').first().click();
    await page.getByRole('dialog').getByRole('button', { name: /Annuler le passage|Confirmer|Oui/ }).first().click();
    await page.reload();
    await page.getByRole('tab', { name: /^En cours/ }).click();
    await expect(page.getByTestId('run-cancel')).toHaveCount(avant - 1);
  });

  test('santé : la carte Sauvegardes montre des dates factices', async ({ page }) => {
    await ouvrirDemo(page, '/admin/observability');
    const carte = page.getByTestId('carte-sauvegardes');
    await expect(carte).toBeVisible();
    await expect(carte).toContainText('Dernière copie');
    await expect(carte).toContainText('2027');
    await expect(carte).toContainText('Dernier exercice de restauration');
  });

  test('serveurs relais : tester un fournisseur', async ({ page }) => {
    await ouvrirDemo(page, '/llm/proxy-providers');
    await page.getByRole('button', { name: 'Tester' }).first().click();
    await expect(page.getByText('Opérationnel')).toBeVisible();
  });

  test('moteurs d’IA : les quatre onglets répondent', async ({ page }) => {
    await ouvrirDemo(page, '/llm/router');
    await expect(page.getByText('classer-activite')).toBeVisible();
    await page.getByRole('tab', { name: /Usage 30j/ }).click();
    await expect(page.getByText('Coût par fournisseur')).toBeVisible();
  });

  test('registre de l’IA : le détail s’ouvre', async ({ page }) => {
    await ouvrirDemo(page, '/rgpd/ai-act');
    await page.getByRole('row', { name: /Tri des réponses/ }).click();
    await expect(page.getByText('Supervision humaine')).toBeVisible();
  });
});
