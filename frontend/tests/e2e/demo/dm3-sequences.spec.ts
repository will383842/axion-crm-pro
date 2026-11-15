/**
 * F-SEQ — démo d'Envoi : séquences. B1 liste puis fiche (étapes, compteurs) ; B2 pause avec
 * confirmation ; B3 reprise sous frein refusée avec la raison en clair ; B4 boutons de geste
 * selon l'état. Le compte de démo est propriétaire (`/auth/me` ne se change pas, et une route
 * ne peut pas être déclarée deux fois) : le masquage par PERMISSION (lecteur, administrateur)
 * est joué dans `tests/screens/envoi/SequencesPage.test.tsx`. Aucune personne affichée.
 */
import { test, expect } from '@playwright/test';
import { controleAxe, ouvrirDemo, passage360 } from './outils';

test.describe('démo — séquences (F-SEQ)', () => {
  test('B1 · la liste puis la fiche montrent les étapes et les compteurs', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/sequences', { offre: 'envoi' });
    await expect(page.getByRole('heading', { level: 1, name: 'Séquences' })).toBeVisible();
    await expect(page.getByRole('row', { name: /Relance courte/ }).getByText('En pause')).toBeVisible();
    await page.getByRole('link', { name: 'Présentation en trois messages (démo)' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Présentation en trois messages (démo)' })).toBeVisible();
    const etapes = page.getByRole('table', { name: 'Étapes' });
    await expect(etapes.getByRole('row')).toHaveCount(4);
    await expect(etapes.getByText('Une question sur votre activité')).toBeVisible();
    await expect(page.getByTestId('inscriptions-en-cours')).toContainText('80');
    await controleAxe(page);
    await passage360(page);
  });

  test('B2 · la pause demande une confirmation puis le statut devient « En pause »', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/sequences/seq-01', { offre: 'envoi' });
    await page.getByRole('button', { name: 'Mettre en pause' }).click();
    const dialogue = page.getByRole('dialog').filter({ has: page.getByRole('heading', { name: 'Mettre en pause ?' }) });
    await dialogue.getByRole('button', { name: 'Mettre en pause' }).click();
    await expect(page.getByText('En pause', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Reprendre' })).toBeVisible();
  });

  test('B3 · la reprise sous frein est refusée avec la raison, le statut ne change pas', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/sequences/seq-04', { offre: 'envoi' });
    await page.getByRole('button', { name: 'Reprendre' }).click();
    await expect(page.getByRole('alert')).toContainText('Un frein est actif : la reprise est refusée tant qu’il dure.');
    await expect(page.getByText('En pause', { exact: true })).toBeVisible();
  });

  test('B4 · seul le geste qui a un sens est proposé', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/sequences/seq-01', { offre: 'envoi' });
    await expect(page.getByRole('button', { name: 'Mettre en pause' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Reprendre' })).toHaveCount(0);
    await ouvrirDemo(page, '/envoi/sequences/seq-02', { offre: 'envoi' });
    await expect(page.getByRole('button', { name: 'Reprendre' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Mettre en pause' })).toHaveCount(0);
  });
});
