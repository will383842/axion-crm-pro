/**
 * E2E-FIN — parcours P, de bout en bout par l'interface (démo, faux serveur dans le
 * navigateur) : créer une campagne (assistant), voir les opposées exclues dans
 * l'estimation, valider, voir la séquence apparaître, mettre en pause, tenter de
 * reprendre sous frein, consulter le feu vert (non accordé), arrêt général, puis
 * constater qu'aucun écran n'annonce un message parti en réel. Aucune personne affichée.
 */
import { test, expect, type Page } from '@playwright/test';
import { ouvrirDemo } from './outils';

const NOM = 'Parcours complet (démo)';
const PARTI_EN_REEL = /message[s]? (?:est |sont )?(?:parti|envoyé)[s]? en réel|envoi réel (?:activé|en cours)|envoyé[es]? pour de vrai/i;

async function aucunEcranNAnnonceUnEnvoiReel(page: Page, chemins: string[]): Promise<void> {
  for (const chemin of chemins) {
    await ouvrirDemo(page, chemin, { offre: 'envoi' });
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.locator('main')).not.toContainText(PARTI_EN_REEL);
  }
}

test.describe('démo — parcours complet d’Envoi (E2E-FIN)', () => {
  test('P · de la création d’une campagne à l’arrêt général, sans aucun envoi réel', async ({ page }) => {
    // 1. Assistant : la cible montre les opposées exclues dans l'estimation.
    await ouvrirDemo(page, '/envoi/campagnes/nouvelle?etiquette=salon-artisans-2026', { offre: 'envoi' });
    await page.getByRole('textbox', { name: 'Nom de la campagne' }).fill(NOM);
    await expect(page.getByText(/adresses retirées d’office/)).toBeVisible();
    await expect(page.getByText(/Oppositions \([1-9]\d*\)/)).toBeVisible();
    await page.getByRole('button', { name: /Continuer : message/ }).click();
    await page.getByRole('button', { name: /Continuer : quand/ }).click();
    await page.getByRole('button', { name: 'Demain 9:00' }).click();
    await page.getByRole('button', { name: /Continuer : vérification/ }).click();
    await expect(page.getByText('Tous les contrôles sont au vert')).toBeVisible();
    await page.getByRole('button', { name: /Continuer : lancement/ }).click();

    // 2. Validation par code : la campagne est programmée.
    await page.getByRole('button', { name: 'Envoyer le code par e-mail' }).click();
    const code = (await page.getByTestId('code-demo').innerText()).trim();
    await page.getByLabel('Code reçu par e-mail').fill(code);
    await page.getByRole('button', { name: 'Valider et programmer' }).click();
    await expect(page.getByText(/Campagne programmée/)).toBeVisible();

    // 3. La séquence de la campagne apparaît dans la liste.
    await ouvrirDemo(page, '/envoi/sequences', { offre: 'envoi' });
    await expect(page.getByRole('heading', { level: 1, name: 'Séquences' })).toBeVisible();
    await page.getByRole('link', { name: NOM }).click();
    await expect(page.getByRole('heading', { level: 1, name: NOM })).toBeVisible();

    // 4. Pause avec confirmation.
    await page.getByRole('button', { name: 'Mettre en pause' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Mettre en pause' }).click();
    await expect(page.getByText('En pause', { exact: true })).toBeVisible();

    // 5. Reprise sous frein : refusée, la raison est en clair, le statut ne change pas.
    await page.getByRole('button', { name: 'Reprendre' }).click();
    await expect(page.getByRole('alert')).toContainText('Un frein est actif');
    await expect(page.getByText('En pause', { exact: true })).toBeVisible();

    // 6. Feu vert : non accordé, l'accord reste impossible tant qu'un bloquant est rouge.
    await ouvrirDemo(page, '/envoi/sante/feu-vert', { offre: 'envoi' });
    await expect(page.getByRole('button', { name: 'Accorder le feu vert' })).toBeDisabled();
    await expect(page.getByText(/n’active pas l’envoi réel/).first()).toBeVisible();

    // 7. Arrêt général en deux clics : bandeau sans « Reprendre ».
    await page.getByRole('button', { name: 'Tout arrêter' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Arrêter tous les envois' }).click();
    const bandeau = page.getByRole('alert').filter({ hasText: 'Envois arrêtés' });
    await expect(bandeau).toBeVisible();
    await expect(bandeau.getByRole('button')).toHaveCount(0);

    // 8. Aucun écran n'annonce un message parti en réel.
    await aucunEcranNAnnonceUnEnvoiReel(page, ['/envoi/campagnes', '/envoi/sequences', '/envoi/sante/feu-vert']);
  });
});
