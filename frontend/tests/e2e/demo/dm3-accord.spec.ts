/**
 * DM3-ACCORD (G4-ACCORD, D15) — la section « Accord donné » de la fiche personne, en démo (faux serveur).
 *
 * J1 une fiche avec accord : origine, date, source et version du texte ;
 * J2 aucune référence de commentaire, de post ni d'adresse de post dans le DOM ;
 * J3 une fiche sans accord : « Aucun accord enregistré ».
 * Lecture seule : aucun envoi, aucune écriture.
 */
import { test, expect, type Page } from '@playwright/test';
import { ouvrirDemo } from './outils';

const LISTE = '/console/lettre-et-guide';

/** Ouvre la fiche numéro `rang` de la liste ; rend le texte de la section « Accord donné ». */
async function accordDeLaFiche(page: Page, rang: number): Promise<string> {
  await ouvrirDemo(page, LISTE);
  await page.locator('a[href*="/console/lettre-et-guide/"]').nth(rang).click();
  const section = page.getByTestId('accord-donne');
  await expect(section).toBeVisible();
  return (await section.textContent()) ?? '';
}

test.describe('accord donné (G4-ACCORD)', () => {
  test('J1 · une fiche avec accord montre l’origine, la date, la source et la version du texte', async ({ page }) => {
    let trouve = false;
    for (let rang = 0; rang < 12 && !trouve; rang += 1) {
      const texte = await accordDeLaFiche(page, rang);
      if (texte.includes('version du texte')) {
        trouve = true;
        expect(texte).toMatch(/\d{2}\/\d{2}\/\d{4}/);
        expect(texte).toContain('source : confirmation');
        expect(texte).toContain('Origine');
      }
    }
    expect(trouve).toBe(true);
  });

  test('J2 · aucune référence de commentaire ou de post dans le DOM', async ({ page }) => {
    let texte = '';
    for (let rang = 0; rang < 12 && !texte.includes('version du texte'); rang += 1) texte = await accordDeLaFiche(page, rang);
    // La fiche testée contient bien un accord et une origine : l'absence des références est alors significative.
    expect(texte).toContain('version du texte');
    expect(texte).toContain('Origine');
    const html = await page.content();
    expect(html).not.toContain('commentaire_ref');
    expect(html).not.toContain('post_ref');
    expect(html).not.toContain('post_url');
  });

  test('J3 · une fiche sans accord : « Aucun accord enregistré »', async ({ page }) => {
    let trouve = false;
    for (let rang = 0; rang < 12 && !trouve; rang += 1) {
      const texte = await accordDeLaFiche(page, rang);
      if (texte.includes('Aucun accord enregistré')) trouve = true;
    }
    expect(trouve).toBe(true);
  });
});
