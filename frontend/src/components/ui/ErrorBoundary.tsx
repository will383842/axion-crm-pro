import { Component, type ReactNode } from 'react';

/**
 * Frontiere d'erreur de rendu.
 *
 * ⚠️ HISTORIQUE (P6-UI-005 / D24-008) : ce composant a existe pendant des mois
 * SANS ETRE MONTE NULLE PART. `grep -rn ErrorBoundary src/` ne retournait que sa
 * declaration et son reexport dans `index.ts`. Il est desormais monte a trois
 * niveaux, cf. `src/app/RouteErrorBoundary.tsx`. Les sites de montage sont
 * verifies par `tests/components/ErrorBoundary.montage.test.tsx` — ne pas en
 * retirer un sans faire tomber cette garde.
 */
interface Props {
  children: ReactNode;
  fallback?: ReactNode;
  level?: 'root' | 'page' | 'block';
  /**
   * Rearme la frontiere quand la valeur change (en pratique : le chemin
   * courant).
   *
   * SANS ce mecanisme, une frontiere de navigation reste bloquee : l'ecran
   * `/companies` explose, l'utilisateur clique « Tableau de bord » dans la barre
   * laterale, l'URL change... et il continue de lire le message d'erreur, parce
   * que `hasError` est vrai pour toujours. Le seul recours serait `F5`.
   *
   * On rearme par PROPRIETE et non par `key` : un `key` remonterait tout le
   * sous-arbre a chaque navigation, ce qui reinitialiserait l'etat local des
   * ecrans qui traversent un changement de parametre (`/companies/1` ->
   * `/companies/2`, ou TanStack Router reutilise l'instance). Ici, quand il n'y
   * a pas d'erreur, un changement de `resetKey` ne touche a rien.
   *
   * Meme mecanique que `CatchBoundaryImpl` de TanStack Router
   * (`@tanstack/react-router/dist/esm/CatchBoundary.js:24-31`).
   */
  resetKey?: string | number | undefined;
}

interface State {
  hasError: boolean;
  error?: Error | undefined;
  resetKey?: string | number | undefined;
}

export class ErrorBoundary extends Component<Props, State> {
  override state: State = { hasError: false };

  /**
   * Retourne un FRAGMENT d'etat (React le fusionne) : `resetKey` doit survivre a
   * l'erreur. S'il etait ecrase par `undefined`, le rearmement se declencherait
   * immediatement au rendu suivant et l'utilisateur ne verrait jamais le
   * message — l'ecran repartirait en boucle sur le composant qui explose.
   */
  static getDerivedStateFromError(error: Error): Pick<State, 'hasError' | 'error'> {
    return { hasError: true, error };
  }

  static getDerivedStateFromProps(
    props: Props,
    state: State,
  ): Pick<State, 'hasError' | 'resetKey'> | null {
    if (state.resetKey === props.resetKey) return null;
    return { hasError: false, resetKey: props.resetKey };
  }

  override componentDidCatch(error: Error, info: { componentStack: string }): void {
    // Sprint 11 : Sentry.captureException(error, { contexts: { react: info } })
    // Tant que ce n'est pas branche, la console reste la SEULE trace d'un
    // incident de rendu cote client. Ne pas la retirer.
    console.error('ErrorBoundary caught', error, info);
  }

  override render(): ReactNode {
    if (!this.state.hasError) {
      return this.props.children;
    }
    if (this.props.fallback) {
      return this.props.fallback;
    }
    const level = this.props.level ?? 'block';
    // ⚠️ Classes ECRITES EN TOUTES LETTRES. La version precedente construisait
    // `p-${level === 'root' ? 8 : 4}` : Tailwind analyse les sources en TEXTE,
    // il ne voit jamais une classe assemblee a l'execution. `p-8` et `p-4`
    // n'etaient donc pas generees et l'encadre s'affichait SANS AUCUNE marge
    // interieure. Deux classes completes, pas d'interpolation.
    const padding = level === 'root' ? 'p-8' : 'p-4';
    return (
      <div
        // `role="alert"` : sans lui, un lecteur d'ecran n'annonce rien quand le
        // contenu de la page est remplace par ce bloc.
        role="alert"
        className={`rounded-xl bg-rose-50 ${padding} text-rose-900 dark:bg-rose-950 dark:text-rose-100`}
      >
        <h2 className="text-base font-semibold">Une erreur est survenue.</h2>
        <p className="mt-1 text-sm">{this.state.error?.message ?? 'Erreur inconnue'}</p>
        {level !== 'block' ? (
          <button
            onClick={() => window.location.reload()}
            className="mt-3 rounded-md bg-rose-600 px-3 py-1.5 text-sm text-white hover:bg-rose-700"
          >
            Recharger la page
          </button>
        ) : null}
      </div>
    );
  }
}
