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
import { describe, it, expect, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

// D22-004 — pour jouer un ÉCHEC de `/config/features`, il faut que la requête
// parte VRAIMENT : les cas plus bas pré-remplissent le cache et n'appellent
// jamais l'API, donc aucun d'eux ne pouvait exercer la branche d'erreur.
//
// ⚠️ `vi.hoisted` n'est pas une coquetterie : `vi.mock` est remonté au-dessus
// des imports, et sa fabrique s'exécute pendant la résolution de `ConsoleGate`
// (qui importe `@/lib/api` en cascade). Un `const` déclaré dans le corps du
// fichier ne serait pas encore initialisé à cet instant.
//
// On ne bouchonne QUE `api` : `qualifierErreur` reste le vrai, sinon la garde
// mesurerait la classification d'un faux module au lieu de celle du produit.
//
// L'implémentation PAR DÉFAUT ne se résout jamais : c'est ce qui rend le cas
// « chargement » plus bas honnête, au lieu de le laisser courir derrière une
// requête qui aboutit ou casse. Chaque cas d'échec pose la sienne par
// `mockImplementationOnce`.
const { reponseFeatures } = vi.hoisted(() => ({
  reponseFeatures: vi.fn<() => Promise<{ data: unknown }>>(
    () => new Promise<{ data: unknown }>(() => {}),
  ),
}));
vi.mock('@/lib/api', async () => {
  const reel = await vi.importActual<typeof import('@/lib/api')>('@/lib/api');
  return { ...reel, api: { get: () => reponseFeatures() } };
});
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

/**
 * D22-004 — UNE PANNE N'EST PAS UNE DÉCISION DE CONFIGURATION.
 *
 * « La console CRM v2 n'est pas ouverte sur ce serveur » affirme quelque chose
 * de l'EXPLOITATION. Quand `/config/features` échoue, on ne sait rien de
 * l'exploitation : on sait qu'on n'a pas pu demander. L'écran disait pourtant
 * la même phrase dans les deux cas, et `retry: false` faisait qu'un seul
 * hoquet réseau suffisait — l'opérateur allait réclamer à son administrateur
 * un drapeau que celui-ci trouvait déjà levé.
 *
 * La garde précédente n'inspectait que DEUX états sur trois (drapeau fermé,
 * drapeau en attente). L'échec, personne ne le jouait.
 */
describe('ConsoleGate — quand le serveur ne répond pas', () => {
  /**
   * Une VRAIE panne réseau, pas un `new Error()` générique : `qualifierErreur`
   * ne reconnaît que les erreurs axios, et c'est l'absence de `response` qui
   * distingue « le serveur n'a jamais répondu » d'un 500. Un Error nu serait
   * classé « inconnue » — la garde passerait, mais sur un autre cas que celui
   * du constat.
   */
  function panneReseau(): Error {
    const erreur = new Error('Network Error') as Error & {
      isAxiosError: boolean;
      response: undefined;
      toJSON: () => object;
    };
    erreur.isAxiosError = true;
    erreur.response = undefined;
    erreur.toJSON = () => ({});
    return erreur;
  }

  function renderGateEnEchec() {
    reponseFeatures.mockImplementationOnce(() => Promise.reject(panneReseau()));
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });

    return render(
      <QueryClientProvider client={client}>
        <ConsoleGate>
          <p>Contenu de la console</p>
        </ConsoleGate>
      </QueryClientProvider>,
    );
  }

  it('ne présente PAS l’échec comme un drapeau fermé', async () => {
    renderGateEnEchec();

    // On attend l'état d'échec plutôt que le squelette : sans cette attente, on
    // mesurerait la branche « chargement », déjà couverte plus haut.
    await waitFor(() => {
      expect(screen.getByRole('alert')).toBeInTheDocument();
    });

    // Le défaut, mot pour mot : ces deux phrases affirment une décision
    // d'exploitation qu'on n'a pas pu constater.
    expect(screen.queryByText('Console non activée')).not.toBeInTheDocument();
    expect(
      screen.queryByText("La console CRM v2 n'est pas ouverte sur ce serveur."),
    ).not.toBeInTheDocument();
  });

  it('dit que le serveur est injoignable, ce qui est ce qu’on sait', async () => {
    renderGateEnEchec();

    // « injoignable » est la sous-chaîne que les gardes de QueryErrorState
    // cherchent déjà pour la nature « reseau » : on la reprend ici plutôt que
    // d'inventer un libellé qui ne serait vérifié nulle part ailleurs.
    await waitFor(() => {
      expect(screen.getByText(/injoignable/i)).toBeInTheDocument();
    });
  });

  it('reste FERMÉE : un échec n’ouvre jamais la console', async () => {
    renderGateEnEchec();

    await waitFor(() => {
      expect(screen.getByRole('alert')).toBeInTheDocument();
    });

    // Le seul vrai risque du correctif serait de transformer l'erreur en
    // ouverture. La décision ne change pas : seul le texte change.
    expect(screen.queryByText('Contenu de la console')).not.toBeInTheDocument();
  });

  it('offre un réessai, parce que réessayer a un sens sur une panne réseau', async () => {
    renderGateEnEchec();

    await waitFor(() => {
      expect(screen.getByRole('alert')).toBeInTheDocument();
    });

    expect(screen.getByRole('button', { name: /réessayer/i })).toBeInTheDocument();
  });

  /**
   * TÉMOIN — sans lui, un correctif qui afficherait « erreur » PARTOUT
   * satisferait aussi les cas ci-dessus. Le drapeau réellement fermé doit
   * continuer à le dire, et NE PAS lever d'alerte : un drapeau baissé n'est pas
   * une panne.
   */
  it('TÉMOIN : un drapeau réellement fermé le dit toujours, sans alerte', () => {
    renderGate({ console_v2: false, universes: { business: false, vivier: false } });

    expect(screen.getByText('Console non activée')).toBeInTheDocument();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });
});
