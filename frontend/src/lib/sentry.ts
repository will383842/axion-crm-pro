/**
 * Sprint 18.9c — Sentry stub no-op.
 *
 * Le SDK @sentry/react n'est pas activé pour le MVP (zéro coût, GlitchTip
 * self-hosted reporté Sprint 19+ cf. _AUDIT/MONITORING.md).
 *
 * Quand on voudra activer Sentry/GlitchTip :
 *  1. pnpm add @sentry/react
 *  2. Remettre le vrai code (cf. git history commit `e2beb9f`)
 *  3. Set VITE_SENTRY_DSN dans .env
 */

export function initSentry(): void {
  // no-op : Sentry désactivé en MVP.
}

export function captureException(_error: unknown, _context?: Record<string, unknown>): void {
  // no-op
}

export function setUser(_user: { id: string; email?: string; workspace_id?: string } | null): void {
  // no-op
}
