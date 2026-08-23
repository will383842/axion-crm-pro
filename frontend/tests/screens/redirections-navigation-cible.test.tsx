/**
 * LES REDIRECTIONS DU §8.2 DE `10_NAVIGATION-CIBLE.md` — et ce qu'elles valent.
 *
 * ═══ POURQUOI CETTE GARDE EXISTE ═══
 *
 * Le document de refonte prescrit HUIT redirections. Le tableau §12 du mandat
 * en comptait **0 écrite** au 2026-08-23. Quatre le sont désormais.
 *
 * Une redirection est exactement le genre de ligne qui disparaît sans bruit :
 * elle n'a pas d'écran, personne ne la regarde, et le jour où un refactor la
 * retire, l'adresse retombe en 404 — c'est-à-dire dans l'état que `D23-009`
 * décrit, où l'écran « Page introuvable » se rend **hors du gabarit**, sans
 * barre latérale et avec un seul lien.
 *
 * ═══ CE QUE LA GARDE MESURE, ET CE QU'ELLE NE MESURE PAS ═══
 *
 * Elle appelle le `beforeLoad` de chaque route et lit la redirection JETÉE.
 * C'est la mesure de ce que la route FAIT, pas de ce que le fichier contient :
 * un `grep` sur `redirect(` passerait au vert sur un appel commenté ou mort.
 *
 * ⚠️ Elle ne prouve PAS que la cible s'affiche : c'est le rôle des tests
 * d'écran. Elle prouve que l'adresse ne tombe plus dans le vide, et qu'elle
 * vise ce que le §8.2 prescrit.
 *
 * ═══ LES QUATRE QUI MANQUENT, ET QUI SONT ICI AUSSI ═══
 *
 * Le dernier cas fige les QUATRE redirections NON écrites, avec leur raison.
 * Sans lui, une lecture rapide du fichier conclurait « les redirections sont
 * faites ». Elles sont faites À MOITIÉ, et la moitié manquante est bloquée sur
 * des vues épinglées qui n'existent pas encore.
 */
import { describe, expect, it } from 'vitest';
import { isRedirect } from '@tanstack/react-router';

import { routeTree } from '@/app/routeTree';

type RouteLike = {
  options?: { path?: string; beforeLoad?: (...args: never[]) => unknown };
  children?: unknown;
};

/** Toutes les routes de l'arbre, à plat, quelle que soit la profondeur. */
function toutesLesRoutes(noeud: unknown): RouteLike[] {
  const route = noeud as RouteLike;
  const enfants = route?.children;
  const liste: RouteLike[] = route?.options !== undefined ? [route] : [];

  if (Array.isArray(enfants)) {
    for (const enfant of enfants) {
      liste.push(...toutesLesRoutes(enfant));
    }
  } else if (enfants !== null && typeof enfants === 'object') {
    for (const enfant of Object.values(enfants as Record<string, unknown>)) {
      liste.push(...toutesLesRoutes(enfant));
    }
  }

  return liste;
}

const ROUTES = toutesLesRoutes(routeTree);

function routeDuChemin(chemin: string): RouteLike {
  const trouvee = ROUTES.find((r) => r.options?.path === chemin);
  if (trouvee === undefined) {
    throw new Error(`Aucune route ne déclare le chemin ${chemin}.`);
  }
  return trouvee;
}

/**
 * Joue le `beforeLoad` de la route et rend la redirection jetée.
 *
 * Si la route ne jette RIEN, on échoue explicitement : une redirection qui ne
 * redirige pas est le défaut même que cette garde surveille.
 */
function redirectionJetee(chemin: string): { to?: string; search?: unknown } {
  const route = routeDuChemin(chemin);
  const avant = route.options?.beforeLoad;

  if (typeof avant !== 'function') {
    throw new Error(`La route ${chemin} ne déclare aucun beforeLoad : elle ne redirige rien.`);
  }

  try {
    avant();
  } catch (jete: unknown) {
    if (isRedirect(jete)) {
      // ⚠️ MESURE, PAS SUPPOSITION. `redirect()` de TanStack v1 n'expose PAS
      // `to` a la racine : tout est sous `options` — `{ options: { to,
      // replace, statusCode } }`. La premiere version de cette garde lisait
      // `jete.to` et recevait `undefined` : elle aurait pu etre « corrigee »
      // en assouplissant l'assertion, ce qui l'aurait rendue muette.
      return (jete as { options: { to?: string; search?: unknown } }).options;
    }
    throw jete;
  }

  throw new Error(`La route ${chemin} n'a rien jeté : aucune redirection.`);
}

describe('§8.2 — les redirections écrites', () => {
  // ── TÉMOIN ────────────────────────────────────────────────────────────────
  // Sans lui, un arbre vide ferait passer tout le reste au vert sur du néant :
  // `toutesLesRoutes` rendrait `[]`, chaque recherche lèverait, et un `it` qui
  // n'assert rien ne rougirait pas pour autant.
  it('TÉMOIN — l’arbre de routes est bien peuplé', () => {
    expect(ROUTES.length).toBeGreaterThan(25);
  });

  it('§8.2 n. 24 — /cold-email mène à /pas-encore-livre?lot=L7', () => {
    const r = redirectionJetee('/cold-email');
    expect(r.to).toBe('/pas-encore-livre');
    expect(r.search).toEqual({ lot: 'L7' });
  });

  it('§8.2 n. 25 — /linkedin mène à /pas-encore-livre?lot=L7', () => {
    const r = redirectionJetee('/linkedin');
    expect(r.to).toBe('/pas-encore-livre');
    expect(r.search).toEqual({ lot: 'L7' });
  });

  it('§8.2 n. 26 — /crm mène à /contacts, et non plus au 404 hors gabarit', () => {
    expect(redirectionJetee('/crm').to).toBe('/contacts');
  });

  it('§8.2 n. 27 — /analytics mène au tableau de bord (écart assumé : /pilotage n’existe pas)', () => {
    expect(redirectionJetee('/analytics').to).toBe('/');
  });

  it('la cible /pas-encore-livre existe et lit son lot dans l’adresse', () => {
    const route = routeDuChemin('/pas-encore-livre');
    const valider = (route.options as { validateSearch?: (r: unknown) => unknown }).validateSearch;

    expect(typeof valider).toBe('function');
    expect(valider?.({ lot: 'L7' })).toEqual({ lot: 'L7' });
    // Une valeur absente ne devient PAS `{ lot: undefined }` : le dépôt est en
    // `exactOptionalPropertyTypes`, et les deux ne sont pas la même chose.
    expect(valider?.({})).toEqual({});
  });

  it('les deux écrans de démonstration du lot L7 ont disparu du produit', () => {
    // `I48-008` : « le seul endroit où le produit DÉPASSE son périmètre ».
    // Tant qu'un composant reste monté sur ces chemins, l'écran s'affiche —
    // fût-ce une fraction de seconde avant la redirection.
    expect(routeDuChemin('/cold-email').options).not.toHaveProperty('component');
    expect(routeDuChemin('/linkedin').options).not.toHaveProperty('component');
  });
});

describe('§8.2 — les redirections NON écrites, et pourquoi', () => {
  /**
   * 🔴 CE CAS FIGE UN MANQUE, PAS UNE RÉUSSITE.
   *
   * Les quatre chemins ci-dessous doivent, selon le §8.2, devenir des 301. Ils
   * montent aujourd'hui un écran QUI FONCTIONNE, et leurs cibles n'existent
   * pas : les vues épinglées `?vue=presse` et le filtre `?pays=RO` figurent
   * eux-mêmes parmi les éléments « créés » du §8.2.
   *
   * Écrire ces redirections aujourd'hui remplacerait trois écrans utilisables
   * par une liste non filtrée — une régression déguisée en avancement.
   *
   * Le jour où les vues épinglées existent, ce test rougira : ce sera le
   * signal, et le bon moment pour écrire les quatre dernières.
   */
  const ENCORE_DES_ECRANS = [
    { chemin: '/console/contacts', cible: '/companies', blocage: 'fusion d’un écran de la console v2 — décision produit' },
    { chemin: '/journalists', cible: '/contacts?vue=presse', blocage: 'la vue épinglée « Presse » n’existe pas' },
    { chemin: '/media', cible: '/companies?vue=presse', blocage: 'la vue épinglée « Presse » n’existe pas' },
    { chemin: '/international/roumanie', cible: '/coverage?pays=RO', blocage: 'le filtre pays n’existe pas' },
  ];

  it.each(ENCORE_DES_ECRANS)(
    '$chemin monte encore un écran (cible $cible — bloqué : $blocage)',
    ({ chemin }) => {
      const route = routeDuChemin(chemin);
      expect(route.options).toHaveProperty('component');
      expect(route.options?.beforeLoad).toBeUndefined();
    },
  );
});
