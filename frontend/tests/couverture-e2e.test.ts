/**
 * GARDE DE COMPLETUDE — toute suite e2e presente doit etre JOUEE, ou EXCLUE
 * avec un motif ecrit.
 *
 * ── Le defaut (P6-UI-008, S1) ─────────────────────────────────────────────
 * Mesure du 2026-08-20 sur ce depot :
 *   · 17 fichiers `frontend/tests/e2e/*.spec.ts` presents
 *   · 3 seulement joues par un workflow (`a11y.yml` : a11y, dark-contraste,
 *     navigation)
 *   · 14 joues par PERSONNE.
 * Des tests qui existent et ne tournent jamais sont pires qu'absents : ils
 * rassurent sans rien garder. La preuve : joues a la main sur le build
 * (`vite preview`, chromium 1234), 4 des 14 etaient ROUGES —
 *   auth.spec.ts        3 echecs sur 4
 *   companies.spec.ts   2 echecs sur 3
 *   coverage.spec.ts    2 echecs sur 2
 *   onboarding.spec.ts  1 echec sur 4
 * et personne ne pouvait le savoir.
 *
 * ── Ce que cette garde tient ──────────────────────────────────────────────
 * Elle ne mesure pas la qualite des suites : elle mesure qu'AUCUNE ne peut
 * exister en silence. Chaque `*.spec.ts` de `tests/e2e/` doit apparaitre dans
 * une commande `playwright test` d'un workflow de `.github/workflows/`, ou
 * bien etre inscrit dans `tests/e2e/couverture-e2e.json` avec un motif.
 * Ajouter un fichier de suite sans faire l'un des deux fait rougir ce test —
 * dans `pnpm test`, qui est BLOQUANT (job `frontend` de `ci.yml`).
 *
 * ⚠️ CE QU'ELLE NE COUVRE PAS, explicitement :
 *  · elle ne sait pas si un `--grep` restreint la commande a une partie des
 *    cas d'une suite. Une suite citee est comptee jouee EN ENTIER.
 *  · elle ne sait pas si le workflow qui la joue est bloquant ou porte
 *    `continue-on-error`. C'est un autre defaut, deja documente pour les
 *    portes de budget ; il se controle ailleurs.
 *  · elle ne juge pas la VERACITE d'un motif d'exclusion. Elle exige qu'il
 *    soit ecrit, date, et qu'il porte une reparation attendue.
 */
import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ICI = path.dirname(fileURLToPath(import.meta.url));
const RACINE_FRONT = path.resolve(ICI, '..');
const RACINE_DEPOT = path.resolve(RACINE_FRONT, '..');
const DOSSIER_E2E = path.join(RACINE_FRONT, 'tests', 'e2e');
const DOSSIER_WORKFLOWS = path.join(RACINE_DEPOT, '.github', 'workflows');
const FICHIER_CONFIG = path.join(DOSSIER_E2E, 'couverture-e2e.json');

// ── Fonctions pures, pour que le TEMOIN puisse les eprouver ───────────────

/**
 * Les commentaires YAML de ce depot sont denses et citent souvent des
 * commandes. Sans ce nettoyage, un commentaire qui contient « playwright test »
 * ferait croire a la garde que tout est joue — exactement le faux vert qu'elle
 * existe pour empecher.
 */
export function sansCommentairesYaml(texte: string): string {
  return texte
    .split('\n')
    .filter((ligne) => !/^\s*#/.test(ligne))
    .join('\n');
}

/**
 * Renvoie les ARGUMENTS de chaque invocation `playwright test` trouvee.
 *
 * ⚠️ Une commande de workflow n'est PAS toujours sur une seule ligne : un
 * `run: >-` replie la commande sur plusieurs lignes, et c'est la forme lisible
 * quand on cite onze chemins de suite. Un `[^\n]*` ne verrait alors AUCUN
 * chemin — et comme « aucun chemin » veut dire « toute la testDir est jouee »,
 * la garde serait passee au VERT sur un depot ou rien ne tourne. On recolle
 * donc les lignes de continuation : celles qui suivent, non vides, qui ne sont
 * ni un commentaire ni une nouvelle cle YAML (`nom:` ou `- nom:`).
 */
export function argumentsPlaywrightTest(texteYaml: string): string[] {
  const lignes = texteYaml.split('\n');
  const trouves: string[] = [];
  for (let i = 0; i < lignes.length; i += 1) {
    const m = /playwright\s+test\b(.*)$/.exec(lignes[i] ?? '');
    if (m === null) continue;
    let args = m[1] ?? '';
    for (let j = i + 1; j < lignes.length; j += 1) {
      const suite = lignes[j] ?? '';
      if (suite.trim() === '') break;
      if (/^\s*#/.test(suite)) break;
      if (/^\s*-?\s*[\w.-]+:/.test(suite)) break;
      args += ` ${suite.trim()}`;
    }
    trouves.push(args);
  }
  return trouves;
}

/**
 * Des arguments d'une commande, deduit les suites jouees.
 * Une commande `playwright test` SANS aucun chemin `*.spec.ts` lance toute la
 * `testDir` : elle joue donc l'ensemble des suites connues.
 */
export function suitesJouees(argumentsParCommande: string[], suitesConnues: string[]): Set<string> {
  const jouees = new Set<string>();
  for (const args of argumentsParCommande) {
    const chemins = args.match(/[\w./-]+\.spec\.ts/g) ?? [];
    if (chemins.length === 0) {
      for (const suite of suitesConnues) jouees.add(suite);
      continue;
    }
    for (const chemin of chemins) {
      const base = chemin.split('/').pop();
      if (base !== undefined && base !== '') jouees.add(base);
    }
  }
  return jouees;
}

/** Liste recursivement les `*.spec.ts` (nom de base) d'un dossier. */
export function listerSuites(dossier: string): string[] {
  const sorties: string[] = [];
  for (const entree of readdirSync(dossier)) {
    const complet = path.join(dossier, entree);
    if (statSync(complet).isDirectory()) {
      sorties.push(...listerSuites(complet));
    } else if (entree.endsWith('.spec.ts')) {
      sorties.push(entree);
    }
  }
  return sorties.sort();
}

// ── Lecture du depot ──────────────────────────────────────────────────────

const suites = listerSuites(DOSSIER_E2E);

const fichiersWorkflow = readdirSync(DOSSIER_WORKFLOWS).filter(
  (f) => f.endsWith('.yml') || f.endsWith('.yaml'),
);

const argumentsTrouves: string[] = [];
for (const fichier of fichiersWorkflow) {
  const brut = readFileSync(path.join(DOSSIER_WORKFLOWS, fichier), 'utf8');
  argumentsTrouves.push(...argumentsPlaywrightTest(sansCommentairesYaml(brut)));
}

const jouees = suitesJouees(argumentsTrouves, suites);

type Exclusion = { depuis: string; motif: string; reparation_attendue: string };
const config = JSON.parse(readFileSync(FICHIER_CONFIG, 'utf8')) as {
  exclues: Record<string, Exclusion>;
};
const exclues = config.exclues;

// ── Verrous anti-« vert sans mesure » ─────────────────────────────────────
//
// Une garde qui lit des fichiers passe au vert quand elle n'en trouve AUCUN :
// zero suite non couverte, donc zero echec. Ces quatre controles refusent ce
// vert-la. Les seuils sont volontairement bas — ils detectent l'effondrement
// (chemin faux, dossier deplace, checkout partiel), pas la variation normale.

describe('couverture e2e — la garde a bien quelque chose sous les yeux', () => {
  it('trouve les fichiers de suite e2e', () => {
    expect(suites.length).toBeGreaterThanOrEqual(10);
    // Repere nomme : si celui-ci disparait, le chemin est faux, pas le depot.
    expect(suites).toContain('a11y.spec.ts');
  });

  it('trouve les workflows du depot', () => {
    expect(fichiersWorkflow.length).toBeGreaterThanOrEqual(10);
    expect(fichiersWorkflow).toContain('ci.yml');
  });

  it('trouve de vraies invocations de playwright dans les workflows', () => {
    // Plancher, pas comptage : au 2026-08-20 il y a 4 invocations (3 dans
    // `a11y.yml`, 1 dans `e2e.yml`). Le seuil detecte l'effondrement — un
    // chemin faux, un dossier deplace, un checkout partiel — pas la variation.
    expect(argumentsTrouves.length).toBeGreaterThanOrEqual(3);
    expect(jouees.size).toBeGreaterThanOrEqual(3);
  });

  it('trouve le registre des exclusions', () => {
    expect(Object.keys(exclues).length).toBeGreaterThanOrEqual(1);
  });
});

// ── La regle ──────────────────────────────────────────────────────────────

describe('couverture e2e — completude', () => {
  it('toute suite e2e est jouee par un workflow, ou exclue avec un motif ecrit', () => {
    const orphelines = suites.filter(
      (suite) => !jouees.has(suite) && exclues[suite] === undefined,
    );
    // Diff lisible : vitest affiche le tableau attendu contre le tableau recu.
    expect(orphelines).toEqual([]);
  });

  it('aucune exclusion ne porte sur une suite qui n existe plus', () => {
    // Un motif orphelin est un mensonge qui dort : il decrit une suite absente
    // et couvrirait par accident un futur fichier du meme nom.
    const fantomes = Object.keys(exclues).filter((nom) => !suites.includes(nom));
    expect(fantomes).toEqual([]);
  });

  it('aucune suite n est a la fois jouee et exclue', () => {
    // Contradiction : si un workflow la joue, l'exclusion doit disparaitre,
    // sinon le registre raconte l'inverse de ce que fait la CI.
    const contradictoires = Object.keys(exclues).filter((nom) => jouees.has(nom));
    expect(contradictoires).toEqual([]);
  });

  it('chaque motif d exclusion est date, argumente et nomme sa reparation', () => {
    const defaillants: string[] = [];
    for (const [nom, entree] of Object.entries(exclues)) {
      if (!/^\d{4}-\d{2}-\d{2}$/.test(entree.depuis ?? '')) {
        defaillants.push(`${nom}: champ "depuis" absent ou hors format AAAA-MM-JJ`);
      }
      // 200 caracteres : un motif court est toujours une excuse. Les trois
      // motifs ecrits le 2026-08-20 font entre 700 et 1 100 caracteres.
      if ((entree.motif ?? '').length < 200) {
        defaillants.push(`${nom}: motif trop court (${(entree.motif ?? '').length} caracteres)`);
      }
      if ((entree.reparation_attendue ?? '').length < 80) {
        defaillants.push(`${nom}: reparation_attendue absente ou trop courte`);
      }
    }
    expect(defaillants).toEqual([]);
  });
});

// ── TEMOIN ────────────────────────────────────────────────────────────────
//
// Preuve que la garde aurait trouve le defaut de 2026-08-20, et qu'elle ne
// passe pas au vert sur une entree vide. On eprouve les fonctions pures sur des
// entrees fabriquees : c'est le seul moyen de montrer le ROUGE sans casser le
// depot.

describe('couverture e2e — TEMOIN de la garde', () => {
  it('sait voir qu une suite n est jouee par aucun workflow', () => {
    const workflow = "        run: pnpm exec playwright test tests/e2e/a11y.spec.ts --project=chromium\n";
    const connues = ['a11y.spec.ts', 'dashboard.spec.ts'];
    const vues = suitesJouees(argumentsPlaywrightTest(sansCommentairesYaml(workflow)), connues);
    expect(vues.has('a11y.spec.ts')).toBe(true);
    // C'EST le defaut P6-UI-008 en miniature : la suite existe, rien ne la joue.
    expect(vues.has('dashboard.spec.ts')).toBe(false);
    expect(connues.filter((s) => !vues.has(s))).toEqual(['dashboard.spec.ts']);
  });

  it('ne passe pas au vert sur zero workflow', () => {
    const connues = ['a11y.spec.ts', 'dashboard.spec.ts'];
    const vues = suitesJouees(argumentsPlaywrightTest(''), connues);
    expect(vues.size).toBe(0);
    // Sans le verrou anti-vide, « aucune suite jouee » ne produirait aucun
    // echec cote regle si la liste des suites etait vide elle aussi.
    expect(connues.filter((s) => !vues.has(s))).toEqual(connues);
  });

  it('ne passe pas au vert sur zero suite trouvee', () => {
    expect(listerSuites(DOSSIER_E2E).length).toBeGreaterThan(0);
    // Le verrou de la section precedente exige >= 10 ; on montre ici que la
    // fonction elle-meme ne fabrique pas de suites a partir de rien.
    const vues = suitesJouees(['tests/e2e/a11y.spec.ts'], []);
    expect([...vues]).toEqual(['a11y.spec.ts']);
  });

  it('ne prend pas un commentaire YAML pour une commande', () => {
    const workflow = [
      '      # ancienne commande : pnpm exec playwright test (les 3 projets)',
      '      - name: rien',
      '        run: echo bonjour',
    ].join('\n');
    const args = argumentsPlaywrightTest(sansCommentairesYaml(workflow));
    expect(args).toEqual([]);
    // Sans le nettoyage, la ligne de commentaire n aurait cite aucun chemin :
    // la garde aurait conclu « toute la testDir est jouee » et serait passee
    // au vert sur un depot ou rien ne tourne.
    const sansNettoyage = suitesJouees(argumentsPlaywrightTest(workflow), ['a.spec.ts']);
    expect(sansNettoyage.has('a.spec.ts')).toBe(true);
  });

  it('recolle une commande repliee sur plusieurs lignes (run: >-)', () => {
    // C'est la forme employee par `.github/workflows/e2e.yml` : onze chemins
    // ne tiennent pas lisiblement sur une ligne. Si la garde ne recollait pas
    // les continuations, elle ne verrait AUCUN chemin, conclurait « toute la
    // testDir est jouee » et deviendrait un faux vert parfait.
    const workflow = [
      '        run: >-',
      '          pnpm exec playwright test',
      '          tests/e2e/dashboard.spec.ts',
      '          tests/e2e/rgpd.spec.ts',
      '          --project=chromium --reporter=line',
      '      - uses: actions/upload-artifact@v4',
      '        with:',
      '          name: rapport',
    ].join('\n');
    const vues = suitesJouees(argumentsPlaywrightTest(sansCommentairesYaml(workflow)), [
      'dashboard.spec.ts',
      'rgpd.spec.ts',
      'llm.spec.ts',
    ]);
    expect([...vues].sort()).toEqual(['dashboard.spec.ts', 'rgpd.spec.ts']);
    // Et le recollage s'ARRETE a la cle YAML suivante : `actions/upload-artifact`
    // ne doit pas etre avale dans les arguments.
    expect(argumentsPlaywrightTest(sansCommentairesYaml(workflow))[0]).not.toContain('upload-artifact');
  });

  it('comprend une commande playwright nue comme jouant toute la testDir', () => {
    const workflow = '        run: pnpm exec playwright test --project=chromium\n';
    const vues = suitesJouees(argumentsPlaywrightTest(sansCommentairesYaml(workflow)), [
      'a.spec.ts',
      'b.spec.ts',
    ]);
    expect([...vues].sort()).toEqual(['a.spec.ts', 'b.spec.ts']);
  });
});
