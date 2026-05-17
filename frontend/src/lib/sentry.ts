/**
 * Sprint 18.8 — Sentry SDK init (compatible GlitchTip self-hosted).
 *
 * Activé seulement si VITE_SENTRY_DSN est défini. GlitchTip est 100% compatible
 * avec le SDK Sentry officiel — il suffit de pointer le DSN vers l'instance GlitchTip.
 *
 * Sprint 18.9b — dynamic import : ne crash JAMAIS le bundle si @sentry/react absent
 * (cas où pnpm install n'a pas tourné après le pull). Au pire le SDK est désactivé.
 *
 * Coût : 0€/mois (open source, self-hostable). Cf. _AUDIT/MONITORING.md.
 */

interface InitOptions {
  dsn?: string;
  environment?: string;
  release?: string;
  tracesSampleRate?: number;
}

let initialized = false;
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let SentryModule: any = null;

export function initSentry(opts: InitOptions = {}): void {
  if (initialized) return;

  const env = (import.meta as { env?: Record<string, string | undefined> }).env ?? {};
  const dsn = opts.dsn ?? env['VITE_SENTRY_DSN'];
  if (!dsn) return;  // pas de DSN → désactivé, jamais d'erreur

  // Dynamic import — si le package n'est pas installé, on no-op silencieusement.
  // @vite-ignore : Vite ne doit pas pré-résoudre cet import (sinon erreur build).
  import(/* @vite-ignore */ '@sentry/react').then((Sentry) => {
    SentryModule = Sentry;
    Sentry.init({
      dsn,
      environment: opts.environment ?? env['VITE_SENTRY_ENVIRONMENT'] ?? env['MODE'] ?? 'production',
      release: opts.release ?? env['VITE_SENTRY_RELEASE'] ?? undefined,
      tracesSampleRate: opts.tracesSampleRate ?? 0.1,
      integrations: [
        Sentry.browserTracingIntegration(),
      ],
      beforeSend(event: { environment?: string }) {
        if (event.environment === 'development') return null;
        return event;
      },
    });
    initialized = true;
  }).catch(() => {
    // Sentry absent ou erreur init → silently disable
    // (pas de console.error pour ne pas polluer les logs prod)
  });
}

export function captureException(error: unknown, context?: Record<string, unknown>): void {
  if (!initialized || !SentryModule) return;
  SentryModule.captureException(error, { extra: context });
}

export function setUser(user: { id: string; email?: string; workspace_id?: string } | null): void {
  if (!initialized || !SentryModule) return;
  SentryModule.setUser(user);
}
