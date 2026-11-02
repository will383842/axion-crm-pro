/**
 * Outils des handlers de démo — à UTILISER par chaque lot d'écran.
 *
 *   http.get(api('/envoi/campagnes'), async ({ request }) => {
 *     await delai();
 *     const force = repondreEtatForce(request, { data: [], meta: paginer([], request).meta });
 *     if (force) return force;
 *     const liste = filtrerTexte(demoDb.campagnes.tous(), lireRecherche(request), ['nom']);
 *     return HttpResponse.json(paginer(liste, request));
 *   })
 *
 * `api('/chemin')` rend le motif `*\/api/v1/chemin` : il répond en démo
 * (`https://demo.invalid/api/v1/…`) comme sous Vitest (`https://api.localhost/api/v1/…`).
 */
import { HttpResponse, delay } from 'msw';

/** Motif MSW d'un chemin d'API, quelle que soit l'origine. */
export function api(chemin: string): string {
  return `*/api/v1${chemin.startsWith('/') ? chemin : `/${chemin}`}`;
}

/** Texte sans accents, en minuscules (recherche tolérante). */
export function normaliser(texte: string): string {
  return texte.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
}

/** Garde les éléments dont l'un des champs contient `q` (casse et accents ignorés). */
export function filtrerTexte<T>(liste: readonly T[], q: string | null | undefined, champs: ReadonlyArray<keyof T>): T[] {
  const cherche = normaliser(q ?? '');
  if (cherche === '') return [...liste];
  return liste.filter((e) =>
    champs.some((c) => {
      const v = e[c];
      return (typeof v === 'string' || typeof v === 'number') && normaliser(String(v)).includes(cherche);
    }),
  );
}

/** Le paramètre `q` d'une requête (recherche). */
export function lireRecherche(request: Request): string {
  return new URL(request.url).searchParams.get('q') ?? new URL(request.url).searchParams.get('search') ?? '';
}

export interface PageDemo<T> {
  data: T[];
  /** Forme du contrat d'Envoi (`page`, `par_page`, `total`, `pages`) et forme Laravel du CRM. */
  meta: { page: number; par_page: number; total: number; pages: number; current_page: number; per_page: number; last_page: number };
}

/** Découpe une liste selon `?page=` et `?par_page=` (ou `?per_page=`) de la requête. */
export function paginer<T>(liste: readonly T[], requete: Request | URL | string, parPageDefaut = 25): PageDemo<T> {
  const url = requete instanceof Request ? new URL(requete.url) : new URL(String(requete), 'https://demo.invalid');
  const parPage = Math.max(1, Math.min(200, Number(url.searchParams.get('par_page') ?? url.searchParams.get('per_page') ?? parPageDefaut) || parPageDefaut));
  const pages = Math.max(1, Math.ceil(liste.length / parPage));
  const page = Math.min(pages, Math.max(1, Number(url.searchParams.get('page') ?? 1) || 1));
  return {
    data: liste.slice((page - 1) * parPage, page * parPage),
    meta: { page, par_page: parPage, total: liste.length, pages, current_page: page, per_page: parPage, last_page: pages },
  };
}

/**
 * Latence réaliste (150 à 400 ms) dans le navigateur ; nulle sous Vitest pour
 * des tests rapides. Le hasard ici ne touche aucune donnée : il n'a pas besoin
 * de la graine.
 */
export async function delai(): Promise<void> {
  if (import.meta.env.MODE === 'test') return;
  await delay(150 + Math.floor(Math.random() * 250));
}

export type EtatForce = 'vide' | 'erreur' | 'chargement';
const ETATS: readonly EtatForce[] = ['vide', 'erreur', 'chargement'];
const CLE_ETAT = 'axion-demo:etat';

function lireEtatGlobal(): EtatForce | null {
  try {
    const v = globalThis.sessionStorage?.getItem(CLE_ETAT);
    return ETATS.includes(v as EtatForce) ? (v as EtatForce) : null;
  } catch {
    return null;
  }
}

/** Pose (ou retire, avec `null`) l'état forcé de toute la démo. Lu au démarrage depuis `?demo-etat=`. */
export function definirEtatForce(etat: EtatForce | null): void {
  try {
    if (etat === null) globalThis.sessionStorage?.removeItem(CLE_ETAT);
    else globalThis.sessionStorage?.setItem(CLE_ETAT, etat);
  } catch {
    // stockage indisponible : l'état forcé ne survit pas au rechargement.
  }
}

/**
 * L'état forcé demandé : `?demo-etat=vide|erreur|chargement` sur la requête,
 * sinon celui de la page (posé au démarrage de la démo). `null` : état normal.
 * Pour montrer les 4 états de chaque écran (chargement, vide, erreur, rempli).
 */
export function etatForce(request: Request): EtatForce | null {
  const local = new URL(request.url).searchParams.get('demo-etat');
  if (ETATS.includes(local as EtatForce)) return local as EtatForce;
  return lireEtatGlobal();
}

/**
 * Réponse toute faite pour un état forcé, ou `null` si l'état est normal :
 * `vide` → `corpsVide` ; `erreur` → 500 avec un message en français ;
 * `chargement` → la requête reste en vol.
 */
export async function repondreEtatForce(request: Request, corpsVide: unknown): Promise<Response | null> {
  const etat = etatForce(request);
  if (etat === null) return null;
  if (etat === 'chargement') {
    await delay('infinite');
    return null;
  }
  if (etat === 'erreur') {
    return HttpResponse.json({ error: 'demo_erreur', message: 'Le serveur de démonstration simule une panne.' }, { status: 500 });
  }
  return HttpResponse.json(corpsVide as never);
}

/** Corps d'erreur du contrat (`Erreur`). */
export function erreur(status: number, code: string, message: string): Response {
  return HttpResponse.json({ error: code, message }, { status });
}
