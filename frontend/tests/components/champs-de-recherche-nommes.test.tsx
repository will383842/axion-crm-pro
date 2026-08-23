/**
 * GARDE D28-007 — « SearchInput, composant du systeme employe par huit ecrans,
 * n'expose aucun moyen de poser un libelle ».
 *
 * ── CE QUI ETAIT MESURE ───────────────────────────────────────────────────
 *
 * Mesure du 2026-08-22, avant correctif (`src/components/ui/Toolbar.tsx`) :
 * les proprietes de `SearchInput` etaient exactement
 * `{ value, onChange, placeholder, className }`, et son `<input>` ne portait
 * ni `id`, ni `aria-label`, ni `aria-labelledby` ; aucun `<label>` ne
 * l'accompagnait. Seul un `placeholder` tenait lieu de nom, sur les huit
 * ecrans qui l'emploient (entreprises, contacts, candidats, hub, journalistes,
 * medias, journal d'audit, collectes).
 *
 * ── POURQUOI UN PLACEHOLDER N'EST PAS UN LIBELLE ─────────────────────────
 *
 * Il DISPARAIT a la premiere frappe : la personne qui revient sur le champ
 * apres avoir tape trois lettres n'a plus rien pour savoir ce qu'elle
 * cherchait. Il n'est pas restitue de facon fiable par les aides techniques,
 * et il n'offre aucune cible a la commande vocale (« clique sur Rechercher une
 * entreprise »). Un lecteur d'ecran annoncait « zone d'edition », point.
 *
 * ── CE QUE CETTE GARDE MESURE ────────────────────────────────────────────
 *
 *   A. AU RENDU — le champ a un NOM ACCESSIBLE, calcule par le DOM et non
 *      declare par nous : `getByLabelText` ne trouve que ce qu'une aide
 *      technique trouverait. Le TEMOIN eprouve la sonde sur le markup d'avant
 *      correctif, ou elle doit echouer a trouver le champ.
 *   B. SUR LES SOURCES — chaque `<SearchInput>` du depot porte un `label`.
 *      `tsc` l'exige deja (la propriete est requise), mais la suite vitest ne
 *      joue pas `tsc` : sans cette face, un futur `label={undefined}` ou un
 *      assouplissement de la propriete passerait ici en silence.
 *
 * Elle ne mesure PAS les autres champs sans libellé du depot — le constat en
 * denombre quinze sur dix ecrans. Elle ferme le composant PARTAGE, celui par
 * lequel le defaut se multipliait ; les champs propres a un ecran restent a
 * traiter un ecran a la fois.
 */
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, join, relative, sep } from 'node:path';
import { useState } from 'react';
import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

// Le MODULE, pas la barrique `@/components/ui` : convention des tests de ce
// dossier, et on ne charge pas trente composants pour en eprouver un.
import { SearchInput } from '@/components/ui/Toolbar';

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

/** Parcours recursif a la main, pour n'oublier aucun module. */
function modules(dossier: string, acc: string[] = []): string[] {
  for (const entree of readdirSync(dossier)) {
    const chemin = join(dossier, entree);
    if (statSync(chemin).isDirectory()) modules(chemin, acc);
    else if (chemin.endsWith('.tsx')) acc.push(chemin);
  }
  return acc;
}

function cheminRelatif(chemin: string): string {
  return relative(RACINE_SRC, chemin).split(sep).join('/');
}

/**
 * Les emplois de `<SearchInput …>` d'un module, avec TOUS leurs attributs.
 *
 * ⚠️ On ne peut PAS couper au premier `>` : la moitie des appels du depot
 * portent une fonction flechee (`onChange={(v) => …}`), et le `>` de la fleche
 * arrive avant la fin de la balise. Une sonde naive tronquerait la balise
 * juste avant le `label` qui suit, et signalerait un champ anonyme qui ne
 * l'est pas — un faux rouge, qu'on ferait taire en supprimant la garde.
 *
 * On compte donc la profondeur des accolades : en JSX, tout ce qui contient
 * une fleche vit dans un `{…}`, et la balise se ferme sur un `>` a la
 * profondeur zero. Le TEMOIN l'eprouve sur un appel a fonction flechee.
 */
export function emploisDeSearchInput(source: string): string[] {
  const trouves: string[] = [];
  let curseur = 0;
  for (;;) {
    const debut = source.indexOf('<SearchInput', curseur);
    if (debut === -1) break;

    let profondeur = 0;
    let fin = -1;
    for (let i = debut; i < source.length; i += 1) {
      const c = source[i];
      if (c === '{') profondeur += 1;
      else if (c === '}') profondeur -= 1;
      else if (c === '>' && profondeur === 0) {
        fin = i;
        break;
      }
    }
    if (fin === -1) break; // balise non fermee : on ne devine pas

    trouves.push(source.slice(debut, fin + 1));
    curseur = fin + 1;
  }
  return trouves;
}

export function porteUnLibelle(balise: string): boolean {
  // `label={…}` autant que `label="…"` : les deux nomment le champ.
  //
  // ⚠️ `(?<![\w-])` et non `\b` : `\b` matche AUSSI dans `aria-label`, parce
  // que le tiret est un non-mot. La sonde aurait accepte
  // `<SearchInput aria-label="…">` — un attribut que ce composant ne transmet
  // nulle part, donc une reparation qui ne repare rien. Le TEMOIN l'eprouve.
  return /(?<![\w-])label\s*=\s*(?:"[^"]+"|\{[^}]+\})/.test(balise);
}

// ---------------------------------------------------------------------------
// TEMOIN — la sonde retrouve le defaut, et sait dire non
// ---------------------------------------------------------------------------

describe('D28-007 — TEMOIN : ce que mesurent les deux sondes', () => {
  it('un champ nomme par le seul placeholder n a PAS de nom accessible', () => {
    // Le markup d'AVANT correctif, reproduit a l'identique. Si cette sonde
    // trouvait le champ ici, tout le reste du fichier serait vert par
    // construction.
    render(
      <div>
        <input placeholder="Rechercher une entreprise…" />
      </div>,
    );

    expect(screen.queryByLabelText('Rechercher une entreprise')).toBeNull();
    // Et pourtant le champ EST rendu : c'est bien le nom qui manque, pas le
    // champ.
    expect(screen.getByPlaceholderText('Rechercher une entreprise…')).toBeInTheDocument();
  });

  it('la sonde de source ne se laisse pas tronquer par une fonction flechee', () => {
    // Le piege exact : le `>` de la fleche precede le `label`. Une sonde qui
    // couperait la, verrait une balise anonyme et rougirait a tort.
    const avecFleche =
      '<SearchInput onChange={(v) => { setSearch(v); setPage(1); }} label="Rechercher un journaliste" />';
    const [balise] = emploisDeSearchInput(avecFleche);

    expect(balise).toBe(avecFleche);
    expect(porteUnLibelle(balise ?? '')).toBe(true);

    // Et la sonde compte bien DEUX emplois quand il y en a deux.
    expect(emploisDeSearchInput(`${avecFleche}\n<SearchInput label="B" value={s} />`)).toHaveLength(2);
  });

  it('la sonde de source distingue une balise nommee d une balise nue', () => {
    expect(porteUnLibelle('<SearchInput value={s} onChange={setS} />')).toBe(false);
    expect(porteUnLibelle('<SearchInput placeholder="Rechercher…" />')).toBe(false);
    expect(porteUnLibelle('<SearchInput label="Rechercher un média" value={s} />')).toBe(true);
    expect(porteUnLibelle('<SearchInput\n  label={titre}\n  value={s}\n>')).toBe(true);
    // `aria-label` n'est PAS une propriete de ce composant : le compter
    // laisserait passer une reparation qui ne repare rien.
    expect(porteUnLibelle('<SearchInput aria-label="Rechercher" value={s} />')).toBe(false);
  });

  it('la sonde de source trouve vraiment les emplois du depot', () => {
    const total = modules(RACINE_SRC)
      .filter((chemin) => cheminRelatif(chemin) !== 'components/ui/Toolbar.tsx')
      .reduce((n, chemin) => n + emploisDeSearchInput(readFileSync(chemin, 'utf8')).length, 0);

    // Mesure du 2026-08-22 : huit ecrans. On n'exige pas huit — un neuvieme
    // ecran est legitime — mais zero voudrait dire que la sonde ne lit rien.
    expect(
      total,
      'D28-007 : la sonde n a trouve AUCUN emploi de `<SearchInput>` dans `src`. Elle ne ' +
        'mesure rien. GESTE : verifier la remontee de `trouverRacineSrc()`.',
    ).toBeGreaterThan(0);
  });
});

// ---------------------------------------------------------------------------
// A. Au rendu — le champ a un nom, et le nom est masque a l'oeil
// ---------------------------------------------------------------------------

describe('D28-007 — SearchInput expose un nom accessible', () => {
  it('le champ est trouvable par son libelle', () => {
    render(<SearchInput label="Rechercher une entreprise" value="" onChange={() => {}} />);

    const champ = screen.getByLabelText('Rechercher une entreprise');
    expect(
      champ.tagName,
      'D28-007 : le nom accessible ne designe plus le champ de saisie lui-meme. ' +
        'GESTE : verifier que `htmlFor` du `<label>` pointe bien l `id` de l `<input>`.',
    ).toBe('INPUT');
  });

  it('le libelle est masque a l oeil, pas aux aides techniques', () => {
    // C'est la condition qui rend ce correctif sans risque : aucun pixel ne
    // change. Si quelqu'un retire `sr-only`, huit barres d'outils gagnent un
    // texte — la garde doit le dire AVANT la capture d'ecran.
    const { container } = render(
      <SearchInput label="Rechercher un média" value="" onChange={() => {}} />,
    );

    const libelle = container.querySelector('label');
    expect(libelle).not.toBeNull();
    expect(
      libelle?.className.includes('sr-only'),
      'D28-007 : le `<label>` de `SearchInput` n est plus `sr-only` : il devient VISIBLE dans ' +
        'les huit barres d outils qui emploient ce champ.\n' +
        'GESTE : soit remettre `sr-only`, soit assumer le changement d apparence et le faire ' +
        'valider — ce n est plus le meme correctif.',
    ).toBe(true);
  });

  it('le nom survit a la frappe, la ou le placeholder disparaissait', async () => {
    // Le coeur du constat, et il se mesure : on tape pour de vrai dans un
    // champ CONTROLE. Le placeholder n'est plus affiche des qu'il y a une
    // valeur — c'est la propriete meme du placeholder — tandis que le nom
    // accessible, lui, reste. Un harnais a `value=""` fige ne prouverait rien.
    const utilisateur = userEvent.setup();

    function Harnais() {
      const [valeur, setValeur] = useState('');
      return (
        <SearchInput
          label="Rechercher un candidat"
          placeholder="Nom, prénom, e-mail…"
          value={valeur}
          onChange={setValeur}
        />
      );
    }
    render(<Harnais />);

    const champ = screen.getByLabelText('Rechercher un candidat');
    await utilisateur.type(champ, 'dupont');

    expect((champ as HTMLInputElement).value).toBe('dupont');
    // Le placeholder ne dit plus rien a personne : le navigateur ne l'affiche
    // plus des lors que le champ porte une valeur.
    expect((champ as HTMLInputElement).value).not.toBe('');
    expect(
      screen.getByLabelText('Rechercher un candidat'),
      'D28-007 : le champ perd son nom des qu on y tape — c est exactement le defaut du ' +
        'placeholder-comme-libelle, reintroduit.',
    ).toBeInTheDocument();
  });

  it('deux champs sur le meme ecran ne partagent pas le meme identifiant', () => {
    // Sans `useId()`, deux `SearchInput` sur un meme ecran porteraient le meme
    // `id` : cliquer le second libelle focaliserait le premier champ, et
    // `getByLabelText` leverait « found multiple elements ».
    const { container } = render(
      <div>
        <SearchInput label="Rechercher une entreprise" value="" onChange={() => {}} />
        <SearchInput label="Rechercher un contact" value="" onChange={() => {}} />
      </div>,
    );

    const ids = Array.from(container.querySelectorAll('input')).map((champ) => champ.id);
    expect(ids.filter((id) => id !== '')).toHaveLength(2);
    expect(
      new Set(ids).size,
      `D28-007 : deux champs de recherche partagent l identifiant « ${ids.join(' / ')} ». ` +
        'Le libelle du second pointe alors le champ du premier.\n' +
        'GESTE : retablir `useId()` dans `SearchInput` (Toolbar.tsx).',
    ).toBe(2);
  });
});

// ---------------------------------------------------------------------------
// B. Sur les sources — aucun ecran ne rend un champ de recherche anonyme
// ---------------------------------------------------------------------------

describe('D28-007 — chaque `<SearchInput>` du depot porte son libelle', () => {
  it('aucun emploi anonyme', () => {
    const anonymes: string[] = [];

    for (const chemin of modules(RACINE_SRC)) {
      const relatif = cheminRelatif(chemin);
      if (relatif === 'components/ui/Toolbar.tsx') continue; // la definition
      for (const balise of emploisDeSearchInput(readFileSync(chemin, 'utf8'))) {
        if (!porteUnLibelle(balise)) anonymes.push(`${relatif} — ${balise.replace(/\s+/g, ' ')}`);
      }
    }

    expect(
      anonymes,
      'D28-007 : ces champs de recherche n ont aucun nom accessible :\n' +
        `${anonymes.join('\n')}\n\n` +
        'Un lecteur d ecran y annonce « zone d edition », et la commande vocale n a aucune ' +
        'cible. Le placeholder ne compte pas : il disparait a la premiere frappe.\n' +
        'GESTE : ajouter `label="Rechercher …"` en disant ce que CE champ cherche reellement — ' +
        'pas « Recherche », qui ne distingue pas huit ecrans les uns des autres.',
    ).toEqual([]);
  });
});
