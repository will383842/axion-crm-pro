/**
 * D22-005 — `/contacts` NE DOIT PAS SURVIVRE À L'OUVERTURE DE LA CONSOLE.
 *
 * Mesure du 2026-08-22 : la barre latérale retire l'entrée `/contacts` dès que
 * `console_v2` est vrai (`Sidebar.tsx`, `sectionContacts`), mais la route restait
 * vivante en toutes circonstances — aucune redirection nulle part. Un signet ou
 * l'historique ramenaient sur un écran orphelin, que plus rien n'annonçait comme
 * périmé.
 *
 * Les trois cas comptent autant l'un que l'autre : rediriger quand c'est ouvert,
 * NE PAS rediriger quand c'est fermé (le retour arrière du drapeau doit rester
 * possible « en une minute »), et ne rien préjuger tant qu'on ne sait pas.
 */
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

vi.mock('@tanstack/react-router', () => ({
  // La redirection est rendue observable au lieu d'être exécutée : le test
  // mesure la DÉCISION, sans avoir besoin d'un routeur complet.
  Navigate: ({ to, replace }: { to: string; replace?: boolean }) => (
    <div data-testid="redirection" data-vers={to} data-remplace={String(Boolean(replace))} />
  ),
}));

vi.mock('@/features/contacts/ContactsListPage', () => ({
  ContactsListPage: () => <div data-testid="ancien-ecran" />,
}));

// Jamais résolue : c'est ainsi qu'on tient la requête en attente pour le
// troisième cas.
vi.mock('@/lib/api', () => ({ api: { get: () => new Promise(() => {}) } }));

const { ContactsRoute } = await import('@/features/contacts/ContactsRoute');
const { CONSOLE_FEATURES_KEY } = await import('@/features/crm-console/useConsoleFeatures');

function afficher(features: { console_v2: boolean } | null) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  if (features !== null) {
    client.setQueryData(CONSOLE_FEATURES_KEY, {
      console_v2: features.console_v2,
      universes: { business: true, vivier: false },
    });
  }
  return render(
    <QueryClientProvider client={client}>
      <ContactsRoute />
    </QueryClientProvider>,
  );
}

describe('D22-005 — la route /contacts', () => {
  it('redirige vers /console/contacts quand la console v2 est ouverte', () => {
    afficher({ console_v2: true });

    const redirection = screen.queryByTestId('redirection');
    expect(
      redirection !== null,
      'D22-005 : `/contacts` reste affiché alors que la console v2 est ouverte. ' +
        'La barre latérale ne montre plus cette entrée : l’écran devient ' +
        'joignable par signet seulement, sans rien qui dise qu’il est périmé. ' +
        'GESTE : rétablir le `<Navigate to="/console/contacts" replace />` de ' +
        '`src/features/contacts/ContactsRoute.tsx`.',
    ).toBe(true);
    expect(redirection?.getAttribute('data-vers')).toBe('/console/contacts');
    expect(
      redirection?.getAttribute('data-remplace'),
      'D22-005 : la redirection laisse une entrée d’historique. Le bouton ' +
        'Précédent ramène alors sur la page périmée, qui redirige à nouveau — une ' +
        'boucle. GESTE : garder `replace` sur le `<Navigate>`.',
    ).toBe('true');
    expect(screen.queryByTestId('ancien-ecran')).toBeNull();
  });

  it('affiche l’ancien écran quand la console v2 est fermée', () => {
    afficher({ console_v2: false });

    expect(screen.queryByTestId('redirection')).toBeNull();
    expect(
      screen.queryByTestId('ancien-ecran') !== null,
      'D22-005 : `/contacts` redirige alors que la console v2 est FERMÉE. La ' +
        'redirection doit être conditionnée au drapeau, jamais écrite en dur : ' +
        'sinon le retour arrière annoncé « en une minute » ' +
        '(`useConsoleFeatures.ts`) laisse le produit sans écran de contacts. ' +
        'GESTE : vérifier la condition `features.console_v2` dans ' +
        '`src/features/contacts/ContactsRoute.tsx`.',
    ).toBe(true);
  });

  it('ne préjuge de rien tant que /config/features n’a pas répondu', () => {
    afficher(null);

    expect(
      screen.queryByTestId('redirection'),
      'D22-005 : `/contacts` redirige AVANT de savoir si la console est ouverte. ' +
        'Un utilisateur dont la console est fermée serait envoyé sur un écran qui ' +
        'lui répond « Console non activée ». GESTE : garder la branche ' +
        '`isPending` de `src/features/contacts/ContactsRoute.tsx`.',
    ).toBeNull();
    expect(screen.queryByTestId('ancien-ecran')).toBeNull();
  });
});
