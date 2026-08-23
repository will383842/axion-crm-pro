/**
 * D30-008 — LE REPLI DES ACTIONS D'EN-TÊTE, ÉCRIT MAIS IMPOSSIBLE.
 *
 * Mesure du 2026-08-22 : le bloc d'actions de `PageHeader` portait à la fois
 * `shrink-0` et `flex-wrap`. Les deux s'annulent — `shrink-0` fige la largeur du
 * bloc à `max-content`, ses enfants n'y rencontrent donc jamais de contrainte de
 * largeur, et le `flex-wrap` du même élément ne peut structurellement jamais
 * s'exercer. Le repli était écrit et ne pouvait pas se produire, sur les 27
 * écrans qui montent ce composant.
 *
 * ⚠️ CE QUE CETTE GARDE NE PROUVE PAS : jsdom ne fait aucune mise en page. Elle
 * n'observe donc PAS un repli ; elle observe que les deux classes qui
 * s'excluent ne cohabitent plus. C'est exactement le défaut relevé, ni plus.
 */
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { PageHeader } from '@/components/ui/PageHeader';

describe('D30-008 — PageHeader', () => {
  it('n’oppose plus shrink-0 au flex-wrap de son bloc d’actions', () => {
    render(
      <PageHeader
        title="Entreprises"
        actions={
          <>
            <button type="button">Importer</button>
            <button type="button">Exporter</button>
            <button type="button">Nouvelle entreprise</button>
          </>
        }
      />,
    );

    const bloc = screen.getByRole('button', { name: 'Importer' }).parentElement as HTMLElement;
    const classes = bloc.className;

    expect(
      /(^|\s)shrink-0(\s|$)/.test(classes),
      'D30-008 : le bloc d’actions de `PageHeader` reprend `shrink-0` à côté de ' +
        '`flex-wrap`. `shrink-0` fige sa largeur à `max-content` : le repli écrit ' +
        'juste à côté ne peut alors JAMAIS se produire, sur les 27 écrans qui ' +
        'montent ce composant. GESTE : remplacer `shrink-0` par `min-w-0` dans ' +
        '`src/components/ui/PageHeader.tsx`.',
    ).toBe(false);

    expect(
      classes.includes('flex-wrap'),
      'D30-008 : le bloc d’actions de `PageHeader` a perdu `flex-wrap`. Sans lui ' +
        'les actions débordent en ligne au lieu de se replier sur un écran ' +
        'étroit. GESTE : le rétablir dans `src/components/ui/PageHeader.tsx`.',
    ).toBe(true);
  });
});
