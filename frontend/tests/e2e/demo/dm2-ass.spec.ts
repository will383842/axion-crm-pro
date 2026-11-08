/**
 * DM2-ASS — la création de campagne en 5 étapes, les messages types et
 * l'éditeur, en démo (faux serveur dans le navigateur, rien ne sort).
 *
 * Tâche T4 : « une campagne vers l'étiquette, deux objets, demain 9 h », en
 * 15 clics au plus et 5 minutes au plus.
 */
import { test, expect, type Page } from '@playwright/test';
import { controleAxe, ouvrirDemo, passage360 } from './outils';

/** Compte les clics réels de l'utilisateur (pas ceux du code de la page). */
async function compterLesClics(page: Page): Promise<void> {
  await page.evaluate(() => {
    const fenetre = window as unknown as { __clics: number };
    fenetre.__clics = 0;
    document.addEventListener(
      'click',
      (e) => {
        if (e.isTrusted) fenetre.__clics += 1;
      },
      true,
    );
  });
}

async function clics(page: Page): Promise<number> {
  return page.evaluate(() => (window as unknown as { __clics: number }).__clics);
}

test.describe('création de campagne (DM2-ASS)', () => {
  test('T4 : une campagne vers l’étiquette, deux objets, demain 9 h, en 15 clics et 5 minutes', async ({ page }) => {
    const debut = Date.now();
    await ouvrirDemo(page, '/envoi/campagnes/nouvelle?etiquette=salon-artisans-2026', { offre: 'les-deux' });
    await compterLesClics(page);

    // 1. Cible : pré-remplie par l'étiquette.
    await expect(page.getByRole('radio', { name: /salon-artisans-2026/ })).toBeChecked();
    await expect(page.getByText(/46 adresses retirées d’office/)).toBeVisible();
    await page.getByRole('button', { name: /Continuer : message/ }).click();

    // 2. Message : deux objets (A/B).
    await page.getByRole('switch', { name: /Tester deux objets/ }).check();
    await expect(page.getByRole('textbox', { name: 'Objet · version B' })).not.toHaveValue('');
    await page.getByRole('button', { name: /Continuer : quand/ }).click();

    // 3. Quand : demain 9 h.
    await page.getByRole('button', { name: 'Demain 9:00' }).click();
    await expect(page.getByLabel('Jour', { exact: true })).toHaveValue('2027-01-13');
    await page.getByRole('button', { name: /Continuer : vérification/ }).click();

    // 4. Contrôles au vert.
    await expect(page.getByText('Tous les contrôles sont au vert')).toBeVisible();
    await page.getByRole('button', { name: /Continuer : lancement/ }).click();

    // 5. Code par e-mail, puis validation.
    await page.getByRole('button', { name: 'Envoyer le code par e-mail' }).click();
    const code = (await page.getByTestId('code-demo').innerText()).trim();
    await page.getByLabel('Code reçu par e-mail').fill(code);
    await page.getByRole('button', { name: 'Valider et programmer' }).click();
    await expect(page.getByText(/Campagne programmée/)).toBeVisible();

    expect(await clics(page)).toBeLessThanOrEqual(15);
    expect(Date.now() - debut).toBeLessThanOrEqual(5 * 60_000);

    // Le geste se voit après rechargement (la campagne est dans la liste de la démo).
    await page.reload();
    await expect(page.getByRole('region', { name: 'Démonstration' })).toBeVisible();
  });

  test('le brouillon est enregistré tout seul et se retrouve au rechargement', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/campagnes/nouvelle?etiquette=salon-artisans-2026');
    await page.getByRole('textbox', { name: 'Nom de la campagne' }).fill('Ma campagne de test');
    await expect(page.getByText(/Brouillon enregistré/)).toBeVisible();
    await page.reload();
    await expect(page.getByRole('textbox', { name: 'Nom de la campagne' })).toHaveValue('Ma campagne de test');
  });

  test('étape 1 : accessibilité, téléphone, les 4 états', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/campagnes/nouvelle?etiquette=salon-artisans-2026');
    await expect(page.getByRole('radio', { name: /salon-artisans-2026/ })).toBeChecked();
    await controleAxe(page);
    await passage360(page);

    await ouvrirDemo(page, '/envoi/campagnes/nouvelle', { etat: 'vide' });
    await expect(page.getByText('Rien à cibler')).toBeVisible();
    await ouvrirDemo(page, '/envoi/campagnes/nouvelle', { etat: 'erreur' });
    await expect(page.getByRole('alert')).toBeVisible();
    await ouvrirDemo(page, '/envoi/campagnes/nouvelle', { etat: 'chargement' });
    await expect(page.getByRole('status', { name: 'Chargement en cours' })).toBeVisible();
  });
});

test.describe('messages types et éditeur (DM2-ASS)', () => {
  test('la liste des messages types : valider une version, archiver sans jamais supprimer', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/campagnes/messages');
    await expect(page.getByRole('heading', { level: 1, name: 'Messages types' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Relance douce', exact: true })).toBeVisible();
    await controleAxe(page);
    await passage360(page);

    await page.getByRole('button', { name: 'Archiver Relance douce' }).click();
    await expect(page.getByRole('link', { name: 'Relance douce', exact: true })).toHaveCount(0);
    await page.reload();
    await expect(page.getByRole('link', { name: 'Relance douce', exact: true })).toHaveCount(0);
    await page.getByRole('checkbox', { name: 'Afficher les messages archivés' }).check();
    await expect(page.getByRole('link', { name: 'Relance douce', exact: true })).toBeVisible();
  });

  test('l’éditeur : mot interdit détecté, nouvelle version, test sans envoi réel', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/campagnes/messages/msg-3');
    await expect(page.getByRole('textbox', { name: 'Texte' })).toBeVisible();
    await controleAxe(page);

    await page.getByRole('textbox', { name: 'Texte' }).pressSequentially(' Offre gratuite.');
    await expect(page.getByText(/Mot interdit : « gratuit »/)).toBeVisible();

    await page.getByRole('button', { name: /M’envoyer un test/ }).click();
    await page.getByRole('button', { name: 'Envoyer le test' }).click();
    await expect(page.getByText(/Test envoyé à \d+ adresses? de test/)).toBeVisible();

    await passage360(page);
  });

  test('les 4 états de la liste et de l’éditeur', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/campagnes/messages', { etat: 'vide' });
    await expect(page.getByText('Aucun message type')).toBeVisible();
    await ouvrirDemo(page, '/envoi/campagnes/messages', { etat: 'erreur' });
    await expect(page.getByRole('alert')).toBeVisible();
    await ouvrirDemo(page, '/envoi/campagnes/messages/msg-1', { etat: 'erreur' });
    await expect(page.getByRole('alert')).toBeVisible();
  });
});
