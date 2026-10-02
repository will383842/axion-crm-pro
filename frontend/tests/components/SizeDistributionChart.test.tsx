import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { SizeDistributionChart } from '@/features/dashboard/components/SizeDistributionChart';

/**
 * « Taille d'entreprise (INSEE) » — constat de production du 2026-10-02 :
 * barres invisibles. La barre portait `height: X%` dans une colonne sans
 * hauteur définie, et les petites catégories tombaient sous 1 % face aux TPE.
 *
 * Chiffres proches de la production (fictifs).
 */

function hauteur(libelle: RegExp): number {
  const barre = screen.getByRole('img', { name: libelle });
  return parseFloat(barre.style.height);
}

describe('SizeDistributionChart', () => {
  it('la plus haute barre fait toute la zone, les autres sont visibles et ordonnées', () => {
    render(
      <SizeDistributionChart data={{ tpe: 4_000_000, pme: 185_000, eti: 75_000, grand_groupe: 28_000 }} />,
    );

    const tpe = hauteur(/^TPE/);
    const pme = hauteur(/^PME/);
    const eti = hauteur(/^ETI/);
    const gg = hauteur(/^Grand/i);

    expect(tpe).toBe(100);
    expect(gg).toBeGreaterThanOrEqual(4);
    expect(tpe).toBeGreaterThan(pme);
    expect(pme).toBeGreaterThan(eti);
    expect(eti).toBeGreaterThan(gg);
  });

  it('la barre est dans une zone qui prend toute la hauteur de sa colonne', () => {
    render(<SizeDistributionChart data={{ tpe: 10, pme: 5, eti: 0, grand_groupe: 0 }} />);

    const barre = screen.getByRole('img', { name: /^TPE/ });
    const zone = barre.parentElement as HTMLElement;
    const colonne = zone.parentElement as HTMLElement;

    expect(zone.className).toContain('flex-1');
    expect(zone.className).toContain('items-end');
    expect(colonne.className).toContain('h-full');
    expect(hauteur(/^ETI/)).toBe(0);
  });

  it('aucune classe de mode sombre dans le graphique lui-même', () => {
    const { container } = render(<SizeDistributionChart data={{ tpe: 1 }} />);
    // La carte partagée (`Card`) n'est pas de ce composant ; on lit le graphique.
    const graphique = container.querySelector('.h-44') as HTMLElement;
    expect(graphique.innerHTML).not.toContain('dark:');
  });
});
