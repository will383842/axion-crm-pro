/**
 * ENTREPRISES FERMÉES SELON L'INSEE (décision du 04/10/2026) : masquées par
 * défaut, retrouvables par « Afficher les entreprises fermées ». Ce que ces
 * gardes tiennent :
 *  1. le paramètre `fermees` de l'adresse n'accepte que `inclure` ou `seules` ;
 *  2. la date de fermeture s'écrit JJ/MM/AAAA, sans décalage de fuseau ;
 *  3. la ligne de la liste porte la pastille « Fermée le … ».
 * Données fictives.
 */
import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';

import { dateFermeture, libelleFermeture } from '@/features/companies/fermees';
import {
  EMPTY_FILTER,
  filtreDepuisRecherche,
  rechercheDepuisFiltre,
  validerRechercheEntreprises,
} from '@/features/companies/filtresUrl';
import { CompanyRow } from '@/features/companies/components/CompanyRow';

vi.mock('@tanstack/react-router', () => ({
  Link: ({ children }: { children: ReactNode }) => <a href="/companies/1">{children}</a>,
}));

describe('paramètre fermees de l’adresse', () => {
  it('masquées par défaut : rien dans l’adresse', () => {
    expect(EMPTY_FILTER.fermees).toBe('');
    expect(rechercheDepuisFiltre(EMPTY_FILTER)).not.toHaveProperty('fermees');
  });

  it('accepte inclure et seules, et les garde au rechargement', () => {
    expect(validerRechercheEntreprises({ fermees: 'inclure' })).toEqual({ fermees: 'inclure' });
    expect(filtreDepuisRecherche({ fermees: 'seules' }).fermees).toBe('seules');
  });

  it('refuse toute autre valeur (le filtre reste « masquées »)', () => {
    expect(validerRechercheEntreprises({ fermees: 'oui' })).toEqual({});
    expect(validerRechercheEntreprises({ fermees: 1 })).toEqual({});
  });
});

describe('date de fermeture', () => {
  it('écrit JJ/MM/AAAA sans passer par un fuseau', () => {
    expect(dateFermeture('2026-09-15')).toBe('15/09/2026');
    expect(dateFermeture('2026-09-15T00:00:00.000000Z')).toBe('15/09/2026');
    expect(dateFermeture(null)).toBeNull();
  });

  it('libellé du bandeau de la fiche', () => {
    expect(libelleFermeture('2026-09-15')).toBe('Entreprise fermée selon l’INSEE depuis le 15/09/2026');
    expect(libelleFermeture(null)).toBeNull();
  });
});

describe('ligne de la liste', () => {
  it('porte la pastille « Fermée le … » pour une fermée, rien sinon', () => {
    const { rerender } = render(
      <CompanyRow company={{ id: 1, siren: '000000000', denomination: 'ZZ Fermée', insee_ferme_le: '2026-09-15' }} />,
    );
    expect(screen.getByText('Fermée le 15/09/2026')).toBeTruthy();

    rerender(<CompanyRow company={{ id: 2, siren: '000000001', denomination: 'ZZ Ouverte', insee_ferme_le: null }} />);
    expect(screen.queryByText(/Fermée/)).toBeNull();
  });
});
