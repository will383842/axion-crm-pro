/**
 * PASTILLES « À TRAITER » DU MENU (audit UX du 02/10/2026, lot 8).
 *
 * Ce que ces gardes tiennent :
 *  1. rien n'est affiché pour un compteur inconnu (`null`, absent) ni pour 0 —
 *     jamais un « 0 » inventé à la place d'un échec ;
 *  2. le libellé lu par les lecteurs d'écran est une phrase complète, avec le
 *     nombre exact au format français (« 1 234 doublons à vérifier ») ;
 *  3. au-delà de 999, la pastille affiche « 999+ » ;
 *  4. la réponse du serveur est filtrée : tout ce qui n'est pas un entier
 *     positif ou nul devient « pas de pastille » ;
 *  5. dans le menu, la pastille est sur la bonne entrée, et seulement là.
 */
import { describe, it, expect, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';

let cheminCourant = '/doublons';

vi.mock('@tanstack/react-router', () => ({
  Link: ({ children, to, ...reste }: { children?: ReactNode; to: string } & Record<string, unknown>) => (
    <a href={to} {...reste}>
      {children}
    </a>
  ),
  useRouterState: ({ select }: { select: (s: { location: { pathname: string } }) => unknown }) =>
    select({ location: { pathname: cheminCourant } }),
}));

vi.mock('@/lib/api', () => ({ api: { get: () => Promise.resolve({ data: {} }) } }));

const { PastilleCompteur } = await import('@/features/a-traiter/PastilleCompteur');
const {
  LIBELLES_COMPTEUR,
  COMPTEURS_A_TRAITER_KEY,
  normaliserCompteurs,
  texteDePastille,
  formaterNombre,
  totalDesCompteurs,
  libelleTotalATraiter,
} = await import('@/features/a-traiter/compteurs');
const { Sidebar } = await import('@/components/layout/Sidebar');
const { CONSOLE_FEATURES_KEY } = await import('@/features/crm-console/useConsoleFeatures');

describe('PastilleCompteur', () => {
  it.each([null, undefined, 0])('rien n’est affiché pour %s', (nombre) => {
    const { container } = render(<PastilleCompteur nombre={nombre} libelle={LIBELLES_COMPTEUR.doublons} />);
    expect(container).toBeEmptyDOMElement();
  });

  it('affiche le nombre, et dit la phrase complète aux lecteurs d’écran', () => {
    render(<PastilleCompteur nombre={12} libelle={LIBELLES_COMPTEUR.doublons} />);
    const pastille = screen.getByRole('img', { name: '12 doublons à vérifier' });
    expect(pastille).toHaveTextContent('12');
    expect(pastille).toHaveAttribute('title', '12 doublons à vérifier');
  });

  it('accorde au singulier', () => {
    render(<PastilleCompteur nombre={1} libelle={LIBELLES_COMPTEUR.a_rattacher} />);
    expect(screen.getByRole('img', { name: '1 personne à rattacher' })).toHaveTextContent('1');
  });

  it('au-delà de 999 : « 999+ » à l’écran, le nombre exact au format français pour les lecteurs d’écran', () => {
    render(<PastilleCompteur nombre={1234} libelle={LIBELLES_COMPTEUR.doublons} />);
    const pastille = screen.getByRole('img', { name: /^1\s234 doublons à vérifier$/ });
    expect(pastille).toHaveTextContent('999+');
  });

  it('format des nombres', () => {
    expect(texteDePastille(999)).toBe('999');
    expect(texteDePastille(1000)).toBe('999+');
    // Séparateur des milliers : une espace (fine insécable), jamais une virgule.
    expect(formaterNombre(1234)).toMatch(/^1\s234$/);
    expect(formaterNombre(4300000)).toMatch(/^4\s300\s000$/);
  });
});

describe('normaliserCompteurs', () => {
  it('ne garde que des entiers positifs ou nuls ; le reste devient null (pas de pastille)', () => {
    expect(normaliserCompteurs({ doublons: 3, a_rattacher: 0 })).toEqual({ doublons: 3, a_rattacher: 0 });
    expect(normaliserCompteurs({})).toEqual({ doublons: null, a_rattacher: null });
    expect(normaliserCompteurs(null)).toEqual({ doublons: null, a_rattacher: null });
    expect(normaliserCompteurs({ doublons: '3', a_rattacher: -1 })).toEqual({ doublons: null, a_rattacher: null });
    expect(normaliserCompteurs({ doublons: 1.5, a_rattacher: null })).toEqual({ doublons: null, a_rattacher: null });
  });

  it('relances (accueil) : absent du serveur → absent (pas de carte) ; illisible → null ; entier → gardé', () => {
    expect(normaliserCompteurs({ doublons: 1, a_rattacher: 2 })).not.toHaveProperty('relances');
    expect(normaliserCompteurs({ doublons: 1, a_rattacher: 2, relances: 4 })).toEqual({ doublons: 1, a_rattacher: 2, relances: 4 });
    expect(normaliserCompteurs({ doublons: 1, a_rattacher: 2, relances: null })).toEqual({ doublons: 1, a_rattacher: 2, relances: null });
    expect(normaliserCompteurs({ doublons: 1, a_rattacher: 2, relances: 'x' })).toEqual({ doublons: 1, a_rattacher: 2, relances: null });
  });
});

describe('Le menu', () => {
  function afficher(compteurs: { doublons: number | null; a_rattacher: number | null }) {
    cheminCourant = '/doublons';
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity } } });
    client.setQueryData(CONSOLE_FEATURES_KEY, { console_v2: true, universes: { business: true, vivier: false } });
    client.setQueryData(COMPTEURS_A_TRAITER_KEY, compteurs);
    return render(
      <QueryClientProvider client={client}>
        <Sidebar collapsed={false} onToggleCollapse={() => {}} />
      </QueryClientProvider>,
    );
  }

  it('la pastille est sur « Doublons à vérifier », et aucune sur une entrée au compteur inconnu', () => {
    afficher({ doublons: 12, a_rattacher: null });

    const doublons = screen.getByRole('link', { name: /Doublons à vérifier/ });
    expect(within(doublons).getByRole('img', { name: '12 doublons à vérifier' })).toHaveTextContent('12');

    const rattacher = screen.getByRole('link', { name: /Personnes à rattacher/ });
    expect(within(rattacher).queryByRole('img')).toBeNull();

    // Une seule pastille dans tout le menu.
    expect(document.querySelectorAll('[data-pastille-compteur]')).toHaveLength(1);
  });

  it('zéro partout : aucune pastille', () => {
    afficher({ doublons: 0, a_rattacher: 0 });
    expect(document.querySelectorAll('[data-pastille-compteur]')).toHaveLength(0);
  });

  it('« Personnes à rattacher » porte la sienne', () => {
    afficher({ doublons: null, a_rattacher: 3 });
    const rattacher = screen.getByRole('link', { name: /Personnes à rattacher/ });
    expect(within(rattacher).getByRole('img', { name: '3 personnes à rattacher' })).toBeInTheDocument();
  });
});

describe('Section « À traiter » repliée (03/10/2026)', () => {
  it('total des compteurs connus ; null ignoré ; rien de connu → null', () => {
    expect(totalDesCompteurs({ doublons: 275, a_rattacher: 3 }, ['doublons', 'a_rattacher'])).toBe(278);
    expect(totalDesCompteurs({ doublons: null, a_rattacher: 3 }, ['doublons', 'a_rattacher'])).toBe(3);
    expect(totalDesCompteurs({ doublons: null, a_rattacher: null }, ['doublons', 'a_rattacher'])).toBeNull();
    expect(totalDesCompteurs(undefined, ['doublons'])).toBeNull();
    // Seules les entrées présentes dans la section comptent.
    expect(totalDesCompteurs({ doublons: 5, a_rattacher: 9 }, ['doublons'])).toBe(5);
    expect(libelleTotalATraiter(1)).toBe('1 élément à traiter');
    expect(libelleTotalATraiter(1234)).toMatch(/^1\s234 éléments à traiter$/);
  });

  function afficherAilleurs(
    compteurs: { doublons: number | null; a_rattacher: number | null },
    chemin = '/companies',
  ) {
    cheminCourant = chemin;
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity } } });
    client.setQueryData(CONSOLE_FEATURES_KEY, { console_v2: true, universes: { business: true, vivier: false } });
    client.setQueryData(COMPTEURS_A_TRAITER_KEY, compteurs);
    return render(
      <QueryClientProvider client={client}>
        <Sidebar collapsed={false} onToggleCollapse={() => {}} />
      </QueryClientProvider>,
    );
  }

  it('repliée : le titre porte le total (doublons + à rattacher)', () => {
    afficherAilleurs({ doublons: 275, a_rattacher: 3 });
    const titre = screen.getByRole('button', { name: /À traiter/ });
    expect(titre).toHaveAttribute('aria-expanded', 'false');
    expect(within(titre).getByRole('img', { name: '278 éléments à traiter' })).toHaveTextContent('278');
  });

  it('repliée, un compteur inconnu est ignoré', () => {
    afficherAilleurs({ doublons: null, a_rattacher: 4 });
    const titre = screen.getByRole('button', { name: /À traiter/ });
    expect(within(titre).getByRole('img', { name: '4 éléments à traiter' })).toBeInTheDocument();
  });

  it('repliée, rien de connu ou zéro : pas de pastille sur le titre', () => {
    const { unmount } = afficherAilleurs({ doublons: null, a_rattacher: null });
    expect(within(screen.getByRole('button', { name: /À traiter/ })).queryByRole('img')).toBeNull();
    unmount();
    afficherAilleurs({ doublons: 0, a_rattacher: 0 });
    expect(within(screen.getByRole('button', { name: /À traiter/ })).queryByRole('img')).toBeNull();
  });

  it('dépliée : pas de total sur le titre, les pastilles sont sur les entrées', () => {
    afficherAilleurs({ doublons: 275, a_rattacher: 3 }, '/doublons');
    const titre = screen.getByRole('button', { name: /À traiter/ });
    expect(titre).toHaveAttribute('aria-expanded', 'true');
    expect(within(titre).queryByRole('img')).toBeNull();
    expect(screen.getByRole('img', { name: '275 doublons à vérifier' })).toBeInTheDocument();
  });
});
