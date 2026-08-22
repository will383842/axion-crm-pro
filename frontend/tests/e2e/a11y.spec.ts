/**
 * PORTE D'ACCESSIBILITE — axe-core sur le produit REELLEMENT rendu.
 *
 * ── Ce que cette porte ne faisait pas (defaut D28-002) ────────────────────
 *  1. Elle n'assurait que sur `impact === 'critical'`. Or axe classe
 *     `color-contrast` en `serious`. Mesure du 2026-08-20 sur le build
 *     (`vite preview`, chromium 1223) : en mode sombre, axe remontait
 *     2 violations `color-contrast` sur /companies, 6 sur /coverage,
 *     2 sur /rgpd/requests — et la porte etait VERTE sur les trois.
 *  2. Elle visitait les ecrans SANS session et SANS simulation d'API. Aucun
 *     backend n'ecoute derriere `vite preview` : les ecrans se rabattaient sur
 *     leur etat d'erreur ou de liste vide. Ils n'etaient pas vides au sens du
 *     DOM (mesure : 150 a 1 445 caracteres de texte) — la porte mesurait donc
 *     bien quelque chose — mais elle ne mesurait PAS le produit avec des
 *     donnees, la ou vivent badges, pastilles et lignes de tableau.
 *  3. Elle ne visitait qu'un seul theme. Les defauts de contraste du mode
 *     sombre etaient hors de sa portee par construction.
 *
 * ── Ce que cette porte fait maintenant ────────────────────────────────────
 *  · session simulee au niveau reseau + charge utile choisie (vide OU peuplee)
 *  · chaque ecran visite en CLAIR puis en SOMBRE
 *  · assertion sur `critical` ET `serious`, moins un socle nomme, date et
 *    justifie (§ SOCLE)
 *  · trois verrous anti-« vert sans mesure » : titre de niveau 1 attendu,
 *    repere textuel de l'ecran, et nombre minimal de regles evaluees par axe.
 *
 * ⚠️ CE QU'ELLE NE COUVRE TOUJOURS PAS, explicitement :
 *  · les impacts `moderate` et `minor` (axe en remonte ; ils ne bloquent pas).
 *  · `/login` en mode sombre : cet ecran est monte HORS de la coquille
 *    applicative, `DarkModeToggle` n'y existe pas, `html.dark` n'y est jamais
 *    pose. Le parcours d'authentification n'a pas de mode sombre du tout —
 *    constat pinne par le test « CONSTAT » en bas de fichier.
 *  · les 33 autres ecrans de route du produit. Quatre ecrans sur 37.
 *  · le contraste fin est mesure separement, en ratio, par
 *    `tests/e2e/dark-contraste.spec.ts`.
 */
import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * SOCLE — dette d'accessibilite connue, toleree, NOMMEE et BORNEE.
 *
 * Une porte qui rougit tous les jours pour la meme raison finit desarmee. On
 * tolere donc, une par une, les violations deja presentes au 2026-08-20 — mais
 * chaque tolerance est bornee sur trois axes : la REGLE, l'ECRAN, et le NOMBRE
 * DE NOEUDS mesure. Une cinquieme ligne de tableau fautive, ou la meme regle
 * sur un autre ecran, fait rougir.
 *
 * ⚠️ Le socle ne se retracte pas tout seul : il n'exige pas que la violation
 * soit encore la. `target-size` depend de la largeur du glyphe rendu (« ☾ »,
 * « 👁 ») donc de la fonte du runner — l'exiger produirait un faux rouge sur un
 * runner Linux sans fonte emoji. A relire quand les composants cites bougent.
 *
 * ⚠️ AUCUNE de ces lignes n'est reparee par le lot qui a pose cette porte :
 * elles vivent toutes dans `src/components/**` ou `src/features/**`, hors de
 * son perimetre (`styles/index.css`, les specs, le workflow). Elles sont ecrites
 * ici pour EXISTER quelque part, pas pour etre acceptees.
 */
const SOCLE: ReadonlyArray<{ regle: string; url: string; noeudsMax: number; pourquoi: string }> = [
  {
    regle: 'target-size',
    url: '*',
    noeudsMax: 1,
    pourquoi:
      '2 fautifs mesures au 2026-08-20 : le bouton de theme de `DarkModeToggle.tsx:44` '
      + "(`px-2 py-1` -> 22,9 x 24 px) et le bouton « Afficher le mot de passe » de "
      + '`LoginPage.tsx` (`p-0.5` -> 20 x 20 px). Minimum WCAG 2.2 AA : 24 x 24 px. '
      + '⚠️ D28-015 ferme le PREMIER des deux le 2026-08-22 (`min-h-6 min-w-6` pose sur '
      + 'le bouton de theme) : il ne reste que `LoginPage.tsx`, hors du perimetre de ce '
      + "lot. Le plafond n'est pas abaisse ici — le socle ne se retracte pas tout seul "
      + '(cf. avertissement ci-dessus : `target-size` depend de la fonte du runner).',
  },
  // ── Le tableau virtualise des entreprises ────────────────────────────────
  // Ces quatre lignes n'etaient PAS visibles de l'ancienne porte : elle
  // visitait /companies sans donnees, donc sans une seule ligne de tableau.
  // Trois d'entre elles sont `critical` — la porte assurait pourtant deja sur
  // `critical` et restait verte. C'est la demonstration que le defaut de
  // couverture pesait plus lourd que le defaut de seuil.
  {
    regle: 'aria-required-parent',
    url: '/companies',
    noeudsMax: 2,
    pourquoi:
      '`CompanyRow`/`CompaniesListPage` posent `role="row"` et `role="rowgroup"` sans '
      + 'ancetre `role="grid"` ni `role="table"` (grille CSS + `@tanstack/react-virtual`).',
  },
  {
    regle: 'aria-required-children',
    url: '/companies',
    noeudsMax: 2,
    pourquoi: 'Meme cause : les `role="row"` ne contiennent aucun `role="cell"`/`gridcell`.',
  },
  {
    regle: 'aria-allowed-attr',
    url: '/companies',
    noeudsMax: 2,
    pourquoi:
      '`aria-rowcount` / `aria-rowindex` poses sur des elements dont le role ne les admet pas '
      + '(consequence directe de la grille sans `role="grid"`).',
  },
  {
    regle: 'nested-interactive',
    url: '/companies',
    noeudsMax: 1,
    pourquoi:
      'Le declencheur de menu (`aria-haspopup="menu"`) de la ligne d\'entreprise est imbrique '
      + 'dans un element deja interactif : un lecteur d\'ecran ne peut atteindre que l\'externe.',
  },
];

const IMPACTS_BLOQUANTS = new Set(['critical', 'serious']);

function estTolere(regle: string, url: string, noeuds: number): boolean {
  return SOCLE.some(
    (s) => s.regle === regle && (s.url === '*' || s.url === url) && noeuds <= s.noeudsMax,
  );
}

/**
 * Plancher de TEMOIN sur le travail d'axe. Une page blanche ne produit AUCUNE
 * violation — donc un vert. Le nombre de regles REUSSIES est, lui, la preuve
 * qu'axe a eu de la matiere a analyser. Mesure du 2026-08-20 : 26 a 33 regles
 * passees selon l'ecran. On exige 15.
 */
const PLANCHER_REGLES_EVALUEES = 15;

const IDENTITE_UTILISATEUR = {
  user: {
    id: 'u1',
    email: 'porte-a11y@axion-ia.test',
    name: 'Porte A11y',
    current_workspace_id: 'w1',
    onboarding_tour_completed_at: '2026-01-01T00:00:00Z',
  },
  roles: ['owner'],
};

const LISTE_VIDE = { data: [], meta: { total: 0, last_page: 1, current_page: 1, per_page: 50 } };

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

async function simulerApi(page: Page, charge: unknown): Promise<void> {
  await page.route('**/api/v1/auth/me', (route) => route.fulfill({ json: IDENTITE_UTILISATEUR }));
  await page.route('**/api/v1/**', (route) => route.fulfill({ json: charge }));
}

async function poserTheme(page: Page, theme: 'light' | 'dark'): Promise<void> {
  await page.addInitScript((t) => {
    window.localStorage.setItem('axion-theme', t);
  }, theme);
}

interface Ecran {
  readonly url: string;
  readonly titre: string;
  readonly repere: string;
  readonly charge: unknown;
  /** `false` quand l'ecran ne sait pas s'afficher en sombre (cf. /login). */
  readonly sombre: boolean;
}

const ECRANS: readonly Ecran[] = [
  // « Sign in » : le titre de /login est en ANGLAIS dans un produit francais.
  // Constat releve, non repare ici (composant hors perimetre).
  { url: '/login', titre: 'Sign in', repere: 'Sign in', charge: LISTE_VIDE, sombre: false },
  { url: '/companies', titre: 'Entreprises', repere: 'Inconnue', charge: UNE_ENTREPRISE, sombre: true },
  { url: '/coverage', titre: 'Couverture France', repere: 'Aucune', charge: LISTE_VIDE, sombre: true },
  // Sous-chaine SANS lettre accentuee : le titre reel est « Requetes RGPD ».
  { url: '/rgpd/requests', titre: 'RGPD', repere: 'RGPD', charge: LISTE_VIDE, sombre: true },
];

interface Bilan {
  readonly bloquantes: ReadonlyArray<{ regle: string; impact: string; noeuds: number; extraits: string[] }>;
  readonly tolerees: readonly string[];
  readonly reglesEvaluees: number;
}

async function auditer(page: Page, url: string): Promise<Bilan> {
  const resultat = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag22aa']).analyze();

  const bloquantes = resultat.violations
    .filter((v) => IMPACTS_BLOQUANTS.has(v.impact ?? ''))
    .filter((v) => !estTolere(v.id, url, v.nodes.length))
    .map((v) => ({
      regle: v.id,
      impact: v.impact ?? 'inconnu',
      noeuds: v.nodes.length,
      extraits: v.nodes.slice(0, 3).map((n) => `${n.target.join(' ')} :: ${n.html.slice(0, 140)}`),
    }));

  const tolerees = resultat.violations
    .filter((v) => estTolere(v.id, url, v.nodes.length))
    .map((v) => `${v.id}(${v.nodes.length})`);

  return { bloquantes, tolerees, reglesEvaluees: resultat.passes.length + resultat.violations.length };
}

function decrire(bilan: Bilan): string {
  return bilan.bloquantes
    .map((v) => `  [${v.impact}] ${v.regle} — ${v.noeuds} noeud(s)\n${v.extraits.map((e) => `      ${e}`).join('\n')}`)
    .join('\n');
}

for (const ecran of ECRANS) {
  const themes: ReadonlyArray<'light' | 'dark'> = ecran.sombre ? ['light', 'dark'] : ['light'];

  for (const theme of themes) {
    test(`${ecran.url} (${theme}) — aucune violation critical/serious hors socle`, async ({ page }) => {
      await simulerApi(page, ecran.charge);
      await poserTheme(page, theme);
      await page.goto(ecran.url);

      // Verrou 1 — l'ecran attendu est bien celui qui est rendu.
      await expect(page.getByRole('heading', { level: 1 })).toContainText(ecran.titre);
      // Verrou 2 — le contenu specifique de l'ecran est la (liste peuplee,
      // etat vide reel…), pas une coquille de chargement.
      await expect(page.getByText(ecran.repere).first()).toBeVisible();
      // Verrou 3 — le theme demande est bien applique.
      if (theme === 'dark') await expect(page.locator('html')).toHaveClass(/dark/);

      const bilan = await auditer(page, ecran.url);

      // Verrou 4 — axe a bien eu de la matiere. Zero regle evaluee produirait
      // zero violation, c'est-a-dire un vert sans mesure.
      expect(
        bilan.reglesEvaluees,
        `axe n'a evalue que ${bilan.reglesEvaluees} regle(s) sur ${ecran.url} : la page n'a rien a analyser`,
      ).toBeGreaterThanOrEqual(PLANCHER_REGLES_EVALUEES);

      expect(
        bilan.bloquantes,
        `${bilan.bloquantes.length} violation(s) critical/serious hors socle sur ${ecran.url} (${theme}) `
          + `— socle tolere ici : ${bilan.tolerees.join(', ') || 'aucun'}\n${decrire(bilan)}`,
      ).toEqual([]);
    });
  }
}

// ───────────────────────────────────────────────────────────────────────────
// TEMOINS de la porte elle-meme
// ───────────────────────────────────────────────────────────────────────────

test('TEMOIN — la porte rougit bien sur une violation `serious` injectee', async ({ page }) => {
  await simulerApi(page, LISTE_VIDE);
  await page.goto('/companies');
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

  // #808080 sur #8a8a8a = 1,1:1. axe classe `color-contrast` en `serious` :
  // c'est EXACTEMENT l'impact que l'ancienne porte laissait passer.
  await page.evaluate(() => {
    const racine = document.querySelector('#root');
    if (!racine) throw new Error('#root absent');
    const p = document.createElement('p');
    p.style.cssText = 'color:#808080;background:#8a8a8a;font-size:14px';
    p.textContent = 'TEMOIN CONTRASTE INSUFFISANT';
    racine.append(p);
  });

  const bilan = await auditer(page, '/companies');
  const contraste = bilan.bloquantes.find((v) => v.regle === 'color-contrast');
  expect(contraste, "la porte n'a pas vu la violation `serious` injectee : son filtre est faux").toBeDefined();
  expect(contraste?.impact).toBe('serious');
});

test('TEMOIN — une page vide ne produit AUCUNE violation (donc le vert ne prouve rien seul)', async ({ page }) => {
  await simulerApi(page, LISTE_VIDE);
  await page.goto('/companies');
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

  await page.evaluate(() => {
    const racine = document.querySelector('#root');
    if (racine) racine.innerHTML = '';
  });

  const bilan = await auditer(page, '/companies');
  // C'est la raison d'etre des quatre verrous des tests ci-dessus : sans eux,
  // CE cas-la serait vert.
  expect(bilan.bloquantes).toEqual([]);
  expect(bilan.reglesEvaluees).toBeLessThan(PLANCHER_REGLES_EVALUEES);
});

test("CONSTAT — /login n'applique jamais le mode sombre (donc la porte ne l'y mesure pas)", async ({ page }) => {
  await simulerApi(page, LISTE_VIDE);
  await poserTheme(page, 'dark');
  await page.goto('/login');
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

  // `DarkModeToggle` est monte dans `RootLayout` ; `/login` vit HORS de cette
  // coquille. Personne ne pose donc `html.dark` sur le parcours
  // d'authentification, quelle que soit la preference enregistree.
  // Si ce test rougit un jour, c'est une BONNE nouvelle : le mode sombre est
  // arrive sur /login — il faut alors passer `sombre: true` pour cet ecran.
  await expect(page.locator('html')).not.toHaveClass(/dark/);
});
