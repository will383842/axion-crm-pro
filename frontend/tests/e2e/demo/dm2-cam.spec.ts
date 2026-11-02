/**
 * DM2-CAM — démo d'Envoi : liste des campagnes, calendrier, suivi d'une campagne.
 * Parcours P2 (suivre les campagnes), P3 (agir depuis la liste), P7 (retrouver
 * un destinataire) et tâche T5 : pause depuis la liste, changer la date,
 * retrouver Yves Roche, en moins de 4 minutes. Jouée par `.github/workflows/demo.yml`.
 */
import { test, expect } from '@playwright/test';
import { appelerApi, controleAxe, ouvrirDemo, passage360 } from './outils';

interface PageCampagnes {
  data: Array<{ id: string; nom: string; statut: string; prochain_envoi_le: string | null }>;
}

test.describe('démo — campagnes (DM2-CAM)', () => {
  test('P2 · la liste montre les campagnes et leurs statuts, sans accessibilité cassée', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/campagnes', { offre: 'les-deux' });
    await expect(page.getByRole('heading', { level: 1, name: 'Campagnes' })).toBeVisible();
    await expect(page.getByRole('row', { name: /Organisateurs Rhône-Alpes/ })).toBeVisible();
    await expect(page.getByRole('tab', { name: /En pause/ })).toBeVisible();
    await controleAxe(page);
  });

  test('T5 · pause depuis la liste, nouvelle date, puis Yves Roche, en moins de 4 minutes', async ({ page }) => {
    const debut = Date.now();
    await ouvrirDemo(page, '/envoi/campagnes', { offre: 'envoi' });

    // 1. Pause depuis la ligne : 2 clics + confirmation.
    const org = page.getByRole('row', { name: /Organisateurs Rhône-Alpes/ });
    await org.getByRole('button', { name: /^Pause/ }).click();
    await page.getByRole('dialog').filter({ has: page.getByRole('heading', { name: 'Mettre en pause ?' }) }).getByRole('button', { name: 'Mettre en pause' }).click();
    await expect(org.getByText('En pause')).toBeVisible();

    // 2. Changer la date d'une campagne programmée : « Demain 9:00 » en un clic.
    const federations = page.getByRole('row', { name: /Fédérations — début d’année/ });
    await federations.getByRole('button', { name: /^Changer la date/ }).click();
    const dialogue = page.getByRole('dialog').filter({ has: page.getByRole('heading', { name: 'Changer la date' }) });
    await dialogue.getByRole('button', { name: 'Demain 9:00' }).click();
    await dialogue.getByRole('button', { name: 'Reprogrammer' }).click();
    await expect(dialogue).toBeHidden();

    // Les gestes modifient la base de démo et survivent à un rechargement.
    await page.reload();
    await expect(page.getByRole('region', { name: 'Démonstration' })).toBeVisible();
    const liste = await appelerApi<PageCampagnes>(page, 'GET', '/envoi/campagnes?par_page=50');
    expect(liste.data.find((c) => c.nom === 'Organisateurs Rhône-Alpes')?.statut).toBe('en_pause');
    expect(liste.data.find((c) => c.nom.startsWith('Fédérations'))?.prochain_envoi_le).toBe('2027-01-13T08:00:00.000Z');
    await expect(page.getByRole('row', { name: /Organisateurs Rhône-Alpes/ }).getByText('En pause')).toBeVisible();

    // 3. Retrouver Yves Roche dans le suivi de la campagne.
    await page.getByRole('link', { name: 'Organisateurs Rhône-Alpes' }).first().click();
    await page.getByRole('tab', { name: /^Destinataires/ }).click();
    await page.getByRole('button', { name: /^Refusés/ }).click();
    const yves = page.getByRole('row', { name: /Yves Roche/ });
    await expect(yves).toContainText('Refusé');
    await expect(yves).toContainText('Adresse inexistante');

    expect(Date.now() - debut).toBeLessThan(4 * 60_000);
  });

  test('P3 · dupliquer crée une copie en brouillon', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/campagnes', { offre: 'les-deux' });
    await page.getByRole('row', { name: /Clubs d’entreprises Savoie/ }).getByRole('button', { name: /^Dupliquer/ }).click();
    const dialogue = page.getByRole('dialog').filter({ has: page.getByRole('heading', { name: 'Dupliquer la campagne' }) });
    await expect(dialogue.getByRole('textbox', { name: 'Nom de la copie' })).toHaveValue('Clubs d’entreprises Savoie (copie)');
    await dialogue.getByRole('button', { name: 'Créer la copie (brouillon)' }).click();
    const copie = page.getByRole('row', { name: /Clubs d’entreprises Savoie \(copie\)/ });
    await expect(copie).toBeVisible();
    await expect(copie.getByText('Brouillon')).toBeVisible();
  });

  test('P7 · le suivi d’une campagne : réponses en tête, ouvertures estimées, aucun clic', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/campagnes', { offre: 'les-deux' });
    await page.getByRole('link', { name: 'Organisateurs Rhône-Alpes' }).first().click();
    await expect(page.getByRole('heading', { level: 1, name: 'Organisateurs Rhône-Alpes' })).toBeVisible();
    await expect(page.getByTestId('tuile-reponses')).toContainText('9');
    await expect(page.getByTestId('tuile-ouvertures')).toContainText('estimation');
    await page.getByRole('tab', { name: 'Test A/B' }).click();
    await expect(page.getByText(/critère : réponses/)).toBeVisible();
    await expect(page.locator('body')).not.toContainText(/\bclics?\b/i);
    await controleAxe(page);
  });

  test('le calendrier montre la semaine', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/campagnes/calendrier', { offre: 'les-deux' });
    await expect(page.getByRole('heading', { level: 1, name: 'Calendrier' })).toBeVisible();
    await expect(page.getByRole('listitem', { name: /^mardi 12 janvier/i })).toBeVisible();
    await controleAxe(page);
  });

  test('états vide et erreur, et téléphone à 360 px', async ({ page }) => {
    await ouvrirDemo(page, '/envoi/campagnes', { offre: 'envoi', etat: 'vide' });
    await expect(page.getByText('Aucune campagne pour l’instant')).toBeVisible();
    await ouvrirDemo(page, '/envoi/campagnes', { offre: 'envoi', etat: 'erreur' });
    await expect(page.getByRole('button', { name: /Réessayer/ })).toBeVisible();
    await ouvrirDemo(page, '/envoi/campagnes', { offre: 'envoi' });
    await passage360(page);
    await ouvrirDemo(page, '/envoi/campagnes/cmp-01', { offre: 'envoi' });
    await passage360(page);
    await ouvrirDemo(page, '/envoi/campagnes/calendrier', { offre: 'envoi' });
    await passage360(page);
  });
});
