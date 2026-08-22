/**
 * LA FEUILLE DE STYLE GLOBALE, LUE COMME UN TEXTE.
 *
 * Ces gardes lisent `src/styles/index.css` au lieu de monter un composant, et
 * c'est délibéré : jsdom n'applique pas Tailwind, ne résout pas les variables
 * de thème et n'évalue aucun `@media`. Une garde qui monterait un composant
 * pour « vérifier le contraste » certifierait donc quelque chose qu'elle
 * n'inspecte pas.
 *
 * ⚠️ CE QUE CES GARDES NE PROUVENT PAS : elles ne mesurent AUCUN contraste et
 * AUCUN pixel. Elles prouvent seulement que les déclarations dont l'absence a
 * causé D28-005 et D28-016 sont présentes. La mesure au pixel reste le travail
 * de `tests/e2e/dark-contraste.spec.ts`.
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const racine = path.dirname(fileURLToPath(import.meta.url));
const feuille = readFileSync(path.resolve(racine, '../../src/styles/index.css'), 'utf8');

/** Extrait le corps du premier bloc dont l'en-tête correspond au sélecteur. */
function corpsDuBloc(entete: string): string | null {
  const debut = feuille.indexOf(entete);
  if (debut === -1) return null;
  const ouvrante = feuille.indexOf('{', debut);
  if (ouvrante === -1) return null;
  let profondeur = 1;
  let i = ouvrante + 1;
  while (i < feuille.length && profondeur > 0) {
    if (feuille[i] === '{') profondeur += 1;
    else if (feuille[i] === '}') profondeur -= 1;
    i += 1;
  }
  return feuille.slice(ouvrante + 1, i - 1);
}

describe('D28-005 — le lien d’évitement est lisible quand il apparaît', () => {
  it('déclare un fond ET une couleur de texte explicites sous :focus', () => {
    const corps = corpsDuBloc('.skip-link:focus');
    expect(corps).not.toBeNull();

    const aUnFond = /(^|[;{\s])background(-color)?\s*:/.test(corps as string);
    expect(
      aUnFond,
      'D28-005 : `.skip-link:focus` n’a plus de `background`. Sans fond, le lien ' +
        'se pose par-dessus la barre latérale (bleu profond) avec la couleur ' +
        'héritée du corps — 1,19:1 mesuré au pixel en mode clair le 2026-08-22. ' +
        'GESTE : remettre `background: var(--color-sidebar-active)` dans ' +
        '`src/styles/index.css`, pas une couleur en dur (elle rouvrirait le ' +
        'défaut dans l’autre thème).',
    ).toBe(true);

    const aUneCouleur = /(^|[;{\s])color\s*:/.test(corps as string);
    expect(
      aUneCouleur,
      'D28-005 : `.skip-link:focus` n’a plus de `color`. Le lien hérite alors du ' +
        'quasi-noir de `body` en mode clair. GESTE : remettre `color: #fff` dans ' +
        '`src/styles/index.css`.',
    ).toBe(true);
  });
});

describe('D28-016 — le mouvement se réduit quand le système le demande', () => {
  it('porte un bloc @media prefers-reduced-motion qui borne les itérations', () => {
    const bloc = corpsDuBloc('@media (prefers-reduced-motion: reduce)');
    expect(
      bloc !== null,
      'D28-016 : plus aucun bloc `@media (prefers-reduced-motion: reduce)` dans ' +
        '`src/styles/index.css`. Le produit déclare quatre animations dont ' +
        '`.axion-pulse-dot` en `infinite` (StatusPill, PageHeader, ' +
        'CampaignDetailPage) : sans ce bloc, elles tournent malgré la demande ' +
        'système de réduction du mouvement. GESTE : rétablir le bloc en fin de ' +
        'feuille.',
    ).toBe(true);

    expect(
      /animation-iteration-count\s*:\s*1\s*!important/.test(bloc as string),
      'D28-016 : le bloc `prefers-reduced-motion` ne borne plus ' +
        '`animation-iteration-count`. `.axion-pulse-dot` est déclarée `infinite` : ' +
        'raccourcir la seule durée la laisse clignoter sans fin. GESTE : ' +
        'remettre `animation-iteration-count: 1 !important;`.',
    ).toBe(true);
  });
});
