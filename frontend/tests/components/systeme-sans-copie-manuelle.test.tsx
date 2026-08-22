/**
 * GARDE D27-001 — « `Stat` n'est employe nulle part, et il a ete recopie a la
 * main dans l'ecran qui en avait besoin ».
 *
 * ── CE QUI ETAIT MESURE, ET CE QUI L'EST AUJOURD'HUI ──────────────────────
 *
 * Mesure du 2026-08-22, avant correctif :
 *   · `src/components/ui/Stat.tsx` est exporte par `src/components/ui/index.ts`
 *     et `grep -rn 'Stat' src` ne rend AUCUN importateur ;
 *   · `src/features/coverage/CoveragePage.tsx` declarait sa PROPRE
 *     `function Stat({ label, value })`, employee deux fois dans le panneau de
 *     detail d'un departement ;
 *   · `CardFooter` (`src/components/ui/Card.tsx`) : declaration + reexport,
 *     zero appelant.
 *
 * ── POURQUOI UNE COPIE MANUSCRITE EST UN DEFAUT, ET PAS UN DETAIL ─────────
 *
 * Parce qu'elle DIVERGE en silence. La copie de `CoveragePage` n'avait aucune
 * des classes `dark:` de la version du systeme : en mode sombre, deux
 * cartouches blancs au milieu d'un panneau sombre. Personne ne pouvait le voir
 * en revoyant le systeme — la copie ne s'appelait pas depuis le systeme. C'est
 * la forme la plus couteuse du doublon : celle qui a l'air correcte des deux
 * cotes.
 *
 * ── CE QUE CETTE GARDE MESURE, ET CE QU'ELLE NE MESURE PAS ────────────────
 *
 * Deux faces, parce que le defaut en a deux :
 *   A. SUR LES SOURCES — aucune redeclaration locale de `Stat` dans `src`, et
 *      le composant du systeme a au moins un appelant (sinon il redevient
 *      exactement ce que le constat reproche). C'est la face qui RETIENT : elle
 *      empeche un deuxieme ecran de recopier.
 *   B. AU RENDU — la version du systeme porte bien les classes `dark:` que la
 *      copie n'avait pas. Sans elle, la face A pourrait etre satisfaite par un
 *      `Stat` partage aussi pauvre que la copie, et le mode sombre resterait
 *      casse.
 *
 * Elle ne mesure PAS l'apparence reelle : jsdom ne compile pas Tailwind, il n'y
 * a aucune mise en page a mesurer. Elle lit les classes emises, ce qui est
 * exactement ce que le correctif change.
 */
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, join, relative, sep } from 'node:path';
import { describe, expect, it } from 'vitest';
import { render } from '@testing-library/react';

// On importe le MODULE et non la barrique `@/components/ui` : c'est la
// convention des tests de ce dossier, et cela evite de charger les ~30
// composants du systeme pour en eprouver un. Que `Stat` soit bien REEXPORTE
// par l'index reste verifie par le cas « CoveragePage emploie bien le Stat du
// systeme », qui lit l'import de production.
import { Stat } from '@/components/ui/Stat';

/**
 * Meme remontee que `tests/components/tableaux-defilables.test.tsx` :
 * `import.meta.url` n'est pas un `file://` sous le transformateur SWC de
 * vitest, et `process.cwd()` en dur casse des qu'on lance vitest depuis la
 * racine du depot.
 */
function trouverRacineSrc(): string {
  let courant = process.cwd();
  for (let i = 0; i < 6; i += 1) {
    const candidat = join(courant, 'src');
    if (existsSync(join(candidat, 'components', 'ui'))) return candidat;
    const parent = dirname(courant);
    if (parent === courant) break;
    courant = parent;
  }
  const secours = join(process.cwd(), 'frontend', 'src');
  if (existsSync(join(secours, 'components', 'ui'))) return secours;
  return join(process.cwd(), 'src');
}

const RACINE_SRC = trouverRacineSrc();

/** Parcours recursif a la main : on veut TOUS les modules, sans exception. */
function modules(dossier: string, acc: string[] = []): string[] {
  for (const entree of readdirSync(dossier)) {
    const chemin = join(dossier, entree);
    if (statSync(chemin).isDirectory()) modules(chemin, acc);
    else if (chemin.endsWith('.tsx') || chemin.endsWith('.ts')) acc.push(chemin);
  }
  return acc;
}

function cheminRelatif(chemin: string): string {
  return relative(RACINE_SRC, chemin).split(sep).join('/');
}

/**
 * Une DECLARATION locale de `Stat` — les trois formes qu'on peut ecrire.
 *
 * On ne cherche pas « le mot Stat » : `StatusPill`, `Statistiques` et
 * `useStats` le contiennent. La frontiere `\b` derriere le nom est donc
 * indispensable, et le TEMOIN l'eprouve.
 */
const MOTIF_DECLARATION_STAT =
  /(?:function\s+Stat\b|const\s+Stat\b\s*[:=]|class\s+Stat\b)/;

export function declareUnStatLocal(source: string): boolean {
  return MOTIF_DECLARATION_STAT.test(source);
}

/** Le fichier du systeme, seul endroit ou la declaration est legitime. */
const FICHIER_DU_SYSTEME = 'components/ui/Stat.tsx';

// ---------------------------------------------------------------------------
// TEMOIN — sans lui, tout ce qui suit peut etre vert sans rien inspecter
// ---------------------------------------------------------------------------

describe('D27-001 — TEMOIN : la sonde retrouve le defaut, et ne s emballe pas', () => {
  it('retrouve la copie manuscrite telle qu elle etait ecrite dans CoveragePage', () => {
    const avant = [
      'function Stat({ label, value }: { label: string; value: string }) {',
      '  return <div className="rounded-xl bg-slate-50 p-3 ring-1 ring-slate-100" />;',
      '}',
    ].join('\n');

    expect(declareUnStatLocal(avant)).toBe(true);
    expect(declareUnStatLocal('const Stat = ({ label }) => null')).toBe(true);
  });

  it('ne confond pas `Stat` avec les noms qui le contiennent', () => {
    // Sans la frontiere de mot, cette garde rougirait sur `StatusPill` et sur
    // `useStats` — elle serait retiree dans la semaine, et le defaut
    // reviendrait avec elle.
    expect(declareUnStatLocal('function StatusPill() { return null }')).toBe(false);
    expect(declareUnStatLocal('const Statistiques = () => null')).toBe(false);
    expect(declareUnStatLocal('import { Stat } from "@/components/ui";')).toBe(false);
  });

  it('trouve vraiment des modules a inspecter', () => {
    // Le pire vert possible : une sonde qui n ouvre aucun fichier. Mesure du
    // 2026-08-22 : bien plus de 100 modules sous `src`.
    const trouves = modules(RACINE_SRC);
    expect(
      trouves.length,
      `D27-001 : aucun module inspecte sous « ${RACINE_SRC} ». La garde ne mesure RIEN. ` +
        'GESTE : verifier la remontee de `trouverRacineSrc()`.',
    ).toBeGreaterThan(50);
  });
});

// ---------------------------------------------------------------------------
// A. Sur les sources — une seule declaration, et au moins un appelant
// ---------------------------------------------------------------------------

describe('D27-001 — `Stat` vient du systeme, et de nulle part ailleurs', () => {
  it('aucun ecran ne redeclare son propre `Stat`', () => {
    const copies = modules(RACINE_SRC)
      .filter((chemin) => cheminRelatif(chemin) !== FICHIER_DU_SYSTEME)
      .filter((chemin) => declareUnStatLocal(readFileSync(chemin, 'utf8')))
      .map(cheminRelatif);

    expect(
      copies,
      `D27-001 : ces modules redeclarent un composant « Stat » a cote de celui du systeme : ` +
        `${copies.join(', ')}.\n` +
        'Mesure du 2026-08-22 : la copie de `CoveragePage` n avait aucune des classes `dark:` ' +
        'du composant partage — deux cartouches blancs dans un panneau sombre, invisibles a ' +
        'toute revue du systeme.\n' +
        'GESTE : supprimer la declaration locale et ecrire ' +
        "`import { Stat } from '@/components/ui';`. Si le besoin differe vraiment, lui donner " +
        'un AUTRE nom, pour que la divergence se voie.',
    ).toEqual([]);
  });

  it('le composant du systeme a au moins un appelant', () => {
    // C est l autre moitie du constat, et elle ne se deduit PAS de la
    // precedente : un `Stat` que plus personne n emploie serait de nouveau du
    // code mort exporte — exactement ce que D27-001 reproche.
    const appelants = modules(RACINE_SRC)
      .filter((chemin) => {
        const relatif = cheminRelatif(chemin);
        return relatif !== FICHIER_DU_SYSTEME && relatif !== 'components/ui/index.ts';
      })
      .filter((chemin) => /<Stat\b/.test(readFileSync(chemin, 'utf8')))
      .map(cheminRelatif);

    expect(
      appelants.length,
      'D27-001 : `src/components/ui/Stat.tsx` est exporte et plus AUCUN ecran ne le rend. ' +
        'C est l etat exact que ce constat reproche : un composant de systeme qui se lit ' +
        'comme une promesse et que rien n eprouve.\n' +
        'GESTE : soit un ecran l emploie, soit on le retire du systeme — comme `CardFooter` ' +
        'l a ete le 2026-08-22.',
    ).toBeGreaterThan(0);
  });

  it('`CoveragePage` emploie bien le `Stat` du systeme', () => {
    const source = readFileSync(join(RACINE_SRC, 'features/coverage/CoveragePage.tsx'), 'utf8');

    expect(
      /import\s*\{[^}]*\bStat\b[^}]*\}\s*from\s*'@\/components\/ui'/.test(source),
      "D27-001 : `CoveragePage.tsx` n importe plus `Stat` depuis '@/components/ui'. " +
        'S il a recupere une copie locale, le mode sombre du panneau de detail est de nouveau ' +
        'casse.\n' +
        "GESTE : retablir `import { Stat } from '@/components/ui';`.",
    ).toBe(true);
  });
});

describe('D27-001 — `CardFooter`, promesse morte, a ete retiree', () => {
  it('plus rien ne declare ni ne reexporte `CardFooter`', () => {
    // Mesure du 2026-08-22 : `grep -rn CardFooter src tests` ne rendait que sa
    // declaration et sa reexportation. On ne le remet PAS en place « au cas
    // ou » : un composant partage se justifie par son DEUXIEME appelant.
    const restes = modules(RACINE_SRC)
      .filter((chemin) => {
        const source = readFileSync(chemin, 'utf8');
        // Le tombeau explicatif de `Card.tsx` cite le nom : on ne compte que
        // les emplois de code, jamais un commentaire.
        return /(?:export\s+function\s+CardFooter\b|<CardFooter\b|\bCardFooter\s*,)/.test(source);
      })
      .map(cheminRelatif);

    expect(
      restes,
      `D27-001 : « CardFooter » est de retour dans ${restes.join(', ')} sans qu aucun ecran ne ` +
        'le rende.\n' +
        'GESTE : si un ecran en a reellement besoin, l ecrire DANS cet ecran ; ne le remonter ' +
        'au systeme qu au deuxieme appelant. La raison est ecrite en tete de `Card.tsx`.',
    ).toEqual([]);
  });
});

// ---------------------------------------------------------------------------
// B. Au rendu — ce que la copie manuscrite n avait pas
// ---------------------------------------------------------------------------

describe('D27-001 — le `Stat` partage porte le mode sombre que la copie n avait pas', () => {
  it('emet les classes `dark:` sur le cartouche, le libelle et la valeur', () => {
    const { container } = render(<Stat label="Entreprises" value="1 234" />);

    const cartouche = container.firstElementChild;
    expect(cartouche).not.toBeNull();

    const classes = container.innerHTML;
    for (const attendue of ['dark:bg-slate-800/60', 'dark:text-slate-400', 'dark:text-white']) {
      expect(
        classes.includes(attendue),
        `D27-001 : le \`Stat\` du systeme n emet plus « ${attendue} ». C est precisement ce que ` +
          'la copie manuscrite de `CoveragePage` n avait pas, et pourquoi le panneau de detail ' +
          'rendait deux cartouches blancs en mode sombre.\n' +
          'GESTE : retablir les classes `dark:` dans `src/components/ui/Stat.tsx`.',
      ).toBe(true);
    }

    // Le libelle et la valeur sont bien rendus : sans cela les assertions
    // ci-dessus pourraient porter sur un composant qui ne rend rien d utile.
    expect(container.textContent).toContain('Entreprises');
    expect(container.textContent).toContain('1 234');
  });
});
