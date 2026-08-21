/**
 * `qualifierErreur()` — la SEULE lecture d'un échec d'API dans l'application.
 *
 * ═══ POURQUOI UNE FONCTION, ET PAS UN `if` DANS CHAQUE ÉCRAN ═══
 *
 * L'audit (D25-001) a mesuré 9 occurrences de `isError` sur 35 écrans. Les 4
 * écrans qui traitent l'erreur le font chacun à leur manière, et aucun ne lit le
 * code HTTP : `RoumaniePage:166` affiche « Impossible de charger le vivier
 * Roumanie » sous 403 comme sous 500. Dupliquer le raisonnement 35 fois, c'est
 * garantir 35 variantes.
 *
 * Cette fonction est le point unique où l'on décide ce qu'un échec SIGNIFIE. Les
 * écrans n'écrivent plus « if (status === 403) » : ils passent l'erreur telle
 * quelle à `<QueryErrorState error={…} />`.
 *
 * ⚠️ Ce fichier est SÉPARÉ de `tests/screens/etats-erreur.test.tsx` à dessein :
 * si l'export `qualifierErreur` manquait, l'import ESM lèverait et emporterait
 * TOUT le fichier — les assertions d'écran comprises, qui n'auraient alors plus
 * rien prouvé.
 */
import { describe, it, expect } from 'vitest';
import { AxiosError, AxiosHeaders } from 'axios';

import { qualifierErreur } from '@/lib/api';

/** Une erreur axios PORTEUSE d'une réponse HTTP, comme en produit. */
function erreurHttp(status: number, data: unknown = null): AxiosError {
  const config = { headers: new AxiosHeaders() };
  return new AxiosError('Request failed', String(status), config, {}, {
    status,
    statusText: '',
    data,
    headers: {},
    config,
  });
}

/** Une erreur axios SANS réponse : le serveur n'a jamais répondu. */
function erreurReseau(code: string): AxiosError {
  return new AxiosError('Network Error', code, { headers: new AxiosHeaders() }, {});
}

describe('qualifierErreur — le code HTTP décide, une fois pour toutes', () => {
  it('403 = refus de droits (et NON une panne)', () => {
    const q = qualifierErreur(erreurHttp(403));
    expect(q.nature).toBe('refus');
    expect(q.status).toBe(403);
  });

  it('401 = session expiree — distincte du refus de droits', () => {
    // 401 et 403 sont deux choses : « reconnecte-toi » et « demande un rôle ».
    // `src/lib/api.ts` redirige déjà sur 401, mais la promesse est tout de même
    // rejetée et React Query passe en erreur le temps de la navigation.
    expect(qualifierErreur(erreurHttp(401)).nature).toBe('session');
  });

  it('404 = introuvable — ni vide, ni refus', () => {
    expect(qualifierErreur(erreurHttp(404)).nature).toBe('introuvable');
  });

  it('422 et 429 = demande rejetee, pas panne serveur', () => {
    expect(qualifierErreur(erreurHttp(422)).nature).toBe('requete');
    expect(qualifierErreur(erreurHttp(429)).nature).toBe('requete');
  });

  it.each([500, 502, 503, 504])('%i = panne serveur', (status) => {
    const q = qualifierErreur(erreurHttp(status));
    expect(q.nature).toBe('panne');
    expect(q.status).toBe(status);
  });

  it('aucune reponse = serveur injoignable, et le statut vaut null', () => {
    const q = qualifierErreur(erreurReseau('ERR_NETWORK'));
    expect(q.nature).toBe('reseau');
    // ⚠️ `null` et non `0` : un écran qui afficherait « code HTTP 0 » ferait
    // croire à une réponse du serveur là où il n'y en a eu aucune.
    expect(q.status).toBeNull();
  });

  it('expiration du delai (30 s, api.ts:10) = injoignable, pas panne', () => {
    expect(qualifierErreur(erreurReseau('ECONNABORTED')).nature).toBe('reseau');
  });

  it('le code applicatif du corps est remonte tel quel', () => {
    // `two_factor_required` / `first_login_required` sont lus par l'intercepteur ;
    // les écrans peuvent avoir besoin de les distinguer d'un 403 RBAC.
    const q = qualifierErreur(erreurHttp(403, { error: 'two_factor_required' }));
    expect(q.code).toBe('two_factor_required');
  });

  it('corps sans champ `error` : le code vaut null, jamais une chaine vide', () => {
    expect(qualifierErreur(erreurHttp(403, { message: 'Forbidden' })).code).toBeNull();
  });

  it('TEMOIN — ce qui n’est pas une erreur axios ne devient pas un refus', () => {
    // Une exception de rendu, un `JSON.parse` raté, un `undefined` : la fonction
    // ne doit RIEN inventer. Un `default: 'refus'` enverrait l'utilisateur
    // demander un droit qu'il possède déjà.
    expect(qualifierErreur(new Error('boum')).nature).toBe('inconnue');
    expect(qualifierErreur(null).nature).toBe('inconnue');
    expect(qualifierErreur(undefined).nature).toBe('inconnue');
    expect(qualifierErreur('403').nature).toBe('inconnue');
    expect(qualifierErreur({ response: { status: 403 } }).nature).toBe('inconnue');
  });

  it('TEMOIN — `null` n’est pas une erreur : le statut reste null partout', () => {
    for (const valeur of [null, undefined, new Error('x'), 'texte']) {
      expect(qualifierErreur(valeur).status).toBeNull();
      expect(qualifierErreur(valeur).code).toBeNull();
    }
  });
});
