import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { SizeCategoryBadge } from '@/components/ui/SizeCategoryBadge';
import { TAILLES } from '@/lib/referentiels.generated';

describe('SizeCategoryBadge', () => {
  // Les quatre tailles du référentiel unique (chantier « référentiels »).
  const cases: [string, string][] = [
    ['tpe', 'TPE'],
    ['pme', 'PME'],
    ['eti', 'ETI'],
    ['grand_groupe', 'Grand groupe'],
  ];

  it.each(cases)('renders %s → %s', (size, label) => {
    render(<SizeCategoryBadge size={size} />);
    expect(screen.getByText(label)).toBeInTheDocument();
  });

  it('couvre exactement les tailles du référentiel', () => {
    expect(cases.map(([code]) => code)).toEqual(TAILLES.map((t) => t.code));
  });

  it('renders inconnue for null', () => {
    render(<SizeCategoryBadge size={null} />);
    expect(screen.getByText(/Inconnue/)).toBeInTheDocument();
  });

  it('renders inconnue for unknown value', () => {
    render(<SizeCategoryBadge size="xyz" />);
    expect(screen.getByText(/Inconnue/)).toBeInTheDocument();
  });

  it('les anciennes valeurs (artisan, grande_entreprise) ne sont plus des tailles', () => {
    // Après le reclassement de masse, aucune fiche ne les porte plus ; une
    // fiche oubliée s'afficherait « Inconnue » plutôt que sous un faux nom.
    render(<SizeCategoryBadge size="grande_entreprise" />);
    expect(screen.getByText(/Inconnue/)).toBeInTheDocument();
  });
});
