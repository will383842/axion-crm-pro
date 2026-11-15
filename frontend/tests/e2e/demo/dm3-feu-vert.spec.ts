/**
 * F-FV — démo d'Envoi : feu vert final. F1 points vert ou rouge avec leur raison ; F2 « Accorder »
 * désactivé tant qu'un bloquant est rouge ; F3 démo « sans bloquant » : accorder avec un motif,
 * décision visible, mention « n'active pas l'envoi réel » ; F4 « Retirer » toujours possible ;
 * F5 rechargement : la décision reste. Le compte de démo est propriétaire : le masquage par
 * permission est joué en Vitest (`tests/screens/envoi/FeuVertPage.test.tsx`). Aucune personne affichée.
 */
import { test, expect } from '@playwright/test';
import { controleAxe, ouvrirDemo, passage360 } from './outils';

const PAGE = '/envoi/sante/feu-vert';

test.describe('démo — feu vert final (F-FV)', () => {
  test('F1 · chaque point est affiché avec son état et sa raison', async ({ page }) => {
    await ouvrirDemo(page, PAGE, { offre: 'envoi' });
    const banc = page.getByRole('listitem').filter({ hasText: 'Banc des boîtes témoins complet' });
    await expect(banc).toContainText('À faire');
    await expect(banc).toContainText('4 fournisseurs sur 6');
    await expect(page.getByRole('listitem').filter({ hasText: 'Domaines : DNS au vert' })).toContainText('Fait');
    await controleAxe(page);
    await passage360(page);
  });

  test('F2 · « Accorder » est désactivé tant qu’un bloquant est rouge', async ({ page }) => {
    await ouvrirDemo(page, PAGE, { offre: 'envoi' });
    await expect(page.getByRole('button', { name: 'Accorder le feu vert' })).toBeDisabled();
    await expect(page.getByText(/à lever avant d’accorder/)).toBeVisible();
    await expect(page.getByText(/n’active pas l’envoi réel/).first()).toBeVisible();
  });

  test('F3 · sans bloquant : accorder avec un motif, la décision est visible', async ({ page }) => {
    await ouvrirDemo(page, `${PAGE}?demo-feu-vert=sans-bloquant`, { offre: 'envoi' });
    await page.getByRole('button', { name: 'Accorder le feu vert' }).click();
    const dialogue = page.getByRole('dialog');
    await expect(dialogue.getByText(/n’active pas l’envoi réel/)).toBeVisible();
    await expect(dialogue.getByRole('button', { name: 'Confirmer l’accord' })).toBeDisabled();
    await dialogue.getByLabel('Motif').fill('Tout a été relu avec le fondateur.');
    await dialogue.getByRole('button', { name: 'Confirmer l’accord' }).click();
    const bloc = page.getByRole('region', { name: 'Décision finale' });
    await expect(bloc).toContainText('Accordé');
    await expect(bloc).toContainText('Tout a été relu avec le fondateur.');
  });

  test('F4 · « Retirer » reste possible, même sans bloquant levé', async ({ page }) => {
    await ouvrirDemo(page, PAGE, { offre: 'envoi' });
    await page.getByRole('button', { name: 'Retirer le feu vert' }).click();
    const dialogue = page.getByRole('dialog');
    await dialogue.getByLabel('Motif').fill('Arrêt demandé par prudence.');
    await dialogue.getByRole('button', { name: 'Confirmer le retrait' }).click();
    await expect(page.getByRole('region', { name: 'Décision finale' })).toContainText('Retiré');
  });

  test('F5 · après rechargement, la décision reste', async ({ page }) => {
    await ouvrirDemo(page, `${PAGE}?demo-feu-vert=sans-bloquant`, { offre: 'envoi' });
    await page.getByRole('button', { name: 'Retirer le feu vert' }).click();
    await page.getByRole('dialog').getByLabel('Motif').fill('Arrêt demandé par prudence.');
    await page.getByRole('dialog').getByRole('button', { name: 'Confirmer le retrait' }).click();
    await expect(page.getByRole('region', { name: 'Décision finale' })).toContainText('Retiré');
    await page.reload();
    await expect(page.getByRole('region', { name: 'Décision finale' })).toContainText('Arrêt demandé par prudence.');
  });
});
