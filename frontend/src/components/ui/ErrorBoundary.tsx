import { Component, type ReactNode } from 'react';

interface Props { children: ReactNode; fallback?: ReactNode; level?: 'root' | 'page' | 'block' }
interface State { hasError: boolean; error?: Error }

export class ErrorBoundary extends Component<Props, State> {
  state: State = { hasError: false };

  static getDerivedStateFromError(error: Error): State {
    return { hasError: true, error };
  }

  override componentDidCatch(error: Error, info: { componentStack: string }): void {
    // Sprint 11 : Sentry.captureException(error, { contexts: { react: info } })
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
    return (
      <div className={`rounded-xl bg-rose-50 p-${level === 'root' ? 8 : 4} text-rose-900`}>
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
