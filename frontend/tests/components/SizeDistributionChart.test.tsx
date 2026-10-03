import { describe, expect, it } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import { SizeDistributionChart, largeursBarres } from '@/features/dashboard/components/SizeDistributionChart';

/**
 * « Par taille » — nouvel accueil (maquette validée par Will, 03/10/2026) :
 * barres HORIZONTALES, largeur = vraie part du total classé, nombre exact au
 * bout de chaque barre.
 *
 * Chiffres proches de la production (fictifs).
 */

function largeur(code: string): number {
  return parseFloat(screen.getByTestId(`barre-${code}`).style.width);
}

describe('SizeDistributionChart — « Par taille »', () => {
  it('chaque taille a sa ligne : libellé, barre proportionnelle, nombre au format français', () => {
    render(<SizeDistributionChart data={{ tpe: 4_042_240, pme: 185_414, eti: 74_753, grand_groupe: 28_027 }} />);

    expect(screen.getByRole('heading', { name: 'Par taille' })).toBeVisible();
    const lignes = within(screen.getByRole('list')).getAllByRole('listitem');
    expect(lignes).toHaveLength(4);
    expect(lignes[0]).toHaveTextContent(/TPE/);
    expect(lignes[0]).toHaveTextContent(/4.042.240/);
    expect(lignes[3]).toHaveTextContent(/Grand groupe/);
    expect(lignes[3]).toHaveTextContent(/28.027/);

    // Linéaire : les TPE font ≈ 93 % ; les autres restent visibles et ordonnées.
    expect(largeur('tpe')).toBeGreaterThan(92);
    expect(largeur('tpe')).toBeLessThan(94);
    expect(largeur('pme')).toBeGreaterThan(largeur('eti'));
    expect(largeur('eti')).toBeGreaterThan(largeur('grand_groupe'));
    expect(largeur('grand_groupe')).toBeGreaterThanOrEqual(1);
  });

  it('une taille à zéro a une barre vide, mais garde sa ligne et son 0 (un vrai zéro)', () => {
    render(<SizeDistributionChart data={{ tpe: 10, pme: 5, eti: 0, grand_groupe: 0 }} />);

    expect(largeur('eti')).toBe(0);
    expect(within(screen.getByRole('list')).getAllByRole('listitem')[2]).toHaveTextContent(/ETI\s*0/);
  });

  it('répartition indisponible (null) : « Chiffre indisponible pour le moment », jamais des barres à 0', () => {
    render(<SizeDistributionChart data={null} />);

    expect(screen.getByTestId('tailles-indisponibles')).toHaveTextContent('Chiffre indisponible pour le moment');
    expect(screen.queryByRole('list')).not.toBeInTheDocument();
  });

  it('largeursBarres : total nul → tout à 0 ; petite part non nulle → au moins 1 %', () => {
    expect(largeursBarres([0, 0])).toEqual([0, 0]);
    expect(largeursBarres([1_000_000, 1])).toEqual([100, 1]);
  });

  it('aucune classe de mode sombre dans les barres elles-mêmes', () => {
    render(<SizeDistributionChart data={{ tpe: 1 }} />);
    // La carte partagée (`Card`) n'est pas de ce composant ; on lit la liste.
    expect(screen.getByRole('list').innerHTML).not.toContain('dark:');
  });
});
