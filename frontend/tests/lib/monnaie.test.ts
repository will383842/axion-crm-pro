/**
 * GARDE — LES MONTANTS EN EUROS SONT RENDUS EN TYPOGRAPHIE FRANÇAISE (D29-005).
 *
 * Mesure du 2026-08-22 : `LlmRouterPage.tsx:228` et `:270` rendaient
 * `« 12.34 € »` — point décimal anglais produit par `toFixed()`, et espace
 * ORDINAIRE devant le symbole, donc sécable en fin de ligne. Deux lignes plus
 * bas, les jetons passaient déjà par `toLocaleString('fr-FR')` : l'outil était
 * là, il n'était pas employé pour l'argent.
 *
 * Deux gardes, et elles ne se remplacent pas :
 *   1. le formateur partagé rend bien la virgule et l'insécable ;
 *   2. l'écran l'EMPLOIE — sans quoi la première serait verte sur du code mort.
 *
 * ⚠️ AUCUNE COMPARAISON DE CHAÎNE EXACTE ICI, ET C'EST DÉLIBÉRÉ. L'espace posée
 * par `Intl.NumberFormat` entre le nombre et le symbole vaut U+00A0 sur les ICU
 * anciennes et U+202F (insécable étroite) sur les récentes. Un `toBe('12,34 €')`
 * tapé au clavier rougirait donc à la première montée de Node sans que rien ne
 * soit cassé. On mesure les propriétés qui comptent : la virgule, l'absence de
 * point, et le caractère insécable — pas sa largeur.
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { formaterEuros } from '@/lib/monnaie';

const racine = path.dirname(fileURLToPath(import.meta.url));

/**
 * U+00A0 (insécable) et U+202F (insécable étroite) : les deux sont acceptables.
 * Écrits en séquences d'échappement à dessein — copiés tels quels, ces deux caractères
 * sont indiscernables d'une espace ordinaire à la relecture, et la garde deviendrait
 * muette au premier « nettoyage » d'un éditeur.
 */
const INSECABLES = ['\u00A0', '\u202F'];

describe('D29-005 — formaterEuros', () => {
  it('rend une virgule décimale, jamais le point anglais de toFixed()', () => {
    const rendu = formaterEuros(12.34);

    expect(rendu).toContain('12,34');
    expect(rendu.includes('.')).toBe(false);
  });

  it('sépare le nombre du symbole par une espace INSÉCABLE', () => {
    const rendu = formaterEuros(12.34);
    const avantSymbole = rendu.charAt(rendu.indexOf('€') - 1);

    expect(rendu).toContain('€');
    expect(INSECABLES.includes(avantSymbole)).toBe(true);
  });

  it('groupe les milliers avec une espace insécable, jamais avec la virgule anglaise', () => {
    const rendu = formaterEuros(1234.5);

    expect(rendu.includes('1,234')).toBe(false);
    expect(rendu).toContain('234,50');
    // Le séparateur de milliers est l'un des insécables, pas un espace ordinaire.
    expect(INSECABLES.some((c) => rendu.includes(`1${c}234`))).toBe(true);
  });

  it('rend deux décimales même quand le montant est rond', () => {
    // Un coût affiché « 7 € » se lit comme un arrondi ; « 7,00 € » dit que
    // c'est le montant.
    expect(formaterEuros(7)).toContain('7,00');
  });
});

describe("D29-005 — l'écran LLM emploie le formateur partagé", () => {
  // Lecture du fichier plutôt que montage : cet écran demande une session, un
  // QueryClient et deux appels MSW pour afficher un seul montant. Ce qu'on veut
  // savoir tient dans le texte — et une garde qui monterait l'écran sans
  // vérifier les fins de ligne serait aveugle sous Windows (piège payé ailleurs
  // dans ce dépôt).
  const source = readFileSync(
    path.resolve(racine, '../../src/features/llm/LlmRouterPage.tsx'),
    'utf8',
  ).replace(/\r\n/g, '\n');

  it('n’écrit plus aucun montant avec toFixed() suivi du symbole €', () => {
    const fautifs = source
      .split('\n')
      .map((ligne, i) => ({ ligne: ligne.trim(), n: i + 1 }))
      .filter(({ ligne }) => /toFixed\(\s*2\s*\)/.test(ligne) && ligne.includes('€'));

    expect(
      fautifs,
      `D29-005 : ${fautifs.length} montant(s) encore rendus par toFixed() + « € » dans ` +
        `LlmRouterPage.tsx (${fautifs.map((f) => `L${f.n}`).join(', ')}). toFixed() rend un ` +
        `point décimal anglais et l'espace tapée avant le symbole est sécable. ` +
        `Geste : passer par formaterEuros() de src/lib/monnaie.ts.`,
    ).toEqual([]);
  });

  it('importe formaterEuros et l’appelle sur chaque montant affiché', () => {
    expect(
      source.includes("from '@/lib/monnaie'"),
      "D29-005 : LlmRouterPage.tsx n'importe plus le formateur partagé. Si les montants " +
        "ont changé de forme, réécris cette garde sur la nouvelle source unique — n'en " +
        'refais pas un formatage à la main dans l’écran.',
    ).toBe(true);

    const appels = source.match(/formaterEuros\(/g) ?? [];
    expect(
      appels.length,
      `D29-005 : ${appels.length} appel(s) à formaterEuros() dans LlmRouterPage.tsx, on en ` +
        `attend au moins 2 (le KPI « Coût total 30j » et la répartition par fournisseur). ` +
        `Un montant a été retiré, ou remis en formatage manuel.`,
    ).toBeGreaterThanOrEqual(2);
  });
});
