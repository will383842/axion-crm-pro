/**
 * Sprint 19.6 — Sentry React conditional init.
 *
 * Le SDK @sentry/react est embarqué mais n'est activé que si `VITE_SENTRY_DSN`
 * est défini dans l'environnement de BUILD (cf. Dockerfile.frontend ARG +
 * docker-compose service.app.build.args).
 *
 * Si pas de DSN → tous les exports sont no-op (zéro coût runtime, zéro requête
 * réseau, zéro PII envoyée nulle part).
 */
import * as Sentry from '@sentry/react';

const DSN: string | undefined = import.meta.env['VITE_SENTRY_DSN'];

export function initSentry(): void {
  if (!DSN) return;
  Sentry.init({
    dsn: DSN,
    environment: import.meta.env.PROD ? 'production' : 'development',
    tracesSampleRate: 0.1,
    replaysSessionSampleRate: 0,
    replaysOnErrorSampleRate: 1.0,
  });
}

export function captureException(error: unknown, context?: Record<string, unknown>): void {
  if (!DSN) return;
  Sentry.captureException(error, context ? { extra: context } : undefined);
}

export function setUser(user: { id: string; email?: string; workspace_id?: string } | null): void {
  if (!DSN) return;
  Sentry.setUser(user as Sentry.User | null);
}
