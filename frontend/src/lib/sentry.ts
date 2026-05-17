/**
 * Sprint 18.8 — Sentry SDK init (compatible GlitchTip self-hosted).
 *
 * Activé seulement si VITE_SENTRY_DSN est défini. GlitchTip est 100% compatible
 * avec le SDK Sentry officiel — il suffit de pointer le DSN vers l'instance GlitchTip.
 *
 * Coût : 0€/mois (open source, self-hostable). Cf. _AUDIT/MONITORING.md.
 */

import * as Sentry from '@sentry/react';

interface InitOptions {
  dsn?: string;
  environment?: string;
  release?: string;
  tracesSampleRate?: number;
}

let initialized = false;

export function initSentry(opts: InitOptions = {}): void {
  if (initialized) return;

  const env = (import.meta as any).env ?? {};
  const dsn = opts.dsn ?? env['VITE_SENTRY_DSN'];
  if (!dsn) return;  // pas de DSN → désactivé

  Sentry.init({
    dsn,
    environment: opts.environment ?? env['VITE_SENTRY_ENVIRONMENT'] ?? env['MODE'] ?? 'production',
    release: opts.release ?? env['VITE_SENTRY_RELEASE'] ?? undefined,
    tracesSampleRate: opts.tracesSampleRate ?? 0.1,  // 10% transactions
    // GlitchTip ne supporte pas tous les features Sentry → on désactive les modules avancés
    integrations: [
      Sentry.browserTracingIntegration(),
    ],
    // Filter en local dev : on ne reporte que les vraies erreurs prod
    beforeSend(event) {
      if (event.environment === 'development') return null;
      return event;
    },
  });

  initialized = true;
}

export function captureException(error: unknown, context?: Record<string, unknown>): void {
  if (!initialized) return;
  Sentry.captureException(error, { extra: context });
}

export function setUser(user: { id: string; email?: string; workspace_id?: string } | null): void {
  if (!initialized) return;
  Sentry.setUser(user);
}
