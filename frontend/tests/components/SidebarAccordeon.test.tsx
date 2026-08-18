/**
 * LA BARRE LATÉRALE EN ACCORDÉON.
 *
 * Neuf sections dépliées en permanence formaient un mur de liens où plus rien
 * ne se distinguait — le constat de Will : « on n'y comprend rien, c'est le
 * bazar ». Une seule section reste ouverte : en ouvrir une referme l'autre.
 *
 * Ces tests portent sur le COMPORTEMENT, pas sur l'apparence : qu'une section
 * s'ouvre est visible à l'œil, mais que la précédente se referme VRAIMENT ne
 * se vérifie qu'ici. Et la règle qui compte le plus est la dernière : la
 * section de la page courante doit être ouverte à l'arrivée, sinon on croit
 * avoir quitté l'application.
 */
import { describe, it, expect, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

let cheminCourant = '/';

vi.mock('@tanstack/react-router', () => ({
  Link: ({ children, to }: { children: React.ReactNode; to: string }) => <span data-to={to}>{children}</span>,
  // `useRouterState` applique un sélecteur : le simuler sans lui rendrait un
  // objet là où le composant attend une chaîne.
  useRouterState: ({ select }: { select: (s: { location: { pathname: string } }) => unknown }) =>
    select({ location: { pathname: cheminCourant } }),
}));

vi.mock('@/lib/api', () => ({ api: { get: () => Promise.resolve({ data: {} }) } }));

const { Sidebar } = await import('@/components/layout/Sidebar');
const { CONSOLE_FEATURES_KEY } = await import('@/features/crm-console/useConsoleFeatures');

function afficher(chemin = '/') {
  cheminCourant = chemin;
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  client.setQueryData(CONSOLE_FEATURES_KEY, {
    console_v2: true,
    universes: { business: true, vivier: false },
  });

  return render(
    <QueryClientProvider client={client}>
      <Sidebar collapsed={false} onToggleCollapse={() => {}} />
    </QueryClientProvider>,
  );
}

/**
 * Une section est ouverte si sa liste d'entrées est RÉELLEMENT affichée — et
 * si l'attribut ARIA le dit aussi.
 *
 * ⚠️ Ne vérifier que `aria-expanded` ne garderait presque rien : cet attribut
 * suit l'état React, il resterait juste même si les entrées s'affichaient
 * toutes en permanence. La preuve par la rougeur l'a montré — en neutralisant
 * le repli, un seul test sur cinq rougissait. On exige donc les deux : ce que
 * l'écran MONTRE, et ce que la technologie d'assistance ANNONCE.
 */
function estOuverte(titre: string): boolean {
  const bouton = screen.getByRole('button', { name: new RegExp(titre, 'i') });
  const idListe = bouton.getAttribute('aria-controls');
  const liste = idListe === null ? null : document.getElementById(idListe);

  const annonceeOuverte = bouton.getAttribute('aria-expanded') === 'true';
  const reellementVisible = liste !== null && !liste.className.includes('hidden');

  // Une divergence entre les deux est un défaut en soi : l'écran et le lecteur
  // d'écran ne doivent jamais raconter deux histoires différentes.
  expect(annonceeOuverte).toBe(reellementVisible);

  return annonceeOuverte;
}

// Étape 0, ligne 3 bis (F17) : « Entreprises » vit désormais dans le groupe
// « Contacts » (id `contacts`) — l'ancien groupe « Data » n'existe plus.
describe('Barre latérale — accordéon', () => {
  it('ouvre la section de la PAGE COURANTE à l’arrivée', () => {
    afficher('/companies');

    // Arriver sur « Entreprises » avec sa section repliée donnerait
    // l'impression d'avoir quitté l'application.
    expect(estOuverte('Contacts')).toBe(true);
  });

  it('ouvrir une section referme la précédente', async () => {
    const user = userEvent.setup();
    afficher('/companies');

    expect(estOuverte('Contacts')).toBe(true);

    await user.click(screen.getByRole('button', { name: /Pilotage/i }));

    // ⬇️ C'est la règle demandée : une seule à la fois.
    expect(estOuverte('Pilotage')).toBe(true);
    expect(estOuverte('Contacts')).toBe(false);
  });

  it('recliquer sur la section ouverte la referme', async () => {
    const user = userEvent.setup();
    afficher('/companies');

    await user.click(screen.getByRole('button', { name: /^Contacts$/ }));

    // Aucune section n'est alors ouverte : refermer doit rester possible,
    // sinon le repli n'est pas un vrai repli.
    expect(estOuverte('Contacts')).toBe(false);
  });

  it('les entrées d’une section fermée ne sont pas atteignables au clavier', async () => {
    const user = userEvent.setup();
    afficher('/companies');

    await user.click(screen.getByRole('button', { name: /Pilotage/i }));

    // `hidden` retire du flux ET de l'ordre de tabulation : une entrée
    // invisible mais focusable est un piège pour la navigation au clavier.
    const listeContacts = document.getElementById('nav-section-contacts');
    expect(listeContacts).not.toBeNull();
    expect(listeContacts?.className).toContain('hidden');
  });

  it('la section ouverte affiche bien ses entrées', () => {
    const { container } = afficher('/companies');

    const listeContacts = container.querySelector('#nav-section-contacts');
    expect(listeContacts).not.toBeNull();
    expect(listeContacts?.className).not.toContain('hidden');
    expect(within(listeContacts as HTMLElement).getByText('Entreprises')).toBeInTheDocument();
  });
});
