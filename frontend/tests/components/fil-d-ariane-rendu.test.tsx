/**
 * P1-3 — LE FIL D'ARIANE RENDU : seuls les écrans réels sont des liens, et un
 * identifiant (nombre, UUID, clé de personne) s'affiche « Fiche ».
 */
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';

let chemin = '/';

vi.mock('@tanstack/react-router', () => ({
  Link: ({ children, to }: { children?: ReactNode; to: string }) => <a href={to}>{children}</a>,
  useRouterState: ({ select }: { select: (s: { location: { pathname: string } }) => unknown }) =>
    select({ location: { pathname: chemin } }),
}));

const { AutoBreadcrumbs } = await import('@/components/layout/AutoBreadcrumbs');

function fil(pathname: string) {
  chemin = pathname;
  const { container, unmount } = render(<AutoBreadcrumbs />);
  const liens = [...container.querySelectorAll('a')].map((a) => `${a.textContent ?? ''}→${a.getAttribute('href') ?? ''}`);
  const texte = container.textContent ?? '';
  unmount();
  return { liens, texte };
}

describe('Fil d’Ariane rendu', () => {
  it('fiche d’un média : « Médias » est un lien, « Fiche » ne l’est pas', () => {
    const { liens, texte } = fil('/media/12');
    expect(liens).toEqual(['Accueil→/', 'Médias→/media']);
    expect(texte).toContain('Fiche');
    expect(texte).not.toContain('12');
  });

  it('fiche personne : segments sans écran omis, empreinte remplacée par « Fiche »', () => {
    const { liens, texte } = fil('/console/personnes/9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08');
    expect(liens).toEqual(['Accueil→/']);
    expect(texte).toContain('Fiche');
    expect(texte).not.toMatch(/9f86d0|Console|Personnes/i);
  });

  it('un segment intermédiaire sans écran n’est jamais un lien', () => {
    const { liens } = fil('/llm/router');
    expect(liens).toEqual(['Accueil→/']);
  });

  it('fiche d’une collecte (UUID)', () => {
    chemin = '/campaigns/0b9f3c1e-4c2a-4d7e-9a51-2f3b1c0d9e8f';
    render(<AutoBreadcrumbs />);
    const nav = screen.getByRole('navigation');
    expect(within(nav).getByRole('link', { name: 'Collectes' })).toHaveAttribute('href', '/campaigns');
    expect(within(nav).getByText('Fiche')).toBeInTheDocument();
  });
});
