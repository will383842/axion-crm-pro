/**
 * LIBELLÉ D'ACTIVITÉ (lot N7, 03/10/2026). L'API rend désormais `naf_label`,
 * le libellé INSEE de la SOUS-CLASSE (« Programmation informatique » pour
 * « 62.01Z »). Ce que ces gardes tiennent :
 *  1. le libellé de l'API passe en priorité ;
 *  2. absent ou vide (code inconnu, code de 1993, référentiel pas chargé) →
 *     le libellé de la DIVISION, comme avant ;
 *  3. la LISTE l'affiche aussi (elle ne lisait que la division).
 */
import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';

import { libelleActivite } from '@/lib/naf-divisions';
import { CompanyRow } from '@/features/companies/components/CompanyRow';

vi.mock('@tanstack/react-router', () => ({
  Link: ({ children }: { children: ReactNode }) => <a href="/companies/1">{children}</a>,
}));

describe('libelleActivite', () => {
  it('le libellé de la sous-classe fourni par l’API passe en priorité', () => {
    expect(libelleActivite('Programmation informatique', '62.01Z')).toBe('Programmation informatique');
  });

  it.each([null, undefined, '', '   '])('sans libellé API (%s) : celui de la division', (fourni) => {
    expect(libelleActivite(fourni, '62.01Z')).toBe('Programmation et conseil informatiques');
  });

  it('ni libellé API ni division connue : null (l’écran affiche alors le code)', () => {
    expect(libelleActivite(null, '00.00Z')).toBeNull();
    expect(libelleActivite(null, null)).toBeNull();
  });
});

describe('Liste des entreprises : colonne Activité', () => {
  it('affiche le libellé de la sous-classe, le code en infobulle', () => {
    render(
      <CompanyRow
        company={{ id: 1, siren: '123456789', denomination: 'ZZ Démo', naf: '81.30Z', naf_label: 'Services d’aménagement paysager' }}
      />,
    );

    const cellule = screen.getByText('Services d’aménagement paysager');
    expect(cellule.getAttribute('title')).toBe('Code d’activité : 81.30Z');
  });

  it('sans libellé API : retombe sur la division', () => {
    render(<CompanyRow company={{ id: 1, siren: '123456789', denomination: 'ZZ Démo', naf: '81.30Z', naf_label: null }} />);

    expect(screen.getByText('Services relatifs aux bâtiments et aménagement paysager')).toBeTruthy();
  });
});
