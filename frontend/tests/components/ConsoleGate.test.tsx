/**
 * L'INERTIE se teste côté frontend aussi.
 *
 * Le backend répond 404 quand le drapeau est fermé ; encore faut-il que
 * l'interface ne propose rien. Un écran qui appelle une route inexistante et
 * affiche « erreur » n'est pas inerte — il est cassé.
 *
 * Ces tests montent le portail avec un QueryClient dont le cache est PRÉ-REMPLI :
 * il n'existe pas de `renderWithProviders` dans ce dépôt, et injecter la réponse
 * dans le cache évite d'avoir à simuler le réseau pour vérifier une décision
 * d'affichage.
 */
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ConsoleGate } from '@/features/crm-console/ConsoleGate';
import { CONSOLE_FEATURES_KEY } from '@/features/crm-console/useConsoleFeatures';
import type { ConsoleFeatures } from '@/features/crm-console/useConsoleFeatures';

function renderGate(features: ConsoleFeatures | undefined, requiresVivier = false) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: 0 } },
  });
  if (features !== undefined) {
    client.setQueryData(CONSOLE_FEATURES_KEY, features);
  }

  return render(
    <QueryClientProvider client={client}>
      <ConsoleGate requiresVivier={requiresVivier}>
        <p>Contenu de la console</p>
      </ConsoleGate>
    </QueryClientProvider>,
  );
}

const OPEN: ConsoleFeatures = {
  console_v2: true,
  universes: { business: true, vivier: false },
};

describe('ConsoleGate', () => {
  it('n’affiche rien de la console quand le drapeau est fermé', () => {
    renderGate({ console_v2: false, universes: { business: false, vivier: false } });

    expect(screen.queryByText('Contenu de la console')).not.toBeInTheDocument();
    expect(screen.getByText('Console non activée')).toBeInTheDocument();
  });

  it('reste fermé quand l’état du drapeau est INCONNU', () => {
    // Un drapeau dont l'état inconnu vaudrait « ouvert » n'est pas un drapeau.
    renderGate(undefined);

    expect(screen.queryByText('Contenu de la console')).not.toBeInTheDocument();
  });

  it('affiche la console quand le drapeau est ouvert', () => {
    renderGate(OPEN);

    expect(screen.getByText('Contenu de la console')).toBeInTheDocument();
  });

  it('masque le vivier à qui n’en est pas membre, même drapeau ouvert', () => {
    renderGate(OPEN, true);

    expect(screen.queryByText('Contenu de la console')).not.toBeInTheDocument();
    expect(screen.getByText('Univers vivier candidats non accessible')).toBeInTheDocument();
  });

  it('affiche le vivier à un membre', () => {
    renderGate({ console_v2: true, universes: { business: true, vivier: true } }, true);

    expect(screen.getByText('Contenu de la console')).toBeInTheDocument();
  });
});

/**
 * « Console non activée » est une AFFIRMATION. Tant que `/config/features` n'a
 * pas répondu, on n'en sait rien : la console reste fermée (fail-closed), mais
 * elle se tait. Ce message s'affichait à chaque ouverture de page, plusieurs
 * secondes, y compris sur un serveur où la console EST activée.
 */
describe('ConsoleGate — tant que le drapeau n’a pas répondu', () => {
  it('reste muet et fermé pendant le chargement', () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });

    const { container } = render(
      <QueryClientProvider client={client}>
        <ConsoleGate>
          <p>Contenu de la console</p>
        </ConsoleGate>
      </QueryClientProvider>,
    );

    expect(screen.queryByText('Contenu de la console')).not.toBeInTheDocument();
    expect(screen.queryByText('Console non activée')).not.toBeInTheDocument();
    expect(container.querySelector('[aria-hidden="true"]')).not.toBeNull();
  });
});
