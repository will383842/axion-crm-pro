/**
 * DM2-REP — démo de la boîte des réponses d'Envoi et des réponses à rattacher.
 * Jouée par `.github/workflows/demo.yml` sur le build de démo.
 *
 *  · P6  : traiter la réponse de Paul Morin en 2 clics (l'ouvrir, appliquer la suggestion) ;
 *  · T7  : répondre à Paul Morin depuis le téléphone (360 px) ;
 *  · STOP confirmé : la personne est mise à l'écart, les données restent ;
 *  · à rattacher : rattacher une réponse sans fiche ;
 *  · les 4 états, l'accessibilité et le 360 px.
 */
import { test, expect } from '@playwright/test';
import { controleAxe, ouvrirDemo, passage360 } from './outils';

test.describe('DM2-REP — réponses', () => {
  test('P6 : traiter la réponse de Paul Morin en 2 clics', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/reponses', { offre: 'les-deux' });
    await expect(page.getByRole('heading', { level: 1, name: 'Réponses' })).toBeVisible();
    await page.getByRole('list', { name: 'Réponses à traiter' }).getByRole('button', { name: /Paul Morin/ }).click();
    await page.getByRole('button', { name: 'Appliquer la suggestion' }).click();
    await expect(page.getByRole('status', { name: 'Résumé envoyé au CRM' })).toContainText('Tâche CRM');
    await controleAxe(page);

    // Le geste survit au rechargement (la démo garde la session).
    await page.reload();
    await expect(page.getByRole('list', { name: 'Réponses à traiter' }).getByRole('button', { name: /Paul Morin/ })).toHaveCount(0);
  });

  test('T7 : répondre à Paul Morin à 360 px', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 780 });
    await ouvrirDemo(page, '/envoi/reponses', { offre: 'envoi' });
    await page.getByRole('list', { name: 'Réponses à traiter' }).getByRole('button', { name: /Paul Morin/ }).click();
    await passage360(page);
    const zone = page.getByRole('textbox', { name: 'Votre réponse' });
    await zone.fill('Bonjour Paul, avec plaisir. Je vous appelle jeudi à 10 h.');
    const envoyer = page.getByRole('button', { name: 'Envoyer la réponse' });
    const boite = await envoyer.boundingBox();
    expect(boite?.height ?? 0).toBeGreaterThanOrEqual(44);
    await envoyer.click();
    await expect(page.getByText('Bonjour Paul, avec plaisir. Je vous appelle jeudi à 10 h.').first()).toBeVisible();
    await passage360(page);
  });

  test('STOP confirmé : mis à l’écart des envois, rien n’est supprimé', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/reponses', { offre: 'envoi' });
    await page.getByRole('list', { name: 'Réponses à traiter' }).getByRole('button', { name: /Paul Morin/ }).click();
    await page.getByRole('button', { name: /STOP/ }).click();
    const dialogue = page.getByRole('dialog', { name: /à l’écart des envois/ });
    await expect(dialogue).toContainText('données sont conservées');
    await dialogue.getByRole('button', { name: 'Confirmer le STOP' }).click();
    await expect(page.getByRole('status', { name: 'Résumé envoyé au CRM' })).toContainText('écart');
    await page.getByRole('link', { name: /Traitées/ }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Réponses traitées' })).toBeVisible();
    await expect(page.getByText('Paul Morin').first()).toBeVisible();
  });

  test('à rattacher : rattacher une réponse à une fiche', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/reponses/a-rattacher', { offre: 'envoi' });
    await expect(page.getByRole('heading', { level: 1, name: 'Réponses à rattacher' })).toBeVisible();
    const carte = page.getByRole('article', { name: /p\.morin@boulangerie-tilleuls\.example/ });
    await expect(carte).toBeVisible();
    await controleAxe(page);
    await passage360(page);
    await carte.getByRole('listitem', { name: /Paul Morin/ }).getByRole('button', { name: /Rattacher/ }).click();
    await expect(page.getByRole('heading', { name: 'Rien à rattacher' })).toBeVisible();
  });

  test('les états vide et erreur sont propres', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/reponses', { offre: 'envoi', etat: 'vide' });
    await expect(page.getByRole('heading', { name: 'Aucune réponse à traiter' })).toBeVisible();
    await controleAxe(page);
    await ouvrirDemo(page, '/envoi/reponses', { offre: 'envoi', etat: 'erreur' });
    await expect(page.getByRole('alert')).toBeVisible();
    await ouvrirDemo(page, '/envoi/reponses/traitees', { offre: 'envoi' });
    await expect(page.getByRole('heading', { level: 1, name: 'Réponses traitées' })).toBeVisible();
    await passage360(page);
  });
});
