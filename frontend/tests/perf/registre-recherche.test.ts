/**
 * GARDE DE REGISTRE — aucun ecran ne remet l'etat d'un champ de saisie
 * DIRECTEMENT dans une `queryKey`.
 *
 * ── Pourquoi une garde de SOURCE en plus de la garde de COMPORTEMENT ──────
 * `tests/perf/recherche-anti-rebond.test.tsx` monte UN ecran reel et compte
 * les requetes : c'est la preuve forte, et elle coute 4,2 s. Huit ecrans
 * couteraient une demi-minute a chaque `pnpm test`, pour huit fois la meme
 * mecanique.
 *
 * Cette garde-ci est la contrepartie faible et rapide : elle lit la source des
 * SEPT AUTRES sites et refuse deux choses.
 *   1. que la variable d'etat BRUTE du champ apparaisse dans une `queryKey` ;
 *   2. que l'ecran n'importe pas `useAntiRebond`.
 *
 * Elle ne prouve pas que l'anti-rebond FONCTIONNE. Elle prouve qu'il est
 * cable, et surtout : elle empeche un HUITIEME ecran de reintroduire le
 * defaut en silence.
 *
 * ── Le defaut (G42-010, S1) ───────────────────────────────────────────────
 * Mesure du 2026-08-20 : les huit sites ci-dessous placaient l'etat du champ
 * dans la cle. Sur `/companies`, mesure au reseau : 11 requetes pour les
 * 11 caracteres de « boulangerie ».
 *
 * ⚠️ CE QU'ELLE NE COUVRE PAS :
 *  · un ecran ABSENT du registre. C'est sa limite structurelle : elle ne
 *    decouvre pas, elle retient. Le dernier cas (« le registre couvre tous
 *    les champs de recherche du depot ») compense en partie, en comptant les
 *    `SearchInput` du depot.
 *  · un anti-rebond cable mais pose sur la mauvaise variable.
 *  · un filtre client (`AuditLogsPage`, `ScraperRunsPage` : ils filtrent en
 *    memoire, sans requete) — ils ne sont volontairement PAS au registre.
 */
import { describe, it, expect } from 'vitest';
import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ICI = path.dirname(fileURLToPath(import.meta.url));
const RACINE_FRONT = path.resolve(ICI, '..', '..');
const SRC = path.join(RACINE_FRONT, 'src');

// ── Fonctions pures, pour que le TEMOIN puisse les eprouver ───────────────

function sansCommentaires(texte: string): string {
  return texte.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/[^\n]*/g, '$1');
}

/**
 * Extrait le CONTENU de chaque `queryKey: [ … ]` du module.
 *
 * ⚠️ On s'arrete au premier `]` : aucune `queryKey` du depot ne contient de
 * tableau imbrique (verifie le 2026-08-20 sur les 14 occurrences). Un jour ou
 * ce serait faux, la garde tronquerait la cle — elle en verrait MOINS, donc
 * elle risquerait un faux vert. C'est pourquoi le TEMOIN l'eprouve.
 */
export function clesDeRequete(source: string): string[] {
  const net = sansCommentaires(source);
  const trouves: string[] = [];
  const motif = /queryKey\s*:\s*\[([^\]]*)\]/g;
  let m: RegExpExecArray | null;
  while ((m = motif.exec(net)) !== null) {
    const contenu = m[1];
    if (contenu !== undefined) trouves.push(contenu);
  }
  return trouves;
}

/**
 * La variable `nom` apparait-elle comme LECTURE DE VARIABLE dans le texte ?
 *
 * Trois formes ressemblent au jeton sans en etre une, et les trois existent
 * vraiment dans les clefs de ce depot. Les ecarter n'affaiblit rien — une
 * variable ne se lit dans aucune des trois :
 *
 *  1. DANS UNE CHAINE — `['global-search', rechercheDifferee]`. Le nom du
 *     cache contient « search » ; sans ce nettoyage, `GlobalSearch.tsx` serait
 *     ROUGE POUR TOUJOURS, y compris repare. Un faux rouge permanent finit
 *     toujours par etre desactive, donc par tout emporter avec lui.
 *  2. NOM DE PROPRIETE — `{ search: rechercheDifferee }` dans
 *     `CampaignsListPage`. C'est une CLEF d'objet, pas une lecture.
 *     ⚠️ La forme abregee `{ search }` reste, elle, une LECTURE — et c'est
 *     exactement ce que cet ecran ecrivait avant reparation. Elle est donc
 *     toujours detectee : le TEMOIN le montre.
 *  3. ACCES DE PROPRIETE — `f.search`, sur un objet deja differe.
 */
export function contientJeton(texte: string, nom: string): boolean {
  const sansChaines = texte.replace(/'[^']*'/g, "''").replace(/"[^"]*"/g, '""').replace(/`[^`]*`/g, '``');
  // `[^\w$.]` ecarte l'acces de propriete ; `(?!\s*:)` ecarte le nom de propriete.
  return new RegExp(`(^|[^\\w$.])${nom}(?![\\w$])(?!\\s*:)`).test(sansChaines);
}

// ── Le registre ───────────────────────────────────────────────────────────

/**
 * Les huit sites de recherche SERVEUR du depot, releves le 2026-08-20.
 *
 * `variableBrute` est le nom de l'etat du champ tel qu'il est declare par
 * `useState` — c'est LUI qui ne doit plus paraitre dans une `queryKey`.
 *
 * `CompaniesListPage` porte un objet `filter` entier plutot qu'une chaine :
 * c'est le meme defaut, avec un emballage.
 */
const REGISTRE: ReadonlyArray<{ fichier: string; variableBrute: string }> = [
  { fichier: 'src/features/companies/CompaniesListPage.tsx', variableBrute: 'filter' },
  { fichier: 'src/features/media/MediaListPage.tsx', variableBrute: 'filter' },
  { fichier: 'src/features/contacts/ContactsListPage.tsx', variableBrute: 'search' },
  { fichier: 'src/features/media/JournalistsListPage.tsx', variableBrute: 'search' },
  { fichier: 'src/features/campaigns/CampaignsListPage.tsx', variableBrute: 'search' },
  { fichier: 'src/features/crm-console/CandidatesPage.tsx', variableBrute: 'search' },
  { fichier: 'src/features/crm-console/ContactsHubPage.tsx', variableBrute: 'search' },
  { fichier: 'src/components/ui/GlobalSearch.tsx', variableBrute: 'search' },
];

const IMPORT_ATTENDU = '@/hooks/useAntiRebond';

function lire(relatif: string): string {
  const complet = path.join(RACINE_FRONT, relatif);
  return existsSync(complet) ? readFileSync(complet, 'utf8') : '';
}

// ── Verrous anti-« vert sans mesure » ─────────────────────────────────────

describe('registre de recherche — la garde a bien les sources sous les yeux', () => {
  it('trouve les huit fichiers du registre', () => {
    const manquants = REGISTRE.filter(({ fichier }) => lire(fichier).length < 500);
    // Un chemin faux rendrait une source vide : zero `queryKey`, zero jeton,
    // donc zero echec. C'est le faux vert le plus facile a fabriquer ici.
    expect(manquants.map((e) => e.fichier)).toEqual([]);
  });

  it('trouve de vraies clefs de requete dedans', () => {
    for (const { fichier } of REGISTRE) {
      expect(clesDeRequete(lire(fichier)).length, fichier).toBeGreaterThanOrEqual(1);
    }
  });

  it('trouve le module d anti-rebond partage', () => {
    expect(existsSync(path.join(SRC, 'hooks', 'useAntiRebond.ts'))).toBe(true);
  });
});

// ── La regle ──────────────────────────────────────────────────────────────

describe('registre de recherche — aucune saisie brute dans une clef', () => {
  it.each(REGISTRE)('$fichier ne met pas $variableBrute dans sa queryKey', ({ fichier, variableBrute }) => {
    const fautives = clesDeRequete(lire(fichier)).filter((cle) => contientJeton(cle, variableBrute));
    // Diff lisible : vitest affiche la clef fautive telle qu'elle est ecrite.
    expect(fautives).toEqual([]);
  });

  it.each(REGISTRE)('$fichier importe bien l anti-rebond partage', ({ fichier }) => {
    expect(lire(fichier)).toContain(IMPORT_ATTENDU);
  });

  it('le registre couvre tous les champs de recherche relies a une requete', () => {
    // Compensation partielle de la limite structurelle : on compte les ecrans
    // qui portent A LA FOIS un champ de recherche et une `queryKey`. Chacun
    // doit etre au registre, ou filtrer en memoire.
    const FILTRES_EN_MEMOIRE = [
      // Ces deux-la chargent une page puis filtrent avec `useMemo` : aucune
      // requete ne part a la frappe. Verifie le 2026-08-20.
      'src/features/rgpd/AuditLogsPage.tsx',
      'src/features/scraping/ScraperRunsPage.tsx',
    ];
    const connus = new Set([...REGISTRE.map((e) => e.fichier), ...FILTRES_EN_MEMOIRE]);
    // Le registre doit rester non vide ET couvrir au moins les huit sites.
    expect(REGISTRE.length).toBeGreaterThanOrEqual(8);
    expect(connus.size).toBe(REGISTRE.length + FILTRES_EN_MEMOIRE.length);
    for (const f of FILTRES_EN_MEMOIRE) {
      // Un ecran declare « filtre en memoire » qui se mettrait a requeter se
      // trahirait ici : son champ apparaitrait dans une `queryKey`.
      const fautives = clesDeRequete(lire(f)).filter((cle) => contientJeton(cle, 'search'));
      expect(fautives, f).toEqual([]);
    }
  });
});

// ── TEMOIN ────────────────────────────────────────────────────────────────

describe('registre de recherche — TEMOIN de la garde', () => {
  it('voit une saisie brute dans une clef, telle qu elle etait ecrite', () => {
    // Recopie fidele de `ContactsHubPage.tsx:78` avant reparation.
    const avant = "  const list = useQuery({ queryKey: ['crm', 'contacts-hub', tab, temperature, search, country], });";
    const cles = clesDeRequete(avant);
    expect(cles).toHaveLength(1);
    // C'EST le defaut G42-010 en miniature.
    expect(contientJeton(cles[0] ?? '', 'search')).toBe(true);
  });

  it('ne confond pas la variable differee avec la variable brute', () => {
    const apres = "queryKey: ['crm', 'contacts-hub', tab, temperature, rechercheDifferee, country],";
    const cles = clesDeRequete(apres);
    expect(contientJeton(cles[0] ?? '', 'search')).toBe(false);
    // Et le prefixe ne trompe pas non plus dans l'autre sens.
    expect(contientJeton("['companies', page, filtreInterroge]", 'filter')).toBe(false);
    expect(contientJeton("['companies', page, filter]", 'filter')).toBe(true);
  });

  it('ne prend pas un acces de propriete pour la variable elle-meme', () => {
    // `f.search` n'est PAS l'etat brut : c'est un champ d'un objet deja
    // differe. Sans cette precaution, la garde rougirait apres reparation.
    expect(contientJeton("['companies', page, { s: f.search }]", 'search')).toBe(false);
  });

  it('ne prend pas le NOM du cache pour la variable', () => {
    // `'global-search'` est le prefixe de cache de la palette Cmd+K. Sans le
    // nettoyage des chaines, `GlobalSearch.tsx` serait rouge une fois repare —
    // un faux rouge permanent, donc une garde qu'on finit par desactiver.
    expect(contientJeton("'global-search', rechercheDifferee", 'search')).toBe(false);
  });

  it('distingue le NOM de propriete de sa forme abregee', () => {
    // `CampaignsListPage` apres reparation : `search` est une clef d'objet.
    expect(contientJeton("'campaigns', { search: rechercheDifferee }", 'search')).toBe(false);
    // `CampaignsListPage` AVANT reparation, recopie fidele de la ligne 60 :
    // la forme abregee EST une lecture de la variable, et reste detectee.
    expect(contientJeton("'campaigns', { search }", 'search')).toBe(true);
  });

  it('ne prend pas un commentaire pour une clef', () => {
    const commente = [
      "// queryKey: ['contacts', search]  <- ancienne forme, cf. G42-010",
      "const q = useQuery({ queryKey: ['contacts', rechercheDifferee] });",
    ].join('\n');
    const cles = clesDeRequete(commente);
    expect(cles).toHaveLength(1);
    expect(contientJeton(cles[0] ?? '', 'search')).toBe(false);
  });

  it('ne passe pas au vert sur une source vide', () => {
    expect(clesDeRequete('')).toEqual([]);
    expect(contientJeton('', 'search')).toBe(false);
    // C'est le premier verrou (« trouve les huit fichiers ») qui refuse ce
    // vert-la : sans lui, un chemin faux serait indiscernable d'une reparation.
    expect(REGISTRE.every(({ fichier }) => lire(fichier).length >= 500)).toBe(true);
  });
});
