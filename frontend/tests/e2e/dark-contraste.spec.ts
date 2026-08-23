/**
 * GARDE — le mode sombre doit rester LISIBLE, et une declaration `dark:` d'un
 * composant doit l'emporter sur la feuille de style globale.
 *
 * ── Le defaut mesure (D27-002) ────────────────────────────────────────────
 * `src/styles/index.css` portait quatre regles de repli en `!important` :
 *
 *     .dark .bg-white       { background:   #13161a !important }
 *     .dark .bg-slate-50    { background:   #0c1014 !important }
 *     .dark .text-slate-900 { color:        #eee    !important }
 *     .dark .border-slate-200 { border-color: #2a2e33 !important }
 *
 * Deux consequences, toutes deux MESUREES sur le build (`vite preview`,
 * chromium 1223, 2026-08-20) :
 *
 *  1. Specificite (0,2,0) + `!important` contre (0,1,0) pour l'utilitaire
 *     Tailwind `.dark\:bg-slate-900:where(.dark,.dark *)` : la feuille globale
 *     ECRASAIT les 598 declarations `dark:` ecrites dans les 63 composants.
 *     Une `Card` par defaut declare `bg-white … dark:bg-slate-900` et se
 *     retrouvait quand meme peinte en #13161a.
 *  2. Le repli noircit le FOND sans toucher au TEXTE. Un texte ecrit pour du
 *     blanc (`text-slate-600`, sans variante `dark:`) se retrouvait sur du
 *     #13161a : la description d'`EmptyState` tombait a 2,39:1, tres en
 *     dessous du seuil WCAG 2.2 AA de 4,5:1.
 *
 * ── Ce que cette garde mesure ─────────────────────────────────────────────
 * Le RAPPORT DE CONTRASTE, pas la presence d'une classe. On lit la couleur
 * CALCULEE (donc le resultat reel de la cascade, `!important` compris) et on
 * la normalise en sRGB via un canvas — seul moyen fiable de ramener
 * `oklch()`, `color-mix()` et `#rgba` a des octets sans reimplementer les
 * espaces de couleur dans le test.
 *
 * ⚠️ Ce que la garde NE couvre PAS, et pourquoi :
 *  - le texte pose sur un `background-image` (degrade) : la couleur de fond
 *    n'est pas lisible par `getComputedStyle`. Ces elements sont COMPTES et
 *    rapportes comme « non mesurables », jamais silencieusement ignores.
 *  - les fonds semi-transparents : pas de composition, meme traitement.
 *  - `/login` : cet ecran est HORS de la coquille applicative, le
 *    `DarkModeToggle` n'y est pas monte, `html.dark` n'y est jamais pose.
 *    Le mode sombre n'existe donc pas sur le parcours d'authentification —
 *    constat consigne, hors perimetre de reparation de ce lot.
 */
import { test, expect, type Page } from '@playwright/test';

/** Seuil WCAG 2.2 AA — texte courant. Le grand texte tombe a 3,0 (cf. `seuilDe`). */
const AA_TEXTE_COURANT = 4.5;

/**
 * Plancher de TEMOIN. Une page blanche ne produit AUCUNE mesure : sans ce
 * plancher, la garde serait VERTE precisement quand elle ne mesure rien.
 * Mesure du 2026-08-20 (build `vite preview`, chromium 1223) : 29 elements
 * textuels mesurables sur `/companies`, 37 sur `/coverage`, 32 sur
 * `/rgpd/requests`. On exige 20 : sous le minimum observe, tres au-dessus de
 * zero.
 */
const PLANCHER_MESURES = 20;

interface Mesure {
  readonly ratio: number;
  readonly seuil: number;
  readonly texte: string;
  readonly classes: string;
  readonly premierPlan: string;
  readonly fond: string;
}

interface Balayage {
  readonly mesures: readonly Mesure[];
  readonly nonMesurables: number;
}

const IDENTITE_UTILISATEUR = {
  user: {
    id: 'u1',
    email: 'garde-contraste@axion-ia.test',
    name: 'Garde Contraste',
    current_workspace_id: 'w1',
    onboarding_tour_completed_at: '2026-01-01T00:00:00Z',
  },
  roles: ['owner'],
};

/**
 * Une liste d'entreprises PEUPLEE. Les etats vides ne montrent ni
 * `SizeCategoryBadge`, ni `StatusPill`, ni `CompanyRow` : trois familles de
 * composants ou le repli sombre peut se tromper de couple fond/texte. Un
 * ecran avec donnees les fait apparaitre reellement.
 */
const UNE_ENTREPRISE = {
  data: [
    {
      id: 1,
      siren: '123456789',
      denomination: 'Acme Inc',
      naf: '6201Z',
      size_category: 'inconnue',
      quality_score: 42,
      city: 'Paris',
    },
  ],
  meta: { total: 1, last_page: 1, current_page: 1, per_page: 50 },
};

const LISTE_VIDE = { data: [], meta: { total: 0, last_page: 1, current_page: 1, per_page: 50 } };

/**
 * Les ecrans sont servis par `vite preview` : AUCUN backend n'ecoute. Sans
 * simulation, chaque requete echoue et l'ecran affiche un etat d'erreur —
 * c'est-a-dire un ecran different de celui qu'on pretend mesurer. On simule
 * donc l'API au niveau reseau : session valide + charge utile choisie par
 * l'appelant.
 */
async function simulerApi(page: Page, charge: unknown = LISTE_VIDE): Promise<void> {
  await page.route('**/api/v1/auth/me', (route) => route.fulfill({ json: IDENTITE_UTILISATEUR }));
  await page.route('**/api/v1/**', (route) => route.fulfill({ json: charge }));
}

async function forcerSombre(page: Page): Promise<void> {
  // `DarkModeToggle` lit `axion-theme` au premier rendu ; on le pose AVANT que
  // le bundle ne demarre, sinon le premier rendu se fait en clair et la mesure
  // porterait sur un instantane intermediaire.
  await page.addInitScript(() => {
    window.localStorage.setItem('axion-theme', 'dark');
  });
}

/** Le code injecte dans la page. Extrait pour etre partage par la garde ET son temoin. */
const CODE_BALAYAGE = (): Balayage => {
  const versSrgb = (css: string): [number, number, number, number] => {
    const toile = document.createElement('canvas');
    toile.width = 1;
    toile.height = 1;
    const ctx = toile.getContext('2d');
    if (!ctx) return [0, 0, 0, 0];
    ctx.clearRect(0, 0, 1, 1);
    ctx.fillStyle = css;
    ctx.fillRect(0, 0, 1, 1);
    const d = ctx.getImageData(0, 0, 1, 1).data;
    return [d[0] ?? 0, d[1] ?? 0, d[2] ?? 0, (d[3] ?? 0) / 255];
  };

  const luminance = (r: number, g: number, b: number): number => {
    const canal = (v: number): number => {
      const s = v / 255;
      return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
    };
    return 0.2126 * canal(r) + 0.7152 * canal(g) + 0.0722 * canal(b);
  };

  const mesures: Mesure[] = [];
  let nonMesurables = 0;

  const racine = document.querySelector('#root');
  if (!racine) return { mesures, nonMesurables };

  for (const el of Array.from(racine.querySelectorAll('*'))) {
    // Seuls les elements qui portent EUX-MEMES du texte : sinon chaque
    // conteneur serait compte autant de fois qu'il a de descendants.
    const porteDuTexte = Array.from(el.childNodes).some(
      (n) => n.nodeType === Node.TEXT_NODE && (n.textContent ?? '').trim().length > 0,
    );
    if (!porteDuTexte) continue;

    const style = window.getComputedStyle(el);
    if (style.visibility !== 'visible' || style.display === 'none') continue;

    const rect = el.getBoundingClientRect();
    if (rect.width === 0 || rect.height === 0) continue;
    // `.skip-link` est parquee a `left: -9999px` : visible pour le calcul,
    // invisible pour l'oeil. La mesurer serait un faux rouge.
    if (rect.right < 0 || rect.bottom < 0) continue;

    const [pr, pg, pb, pa] = versSrgb(style.color);
    // Alpha nul = texte transparent, typiquement `bg-clip-text` sur un degrade.
    // Non mesurable par cette methode : compte, pas ignore.
    if (pa < 0.5) {
      nonMesurables += 1;
      continue;
    }

    let fond: [number, number, number] | null = null;
    let courant: Element | null = el;
    while (courant) {
      const s = window.getComputedStyle(courant);
      if (s.backgroundImage !== 'none') break; // degrade / image : non mesurable
      const [r, g, b, a] = versSrgb(s.backgroundColor);
      if (a > 0.99) {
        fond = [r, g, b];
        break;
      }
      if (a > 0.01) break; // fond semi-transparent : pas de composition ici
      courant = courant.parentElement;
    }
    if (!fond) {
      nonMesurables += 1;
      continue;
    }

    const lPremierPlan = luminance(pr, pg, pb);
    const lFond = luminance(fond[0], fond[1], fond[2]);
    const haut = Math.max(lPremierPlan, lFond);
    const bas = Math.min(lPremierPlan, lFond);
    const ratio = Math.round(((haut + 0.05) / (bas + 0.05)) * 100) / 100;

    const px = parseFloat(style.fontSize);
    const graisse = parseInt(style.fontWeight, 10) || 400;
    // WCAG 1.4.3 : « grand texte » = 18,66 px gras, ou 24 px.
    const grand = px >= 24 || (px >= 18.66 && graisse >= 700);

    mesures.push({
      ratio,
      seuil: grand ? 3 : 4.5,
      texte: (el.textContent ?? '').trim().slice(0, 60),
      classes: typeof el.className === 'string' ? el.className : '',
      premierPlan: `rgb(${pr},${pg},${pb})`,
      fond: `rgb(${fond[0]},${fond[1]},${fond[2]})`,
    });
  }

  return { mesures, nonMesurables };
};

async function balayer(page: Page): Promise<Balayage> {
  return page.evaluate(CODE_BALAYAGE);
}

function decrire(mesures: readonly Mesure[]): string {
  return mesures
    .map((m) => `  ${m.ratio}:1 (seuil ${m.seuil}) « ${m.texte} » ${m.premierPlan} sur ${m.fond}\n    class="${m.classes}"`)
    .join('\n');
}

// ───────────────────────────────────────────────────────────────────────────
// 1. TEMOIN — la sonde sait rougir, et ne se declare pas verte sur du vide
// ───────────────────────────────────────────────────────────────────────────

test('TEMOIN — la sonde detecte un contraste insuffisant et laisse passer un bon', async ({ page }) => {
  await simulerApi(page);
  await forcerSombre(page);
  await page.goto('/companies');
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

  // Deux temoins poses A LA MAIN, en style INLINE : ils ne dependent d'aucune
  // classe Tailwind, donc ils mesurent la sonde et rien d'autre.
  //   #62748e sur #13161a = 3,8:1  → doit etre VU comme insuffisant
  //   #f8fafc sur #13161a = 16,4:1 → doit etre VU comme suffisant
  await page.evaluate(() => {
    const racine = document.querySelector('#root');
    if (!racine) throw new Error('#root absent');
    const mauvais = document.createElement('p');
    mauvais.style.cssText = 'color:#62748e;background:#13161a;font-size:14px';
    mauvais.textContent = 'TEMOIN INSUFFISANT';
    const bon = document.createElement('p');
    bon.style.cssText = 'color:#f8fafc;background:#13161a;font-size:14px';
    bon.textContent = 'TEMOIN SUFFISANT';
    racine.append(mauvais, bon);
  });

  const { mesures } = await balayer(page);
  const mauvais = mesures.find((m) => m.texte === 'TEMOIN INSUFFISANT');
  const bon = mesures.find((m) => m.texte === 'TEMOIN SUFFISANT');

  expect(mauvais, 'le temoin insuffisant doit avoir ete mesure').toBeDefined();
  expect(bon, 'le temoin suffisant doit avoir ete mesure').toBeDefined();
  expect(mauvais?.ratio).toBeLessThan(AA_TEXTE_COURANT);
  expect(bon?.ratio).toBeGreaterThanOrEqual(AA_TEXTE_COURANT);
});

test('TEMOIN — sur une page sans contenu, la sonde ne rend AUCUNE mesure', async ({ page }) => {
  await page.goto('/companies');
  await page.evaluate(() => {
    const racine = document.querySelector('#root');
    if (racine) racine.innerHTML = '';
  });
  const { mesures } = await balayer(page);
  // C'est tout l'interet du plancher : zero mesure ne doit JAMAIS se lire
  // comme « aucun defaut ».
  expect(mesures).toHaveLength(0);
  expect(mesures.length).toBeLessThan(PLANCHER_MESURES);
});

// ───────────────────────────────────────────────────────────────────────────
// 2. La feuille globale ne doit plus ecraser les composants
// ───────────────────────────────────────────────────────────────────────────

test('une declaration `dark:` d\'un composant l\'emporte sur la feuille globale', async ({ page }) => {
  await simulerApi(page);
  await forcerSombre(page);
  await page.goto('/companies');
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

  const verdict = await page.evaluate(() => {
    const versSrgb = (css: string): string => {
      const toile = document.createElement('canvas');
      toile.width = 1;
      toile.height = 1;
      const ctx = toile.getContext('2d');
      if (!ctx) return 'nul';
      ctx.clearRect(0, 0, 1, 1);
      ctx.fillStyle = css;
      ctx.fillRect(0, 0, 1, 1);
      const d = ctx.getImageData(0, 0, 1, 1).data;
      return `rgb(${d[0] ?? 0},${d[1] ?? 0},${d[2] ?? 0})`;
    };

    // On ne code AUCUN selecteur en dur : on cherche dans la page reelle un
    // element qui declare a la fois `bg-white` (donc vise par le repli global)
    // et sa propre variante `dark:bg-<jeton>`. S'il n'y en a aucun, la garde
    // doit le DIRE, pas se declarer verte.
    const candidats = Array.from(document.querySelectorAll('[class]')).filter((el) => {
      const classes = typeof el.className === 'string' ? el.className.split(/\s+/) : [];
      return (
        classes.includes('bg-white') &&
        classes.some((c) => /^dark:bg-[a-z]+-\d{2,3}$/.test(c))
      );
    });
    if (candidats.length === 0) return { trouve: false as const };

    const el = candidats[0];
    if (!el) return { trouve: false as const };
    const classes = (typeof el.className === 'string' ? el.className : '').split(/\s+/);
    const jetonSombre = classes.find((c) => /^dark:bg-[a-z]+-\d{2,3}$/.test(c)) ?? '';
    const nomCouleur = jetonSombre.replace('dark:bg-', '');

    return {
      trouve: true as const,
      jetonSombre,
      calcule: versSrgb(window.getComputedStyle(el).backgroundColor),
      attendu: versSrgb(
        window.getComputedStyle(document.documentElement).getPropertyValue(`--color-${nomCouleur}`).trim(),
      ),
      nbCandidats: candidats.length,
      html: el.outerHTML.slice(0, 160),
    };
  });

  expect(verdict.trouve, 'aucun element `bg-white` + `dark:bg-*` dans la page : le temoin est introuvable, la garde ne prouve rien').toBe(true);
  if (!verdict.trouve) return;

  // Avant reparation : calcule = rgb(19,22,26) (#13161a, le repli global
  // `!important`) alors que `dark:bg-slate-900` vaut rgb(15,23,43).
  expect(
    verdict.calcule,
    `le composant declare ${verdict.jetonSombre} mais la feuille globale impose ${verdict.calcule}\n${verdict.html}`,
  ).toBe(verdict.attendu);
});

// ───────────────────────────────────────────────────────────────────────────
// 3. Le contraste, ecran par ecran, en mode sombre
// ───────────────────────────────────────────────────────────────────────────

const ECRANS_SOMBRES = [
  { cas: '/companies (liste vide)', url: '/companies', titre: 'Entreprises', charge: LISTE_VIDE, repere: 'Aucune entreprise' },
  // « Inconnue » est le libelle de `SizeCategoryBadge` pour `size_category:
  // 'inconnue'` — le SEUL badge du produit qui associe `bg-slate-100` a
  // `text-slate-600` sans variante sombre. Son affichage prouve que la liste
  // est reellement peuplee, et pas retombee sur l'etat vide.
  { cas: '/companies (liste peuplee)', url: '/companies', titre: 'Entreprises', charge: UNE_ENTREPRISE, repere: 'Inconnue' },
  { cas: '/coverage', url: '/coverage', titre: 'Couverture France', charge: LISTE_VIDE, repere: 'Aucune' },
  // Titre sans lettre accentuee volontairement : « Requetes RGPD » porte un
  // accent, on n'assure donc que sur la sous-chaine « RGPD ».
  { cas: '/rgpd/requests', url: '/rgpd/requests', titre: 'RGPD', charge: LISTE_VIDE, repere: 'RGPD' },

  // ── Ajoutes le 2026-08-23 ────────────────────────────────────────────────
  //
  // La liste ci-dessus couvrait 4 cas sur 3 routes. La coquille applicative
  // etant commune, une regression sur la barre laterale ou l'en-tete y serait
  // vue ; le CONTENU de chaque ecran, lui, ne l'etait pas : tableaux, badges
  // de statut, filtres, et surtout les etats vides, qui sont ce qu'un nouvel
  // utilisateur voit en premier.
  //
  // Titres et reperes RELEVES A L'ECRAN le 2026-08-23, pas devines : voir
  // `_AUDIT/…/agent-35/greement-navigateur/mesures-passe1-ecrans-liste.json`.
  // Tous choisis SANS ACCENT, comme le reste de ce fichier.
  // ⚠️ `/` (tableau de bord) est ABSENT DE CETTE LISTE, et ce n'est pas un
  // oubli. `simulerApi()` rend la meme charge a toutes les routes ; sur
  // `/dashboard/stats`, cette forme ne correspond a rien d'attendu, et
  // l'ecran ne rend alors AUCUN `h1` — pas meme son titre. L'y inscrire
  // demanderait une charge taillee a la main pour ce seul ecran, donc
  // devinee : une garde batie sur une forme inventee mesure la forme
  // inventee, pas le produit. Mesure le 2026-08-23.
  //
  // A REGARDER PAR AILLEURS : que `DashboardPage` ne rende meme pas son
  // titre quand la charge est inattendue est une fragilite en soi. Elle
  // n'est PAS rapportee comme un defaut du produit — l'API rend la bonne
  // forme en vrai — mais elle merite un coup d'oeil.
  { cas: '/scraper-runs', url: '/scraper-runs', titre: 'Journaux de collecte', charge: LISTE_VIDE, repere: 'Aucun run' },
  { cas: '/journalists', url: '/journalists', titre: 'Journalistes', charge: LISTE_VIDE, repere: 'Aucun journaliste' },
  { cas: '/tags', url: '/tags', titre: 'Tags', charge: LISTE_VIDE, repere: 'Aucun tag' },
] as const;

for (const { cas, url, titre, charge, repere } of ECRANS_SOMBRES) {
  test(`${cas} — tout texte atteint le seuil WCAG AA en mode sombre`, async ({ page }) => {
    await simulerApi(page, charge);
    await forcerSombre(page);
    await page.goto(url);

    // L'ecran doit etre REELLEMENT rendu, et en sombre. Quatre verrous, chacun
    // ferme une facon d'etre vert sans rien mesurer.
    await expect(page.getByRole('heading', { level: 1 })).toContainText(titre);
    await expect(page.locator('html')).toHaveClass(/dark/);
    await expect(page.getByText(repere).first()).toBeVisible();

    const { mesures, nonMesurables } = await balayer(page);
    expect(
      mesures.length,
      `seulement ${mesures.length} element(s) textuel(s) mesure(s) sur ${url} (plancher ${PLANCHER_MESURES}) : l'ecran est vide ou n'a pas fini de rendre`,
    ).toBeGreaterThanOrEqual(PLANCHER_MESURES);

    const fautifs = mesures.filter((m) => m.ratio < m.seuil);
    expect(
      fautifs,
      `${fautifs.length} texte(s) sous le seuil WCAG AA sur ${url} en mode sombre `
        + `(${mesures.length} mesures, ${nonMesurables} non mesurables) :\n${decrire(fautifs)}`,
    ).toEqual([]);
  });
}

// ───────────────────────────────────────────────────────────────────────────
// 4. Le cas nomme par l'audit : la description d'`EmptyState`
// ───────────────────────────────────────────────────────────────────────────

test("la description d'EmptyState est lisible en mode sombre (mesuree a 2,39:1 le 2026-08-20)", async ({ page }) => {
  await simulerApi(page);
  await forcerSombre(page);
  await page.goto('/companies');

  // `EmptyState` est utilise 27 fois dans le produit ; `/companies` avec une
  // liste vide est le chemin le plus court pour l'atteindre reellement.
  const description = page.getByText('Lance un scraping depuis la carte de couverture');
  await expect(description).toBeVisible();

  const { mesures } = await balayer(page);
  const mesure = mesures.find((m) => m.texte.startsWith('Lance un scraping depuis la carte'));
  expect(mesure, "la description d'EmptyState n'a pas ete mesuree : la garde ne prouve rien").toBeDefined();
  expect(
    mesure?.ratio,
    `description d'EmptyState : ${mesure?.ratio}:1 (${mesure?.premierPlan} sur ${mesure?.fond}), seuil ${AA_TEXTE_COURANT}:1`,
  ).toBeGreaterThanOrEqual(AA_TEXTE_COURANT);
});
