/**
 * « Et maintenant ? » du tableau de bord — des liens du routeur (plus de
 * `<a href>` qui rechargeait toute l'application) et des icônes, pas d'émojis.
 */
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';

const liens: Array<{ to: string; search?: unknown }> = [];

vi.mock('@tanstack/react-router', () => ({
  Link: ({ children, to, search, className }: { children?: ReactNode; to: string; search?: unknown; className?: string }) => {
    liens.push({ to, search });
    return (
      <a data-router-link href={to} className={className}>
        {children}
      </a>
    );
  },
}));

const { NextActions } = await import('@/features/dashboard/components/NextActions');

describe('NextActions', () => {
  it('fiches incomplètes : lien du routeur vers les entreprises filtrées « basique »', () => {
    liens.length = 0;
    render(<NextActions companiesTotal={1200} scraperRuns24h={3} qualityAvgScore={40} />);

    expect(screen.getByText('Compléter les fiches incomplètes')).toBeInTheDocument();
    expect(liens).toContainEqual({ to: '/companies', search: { quality: 'basique' } });
    expect(liens).toContainEqual({ to: '/companies', search: undefined });
    // Toutes les actions passent par le routeur.
    expect(document.querySelectorAll('a:not([data-router-link])')).toHaveLength(0);
  });

  it('aucun émoji dans les actions', () => {
    const { container } = render(<NextActions companiesTotal={0} scraperRuns24h={0} qualityAvgScore={0} />);
    expect(container.textContent ?? '').not.toMatch(/\p{Extended_Pictographic}/u);
    expect(container.querySelectorAll('svg').length).toBeGreaterThan(0);
  });

  it('compteurs indisponibles (null) : ni « Récupérer des entreprises » ni un total inventé', () => {
    liens.length = 0;
    const { container } = render(<NextActions companiesTotal={null} scraperRuns24h={null} qualityAvgScore={0} />);
    expect(screen.queryByText('Récupérer des entreprises')).not.toBeInTheDocument();
    expect(screen.queryByText('Voir vos entreprises')).not.toBeInTheDocument();
    expect(container.textContent ?? '').not.toMatch(/0 fiches/);
    // Repli : la carte reste utile.
    expect(screen.getByText('Voir la carte')).toBeInTheDocument();
  });
});
