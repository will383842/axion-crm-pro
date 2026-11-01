/**
 * GARDE — les handlers de la démo sont chargés tout seuls, et sans doublon.
 *
 * Chaque lot d'écran dépose SON fichier `src/demo/handlers/<produit>/<zone>.handlers.ts`
 * sans toucher `handlers/index.ts` (qui les ramasse par `import.meta.glob`).
 * Deux pièges, tenus ici :
 *  · un fichier qui oublie `export const handlers` serait ignoré en silence ;
 *  · deux lots qui déclarent la même route : MSW prend la première, l'autre
 *    écran affiche des données qui ne sont pas les siennes.
 */
import { describe, it, expect } from 'vitest';
import type { HttpHandler } from 'msw';
import { getResponse } from 'msw';
import { handlers, rassemblerHandlers, cleRoute } from '@/demo/handlers';
import { deciderRequeteNonGeree, gardeReseau, MESSAGE_REFUS } from '@/demo/reseau';

const modules = import.meta.glob<Record<string, unknown>>('/src/demo/handlers/**/*.handlers.ts', { eager: true });

describe('handlers de démo — chargement', () => {
  it('trouve les fichiers du socle', () => {
    const noms = Object.keys(modules);
    expect(noms.length).toBeGreaterThanOrEqual(4);
    expect(noms.some((n) => n.includes('/handlers/socle/'))).toBe(true);
  });

  it('chaque fichier *.handlers.ts exporte un tableau `handlers` non vide', () => {
    const fautifs = Object.entries(modules)
      .filter(([, m]) => !Array.isArray(m['handlers']) || (m['handlers'] as unknown[]).length === 0)
      .map(([n]) => n);
    expect(fautifs).toEqual([]);
  });

  it('l’agrégateur sert tous les handlers de tous les fichiers', () => {
    const total = Object.values(modules).reduce((n, m) => n + (m['handlers'] as unknown[]).length, 0);
    expect(handlers.length).toBe(total);
  });

  it('aucune route n’est déclarée deux fois', () => {
    const vues = new Map<string, number>();
    for (const h of handlers) vues.set(cleRoute(h), (vues.get(cleRoute(h)) ?? 0) + 1);
    expect([...vues].filter(([, n]) => n > 1).map(([cle]) => cle)).toEqual([]);
  });

  it('sert les routes du socle', () => {
    const cles = handlers.map(cleRoute);
    for (const attendue of [
      'GET */sanctum/csrf-cookie',
      'GET */api/v1/auth/me',
      'POST */api/v1/auth/login',
      'POST */api/v1/auth/2fa/verify',
      'GET */api/v1/config/features',
      'GET */api/v1/notifications',
      'GET */api/v1/envoi/etat',
      'POST */api/v1/envoi/tout-arreter',
      'POST */api/v1/envoi/reprendre',
      'GET */api/v1/envoi/compteurs',
    ]) {
      expect(cles, attendue).toContain(attendue);
    }
  });
});

describe('handlers de démo — TÉMOIN', () => {
  it('un fichier sans export `handlers` fait échouer le rassemblement', () => {
    expect(() =>
      rassemblerHandlers({ '/src/demo/handlers/crm/oubli.handlers.ts': { autreChose: [] } }),
    ).toThrow(/oubli\.handlers\.ts/);
  });

  it('une route déclarée deux fois fait échouer le rassemblement', () => {
    const [un] = handlers as [HttpHandler];
    expect(() =>
      rassemblerHandlers({
        '/src/demo/handlers/crm/a.handlers.ts': { handlers: [un] },
        '/src/demo/handlers/crm/b.handlers.ts': { handlers: [un] },
      }),
    ).toThrow(/deux fois/);
  });
});

// ── Les handlers du socle répondent au VRAI client de l'application ───────
// Même tableau qu'en démo, servi ici par le serveur MSW de Vitest
// (`tests/msw/server.ts`) : preuve que `api('/chemin')` vaut aussi sous Vitest.

describe('handlers du socle — servis au client réel (src/lib/api.ts)', () => {
  it('connexion : identifiants de démo, code de démo, puis /auth/me', async () => {
    const { server } = await import('../msw/server');
    const { api: client } = await import('@/lib/api');
    const { IDENTIFIANTS_DEMO } = await import('@/demo/donnees');
    server.use(...handlers);

    await expect(client.get('/auth/me')).rejects.toMatchObject({ response: { status: 401 } });
    await expect(client.post('/auth/login', { email: 'x@example.test', password: 'non' })).rejects.toMatchObject({ response: { status: 422 } });
    const login = await client.post<{ requires_2fa: boolean }>('/auth/login', { email: IDENTIFIANTS_DEMO.email, password: IDENTIFIANTS_DEMO.motDePasse });
    expect(login.data.requires_2fa).toBe(true);
    await expect(client.post('/auth/2fa/verify', { code: '000000' })).rejects.toMatchObject({ response: { status: 422 } });
    await client.post('/auth/2fa/verify', { code: IDENTIFIANTS_DEMO.code });
    const moi = await client.get<{ user: { email: string }; workspace: { name: string } }>('/auth/me');
    expect(moi.data.user.email).toBe(IDENTIFIANTS_DEMO.email);
    expect(moi.data.workspace.name).toBe('Axion (démo)');
  });

  it('features selon l’offre, notifications filtrées, tout arrêter puis reprendre', async () => {
    const { server } = await import('../msw/server');
    const { api: client } = await import('@/lib/api');
    const { choisirOffre } = await import('@/demo/offres');
    const { demoDb } = await import('@/demo/base');
    demoDb.reinitialiser();
    server.use(...handlers);

    const deux = await client.get<{ produits: unknown }>('/config/features');
    expect(deux.data.produits).toEqual({ crm: 'actif', envoi: 'actif' });
    const toutes = await client.get<{ data: Array<{ produit: string; lien: string }>; unread_count: number }>('/notifications');
    expect(toutes.data.data).toHaveLength(6);
    expect(toutes.data.unread_count).toBe(6);
    expect(toutes.data.data.every((n) => n.lien.startsWith('/'))).toBe(true);

    choisirOffre('envoi');
    const envoiSeul = await client.get<{ produits: unknown }>('/config/features');
    expect(envoiSeul.data.produits).toEqual({ crm: 'absent', envoi: 'actif' });
    const sansCrm = await client.get<{ data: Array<{ produit: string }> }>('/notifications');
    expect(sansCrm.data.data.every((n) => n.produit !== 'crm')).toBe(true);

    await expect(client.post('/envoi/reprendre')).rejects.toMatchObject({ response: { status: 409 } });
    const arret = await client.post<{ etat: string; feu: string }>('/envoi/tout-arreter', { motif: 'Essai' });
    expect(arret.data).toMatchObject({ etat: 'arrete', feu: 'rouge' });
    const compteurs = await client.get<{ en_file: number; boites_total: number }>('/envoi/compteurs');
    expect(compteurs.data).toMatchObject({ en_file: 0, boites_total: 8 });
    const reprise = await client.post<{ etat: string }>('/envoi/reprendre');
    expect(reprise.data.etat).toBe('actif');
    demoDb.reinitialiser();
  });
});

describe('garde réseau de la démo — aucune requête ne sort', () => {
  const ORIGINE = 'http://127.0.0.1:4173';

  it('laisse passer la même origine, refuse tout autre hôte', () => {
    expect(deciderRequeteNonGeree(`${ORIGINE}/assets/index.js`, ORIGINE)).toBe('laisser');
    expect(deciderRequeteNonGeree('/mockServiceWorker.js', ORIGINE)).toBe('laisser');
    expect(deciderRequeteNonGeree('data:image/png;base64,AAAA', ORIGINE)).toBe('laisser');
    expect(deciderRequeteNonGeree('https://tuiles.example/10/512/360.png', ORIGINE)).toBe('refuser');
    expect(deciderRequeteNonGeree('https://demo.invalid/autre-chose', ORIGINE)).toBe('refuser');
    expect(deciderRequeteNonGeree('http://127.0.0.1:9999/', ORIGINE)).toBe('refuser');
  });

  it('une tuile de carte reçoit une erreur réseau et un avertissement, jamais le réseau', async () => {
    const avertissements: string[] = [];
    const garde = gardeReseau(ORIGINE, (m) => avertissements.push(m));
    const reponse = await getResponse([garde], new Request('https://tuiles.example/10/512/360.png'));
    expect(reponse?.type).toBe('error');
    expect(avertissements).toEqual([`${MESSAGE_REFUS} GET https://tuiles.example/10/512/360.png`]);
  });

  it('la même origine est rendue au navigateur (passthrough), sans avertissement', async () => {
    const avertissements: string[] = [];
    const garde = gardeReseau(ORIGINE, (m) => avertissements.push(m));
    const reponse = await getResponse([garde], new Request(`${ORIGINE}/assets/index.js`));
    expect(reponse?.headers.get('x-msw-intention')).toBe('passthrough');
    expect(avertissements).toEqual([]);
  });
});
