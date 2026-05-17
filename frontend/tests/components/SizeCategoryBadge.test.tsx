import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { SizeCategoryBadge } from '@/components/ui/SizeCategoryBadge';

describe('SizeCategoryBadge', () => {
  const cases: [string, string][] = [
    ['artisan', 'Artisan'],
    ['tpe', 'TPE'],
    ['pme', 'PME'],
    ['eti', 'ETI'],
    ['grande_entreprise', 'Grande'],
  ];

  it.each(cases)('renders %s → %s', (size, label) => {
    render(<SizeCategoryBadge size={size} />);
    expect(screen.getByText(label)).toBeInTheDocument();
  });

  it('renders inconnue for null', () => {
    render(<SizeCategoryBadge size={null} />);
    expect(screen.getByText(/Inconnue/)).toBeInTheDocument();
  });

  it('renders inconnue for unknown value', () => {
    render(<SizeCategoryBadge size="xyz" />);
    expect(screen.getByText(/Inconnue/)).toBeInTheDocument();
  });
});
