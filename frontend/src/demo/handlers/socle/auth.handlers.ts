/**
 * Socle de démo — CSRF, `/auth/me`, connexion par code de démonstration.
 *
 * Parcours : adresse + mot de passe de démo → « code attendu » → code de démo
 * (affiché dans le bandeau) → connecté. Aucun compte réel, rien ne sort.
 */
import { http, HttpResponse, type HttpHandler } from 'msw';
import { demoDb } from '../../base';
import { IDENTIFIANTS_DEMO } from '../../donnees';
import { api, delai } from '../../outils';
import { ecrireSession, lireSession } from '../../session';

function moi() {
  const u = demoDb.utilisateurs.tous()[0]!;
  const espace = demoDb.espaces.tous()[0]!;
  return {
    user: { id: u.id, name: u.name, email: u.email, current_workspace_id: espace.id, totp_enabled_at: u.totp_enabled_at, role: u.role },
    workspace: { id: espace.id, name: espace.name },
    // Démo : le compte montré est celui du propriétaire (droits d'export et de traitement RGPD).
    permissions: ['rgpd.view', 'rgpd.handle', 'data.export', 'contacts.view_pii'],
  };
}

export const handlers: HttpHandler[] = [
  http.get('*/sanctum/csrf-cookie', () => new HttpResponse(null, { status: 204 })),

  http.get(api('/auth/me'), async () => {
    await delai();
    if (lireSession() === 'deconnecte') {
      return HttpResponse.json({ message: 'Unauthenticated.' }, { status: 401 });
    }
    return HttpResponse.json(moi());
  }),

  http.post(api('/auth/login'), async ({ request }) => {
    await delai();
    const corps = (await request.json().catch(() => ({}))) as { email?: unknown; password?: unknown };
    const email = typeof corps.email === 'string' ? corps.email.trim().toLowerCase() : '';
    if (email !== IDENTIFIANTS_DEMO.email || corps.password !== IDENTIFIANTS_DEMO.motDePasse) {
      return HttpResponse.json(
        { message: 'auth.failed', errors: { email: ['Adresse e-mail ou mot de passe incorrect.'] } },
        { status: 422 },
      );
    }
    ecrireSession('code_attendu');
    return HttpResponse.json({ requires_2fa: true });
  }),

  http.post(api('/auth/2fa/verify'), async ({ request }) => {
    await delai();
    const corps = (await request.json().catch(() => ({}))) as { code?: unknown };
    if (lireSession() === 'deconnecte') {
      return HttpResponse.json({ message: 'Unauthenticated.' }, { status: 401 });
    }
    if (corps.code !== IDENTIFIANTS_DEMO.code) {
      return HttpResponse.json({ message: 'Code incorrect.', errors: { code: ['Code incorrect.'] } }, { status: 422 });
    }
    ecrireSession('connecte');
    return HttpResponse.json({ ok: true });
  }),

  http.post(api('/auth/logout'), async () => {
    await delai();
    ecrireSession('deconnecte');
    return new HttpResponse(null, { status: 204 });
  }),
];
