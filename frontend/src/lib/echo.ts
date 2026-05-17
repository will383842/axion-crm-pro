/**
 * Sprint 18.3 — Laravel Echo + Pusher-JS client pour WS temps réel via Reverb.
 *
 * Usage :
 *   import { initEcho, useEchoToasts } from '@/lib/echo';
 *   const echo = initEcho();
 *   echo.private(`workspace.${workspaceId}`)
 *       .listen('.notification.created', (e) => toast(e.title));
 */

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { toast } from 'sonner';

declare global {
  interface Window {
    Pusher: typeof Pusher;
    Echo?: Echo<'reverb'>;
  }
}

interface InitOptions {
  appKey?: string;
  host?: string;
  port?: number;
  scheme?: 'http' | 'https';
  authEndpoint?: string;
}

let echoInstance: Echo<'reverb'> | null = null;

export function initEcho(opts: InitOptions = {}): Echo<'reverb'> | null {
  if (echoInstance) return echoInstance;

  const env = (import.meta as { env?: Record<string, string | undefined> }).env ?? {};

  // Sprint 18.9c — Echo désactivé par défaut (Reverb pas activé en MVP).
  // Activer en posant VITE_ECHO_ENABLED=true + VITE_REVERB_APP_KEY dans .env.
  if (env['VITE_ECHO_ENABLED'] !== 'true' || !env['VITE_REVERB_APP_KEY']) {
    return null;
  }

  window.Pusher = Pusher;

  const appKey   = opts.appKey  ?? env['VITE_REVERB_APP_KEY'];
  const host     = opts.host    ?? env['VITE_REVERB_HOST']     ?? window.location.hostname;
  const port     = opts.port    ?? Number(env['VITE_REVERB_PORT'] ?? 443);
  const scheme   = (opts.scheme ?? env['VITE_REVERB_SCHEME']   ?? 'https') as 'http' | 'https';
  const authEndpoint = opts.authEndpoint ?? '/broadcasting/auth';

  echoInstance = new Echo({
    broadcaster: 'reverb',
    key: appKey,
    wsHost: host,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint,
    withCredentials: true,
  });

  window.Echo = echoInstance;
  return echoInstance;
}

export function getEcho(): Echo<'reverb'> | null {
  return echoInstance;
}

export function disconnectEcho(): void {
  if (echoInstance) {
    echoInstance.disconnect();
    echoInstance = null;
    if (typeof window !== 'undefined') {
      delete window.Echo;
    }
  }
}

/**
 * Helper : subscribe au channel workspace et déclenche un toast Sonner sur chaque NotificationCreated.
 * À monter dans RootLayout après login (quand workspaceId connu).
 */
export function subscribeWorkspaceNotifications(workspaceId: string): () => void {
  const echo = initEcho();
  if (!echo) return () => undefined;  // Echo désactivé, pas de subscribe
  const channel = echo.private(`workspace.${workspaceId}`);

  channel.listen('.notification.created', (event: {
    notification_id: number;
    title: string;
    body: string;
    severity: 'info' | 'success' | 'warning' | 'error';
    action_url?: string | null;
  }) => {
    const showToast = (() => {
      switch (event.severity) {
        case 'success': return toast.success;
        case 'warning': return toast.warning;
        case 'error':   return toast.error;
        default:        return toast.info;
      }
    })();

    showToast(event.title, {
      description: event.body,
      action: event.action_url
        ? { label: 'Voir', onClick: () => window.location.assign(event.action_url!) }
        : undefined,
    });
  });

  channel.listen('.scrape-job.completed', (event: { status: string; companies_created: number }) => {
    if (event.status === 'success') {
      toast.success(`Scrape terminé : ${event.companies_created} nouvelles entreprises`);
    } else if (event.status === 'failed') {
      toast.error('Scrape échoué');
    }
  });

  channel.listen('.company.enriched', (event: { company_name: string; new_quality_score: number }) => {
    toast.info(`Entreprise enrichie : ${event.company_name}`, {
      description: `Quality score → ${event.new_quality_score}/100`,
    });
  });

  // Cleanup
  return () => {
    echo.leave(`private-workspace.${workspaceId}`);
  };
}
