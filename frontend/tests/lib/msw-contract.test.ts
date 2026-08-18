/**
 * LE HARNAIS SE TESTE LUI-MÊME.
 *
 * Une garde ne vaut que si elle rougit. Trois promesses sont faites aux agents
 * qui écriront les écrans suivants ; si l'une d'elles est fausse, leurs tests
 * seront verts pour de mauvaises raisons. On les vérifie donc ici, une fois.
 *
 *  1. MSW intercepte bien les requêtes d'axios SOUS jsdom (adaptateur XHR).
 *  2. `ensureCsrf()` traverse le harnais : un POST demande le cookie Sanctum.
 *     C'est précisément ce qu'un `vi.mock('@/lib/api')` aurait supprimé.
 *  3. Un 401 déclenche `window.location.assign('/login')` et cette redirection
 *     est OBSERVABLE, au lieu de lever « Not implemented: navigation ».
 */
import { describe, it, expect, beforeEach } from 'vitest';
import { server } from '../msw/server';
import {
  API_ORIGIN,
  getJson,
  getStatus,
  http,
  HttpResponse,
  recordGet,
  recordPost,
} from '../msw/handlers';
import { navigations, setLocation, wasRedirectedToLogin } from '../helpers/location';
import { resetCsrfFlag } from '../helpers/renderScreen';
import type * as ApiModule from '@/lib/api';

// Ce fichier est le SEUL à parler du drapeau `csrfFetched` : il le rembobine
// avant chaque cas, ce qui suppose un import dynamique de `@/lib/api`.
let api: typeof ApiModule.api;

beforeEach(async () => {
  ({ api } = await resetCsrfFlag());
});

describe('harnais MSW — interception réelle d’axios', () => {
  it('répond à un GET passé par l’instance `api`', async () => {
    server.use(getJson('/ping', { ok: true }));
    const r = await api.get('/ping');
    expect(r.data).toEqual({ ok: true });
  });

  it('voit la query string RÉELLEMENT construite par axios, pas l’objet `params`', async () => {
    // Un mock de module recevrait `{ params: { period: '30d' } }` et ne
    // prouverait rien sur l'URL émise.
    const { handler, urls } = recordGet('/dashboard/stats', {});
    server.use(handler);

    await api.get('/dashboard/stats', { params: { period: '30d' } });

    expect(urls).toHaveLength(1);
    expect(new URL(urls[0] as string).searchParams.get('period')).toBe('30d');
  });
});

describe('harnais MSW — le cookie CSRF passe par le test', () => {
  it('un POST demande `GET /sanctum/csrf-cookie` AVANT d’émettre', async () => {
    const order: string[] = [];
    server.use(
      http.get(`${API_ORIGIN}/sanctum/csrf-cookie`, () => {
        order.push('csrf');
        return new HttpResponse(null, { status: 204 });
      }),
    );
    const { handler, bodies } = recordPost<{ a: number }>('/echo', { ok: true });
    server.use(handler);

    await api.post('/echo', { a: 1 });
    order.push('post');

    expect(order).toEqual(['csrf', 'post']);
    expect(bodies).toEqual([{ a: 1 }]);
  });

  it('le drapeau de module fait que le SECOND POST ne redemande PAS le cookie', async () => {
    // Ce n'est pas un défaut, c'est le contrat de `src/lib/api.ts:13`. On
    // l'assure pour que personne n'écrive un test qui suppose l'inverse.
    let csrfCalls = 0;
    server.use(
      http.get(`${API_ORIGIN}/sanctum/csrf-cookie`, () => {
        csrfCalls += 1;
        return new HttpResponse(null, { status: 204 });
      }),
      http.post(`${API_ORIGIN}/api/v1/echo`, () => HttpResponse.json({ ok: true })),
    );

    await api.post('/echo', {});
    await api.post('/echo', {});

    expect(csrfCalls).toBe(1);
  });
});

describe('harnais — l’intercepteur 401 est observable', () => {
  it('redirige vers /login sur 401 et l’enregistre', async () => {
    setLocation('http://localhost:3000/companies/42');
    server.use(getStatus('/companies/42', 401));

    await expect(api.get('/companies/42')).rejects.toBeTruthy();

    expect(wasRedirectedToLogin()).toBe(true);
    expect(navigations()).toEqual(['/login']);
    // Et le stub met le pathname à jour, comme un vrai navigateur : sans ça,
    // le garde-fou anti-boucle de `api.ts` ne serait jamais éprouvé.
    expect(window.location.pathname).toBe('/login');
  });

  it('NE redirige PAS quand on est déjà sur /login (pas de boucle)', async () => {
    setLocation('http://localhost:3000/login');
    server.use(getStatus('/auth/login-check', 401));

    await expect(api.get('/auth/login-check')).rejects.toBeTruthy();

    expect(navigations()).toEqual([]);
  });
});
