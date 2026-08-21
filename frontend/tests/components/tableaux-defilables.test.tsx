/**
 * GARDE D30-002 — « les neuf tableaux "grille" exigent 718 a 1088 px et ne sont
 * enfermes dans AUCUN conteneur a defilement ».
 *
 * Deux gardes, parce que le defaut a deux faces
 * ─────────────────────────────────────────────
 *  A. STRUCTURELLE (sur les sources) — le defaut est un manque de conteneur.
 *     Un manque ne se voit pas en rendant UN ecran : il se voit en verifiant
 *     que TOUS les gabarits de colonnes du produit sont enfermes. jsdom ne
 *     calcule aucune mise en page (`offsetWidth === 0`, Tailwind n'est pas
 *     compile) : mesurer 1102 px dans un test unitaire est impossible. On
 *     verifie donc la STRUCTURE, exactement comme le releve statique
 *     `03_statique-barre-basse-et-tableaux.txt` de l'agent 30.
 *  B. DE RENDU (sur le DOM reel) — `RgpdRequestsPage` est monte pour de vrai,
 *     avec ses donnees, et l'on remonte la chaine des ancetres depuis chaque
 *     ligne de grille jusqu'au conteneur defilable. C'est l'ancrage : la garde
 *     structurelle pourrait etre satisfaite par un `<TableScroll>` place au
 *     mauvais endroit ; celle-ci ne le pourrait pas.
 *
 * TÉMOINS (sans eux ces deux gardes valent zero) :
 *   1. la sonde structurelle, jouee sur le markup d'AVANT correctif, doit
 *      rendre un defaut ;
 *   2. elle doit lever si elle ne trouve AUCUN fichier a inspecter, et le
 *      nombre de fichiers trouves est assure explicitement ;
 *   3. la sonde de rendu, jouee sur une reproduction du markup d'avant
 *      correctif, doit rendre un defaut ;
 *   4. `largeurMinimaleGrille` est verifiee sur des gabarits dont on connait la
 *      reponse a la main, y compris un gabarit tout en `fr` (reponse : les
 *      gouttieres et le rembourrage seuls).
 */
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, join, relative, sep } from 'node:path';
import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';

import { largeurMinimaleGrille, TableScroll } from '@/components/ui/TableScroll';
import { RgpdRequestsPage } from '@/features/rgpd/RgpdRequestsPage';
import { renderScreen } from '../helpers/renderScreen';
import { getJson } from '../msw/handlers';

/**
 * On remonte depuis le dossier courant jusqu'à trouver `src/components/ui` :
 * `import.meta.url` n'est PAS un `file://` sous le transformateur SWC de
 * vitest (`TypeError: The URL must be of scheme file`), et coder
 * `process.cwd()` en dur casserait dès qu'on lance vitest depuis la racine du
 * dépôt. Si la remontée échoue, le TÉMOIN 2 le dit à voix haute.
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

// ---------------------------------------------------------------------------
// A. Sonde structurelle
// ---------------------------------------------------------------------------

/**
 * Ce qui compte comme « tableau grille » — et ce qui n'en est pas un.
 *
 * Un tableau grille se reconnait a un gabarit de colonnes SANS prefixe
 * responsive : `style={{ gridTemplateColumns: GRID }}` ou `grid-cols-[...]`.
 * Les mises en page a deux colonnes (`lg:grid-cols-[1fr_360px]` de
 * `CoveragePage`, `md:grid-cols-[1fr_120px_120px]` de `CampaignWizardPage`)
 * portent TOUJOURS un prefixe : elles s'effondrent d'elles-memes sur petit
 * ecran, ce n'est pas le defaut vise. Le prefixe est donc le discriminant, et
 * il est verifie par le temoin « la sonde ne compte pas les mises en page ».
 */
const MOTIF_GRILLE = /(?<![\w:-])(?:gridTemplateColumns\s*:|grid-cols-\[)/g;

const OUVERTURE = '<TableScroll';
const FERMETURE = '</TableScroll>';

interface Grille {
  index: number;
  extrait: string;
  enferme: boolean;
}

interface Inspection {
  grilles: Grille[];
  defauts: Grille[];
}

/** Les plages de texte couvertes par un `<TableScroll> … </TableScroll>`. */
function plagesEnfermees(source: string): Array<[number, number]> {
  const plages: Array<[number, number]> = [];
  let curseur = 0;
  for (;;) {
    const debut = source.indexOf(OUVERTURE, curseur);
    if (debut === -1) break;
    const fin = source.indexOf(FERMETURE, debut);
    if (fin === -1) break; // balise non fermee : la plage ne compte pas
    plages.push([debut, fin + FERMETURE.length]);
    curseur = fin + FERMETURE.length;
  }
  return plages;
}

/**
 * La dispense NOMINATIVE, écrite dans le code plutôt que dans le test.
 *
 * Deux grilles ne peuvent pas porter leur conteneur elles-mêmes : `CompanyRow`
 * et `RunRow` sont des LIGNES, montées une par une (par le virtualiseur pour la
 * première, par un `map` pour la seconde) ; le conteneur ne peut vivre que chez
 * l'appelant, sans quoi chaque ligne défilerait pour son compte et l'en-tête ne
 * suivrait pas. Elles portent donc, dans leur source, un commentaire
 *
 *     // TABLEAU-DEFILABLE: enferme par <Fichier.tsx> — <pourquoi>
 *
 * et la sonde va VÉRIFIER chez le fichier nommé. Une dispense qui pointe vers
 * un fichier sans `<TableScroll>` reste un défaut.
 */
const MOTIF_MARQUEUR = /TABLEAU-DEFILABLE:\s*enferme par\s+([\w.-]+\.tsx)/;

function inspecter(
  source: string,
  resoudre?: (nomFichier: string) => string | undefined,
): Inspection {
  const plages = plagesEnfermees(source);
  const grilles: Grille[] = [];
  for (const m of source.matchAll(MOTIF_GRILLE)) {
    const index = m.index ?? 0;
    let enferme = plages.some(([a, b]) => index > a && index < b);
    if (!enferme) {
      // Le marqueur doit précéder IMMÉDIATEMENT la grille (400 caractères) :
      // un marqueur posé en tête de fichier ne dispenserait pas tout le fichier.
      const avant = source.slice(Math.max(0, index - 400), index);
      const marque = MOTIF_MARQUEUR.exec(avant);
      if (marque && resoudre) {
        const porteur = resoudre(marque[1]!);
        enferme = porteur !== undefined && porteur.includes(OUVERTURE) && porteur.includes(FERMETURE);
      }
    }
    grilles.push({
      index,
      extrait: source.slice(index, index + 60).replace(/\s+/g, ' '),
      enferme,
    });
  }
  return { grilles, defauts: grilles.filter((g) => !g.enferme) };
}

/** Parcours recursif de `src/`, fichiers `.tsx` seulement. */
function fichiersTsx(dossier: string, acc: string[] = []): string[] {
  for (const entree of readdirSync(dossier)) {
    const chemin = join(dossier, entree);
    if (statSync(chemin).isDirectory()) fichiersTsx(chemin, acc);
    else if (chemin.endsWith('.tsx')) acc.push(chemin);
  }
  return acc;
}

function cheminRelatif(chemin: string): string {
  return relative(RACINE_SRC, chemin).split(sep).join('/');
}

// ---------------------------------------------------------------------------
// B. Sonde de rendu
// ---------------------------------------------------------------------------

interface ReleveRendu {
  lignesDeGrille: number;
  sansConteneur: number;
  largeursMin: string[];
}

/**
 * Depuis chaque element porteur d'un `grid-template-columns` en ligne, remonte
 * les ancetres a la recherche du conteneur defilable.
 *
 * ⚠️ Pourquoi `data-tableau-defilable` et pas `getComputedStyle(...).overflowX` :
 * Tailwind n'est pas compile dans jsdom, `overflow-x-auto` n'est qu'un nom de
 * classe sans regle CSS derriere. On assure donc DEUX choses : le marqueur pose
 * par `TableScroll` (structure), ET la presence litterale de `overflow-x-auto`
 * dans son `className` (la classe qui produit reellement le defilement).
 */
function relever(racine: HTMLElement): ReleveRendu {
  const lignes = Array.from(racine.querySelectorAll<HTMLElement>('*')).filter(
    (el) => el.style.gridTemplateColumns !== '',
  );
  if (lignes.length === 0) {
    throw new Error('SONDE INVALIDE : aucun element porteur de grid-template-columns.');
  }
  let sansConteneur = 0;
  const largeursMin = new Set<string>();
  for (const ligne of lignes) {
    let ancetre: HTMLElement | null = ligne.parentElement;
    let trouve = false;
    while (ancetre) {
      if (ancetre.dataset.tableauDefilable === 'true') {
        expect(ancetre.className).toContain('overflow-x-auto');
        const interieur = ancetre.firstElementChild as HTMLElement | null;
        if (interieur?.style.minWidth) largeursMin.add(interieur.style.minWidth);
        trouve = true;
        break;
      }
      ancetre = ancetre.parentElement;
    }
    if (!trouve) sansConteneur += 1;
  }
  return { lignesDeGrille: lignes.length, sansConteneur, largeursMin: [...largeursMin] };
}

// ---------------------------------------------------------------------------

const REQUETES_RGPD = {
  data: [
    {
      id: 1,
      type: 'rectification',
      subject_email: 'jean.dupont@example.test',
      status: 'pending',
      requested_at: '2026-08-01T09:00:00Z',
      processed_at: null,
      note: null,
    },
    {
      id: 2,
      type: 'opposition',
      subject_email: 'marie.martin@example.test',
      status: 'done',
      requested_at: '2026-08-02T09:00:00Z',
      processed_at: '2026-08-03T09:00:00Z',
      note: 'traitee',
    },
  ],
};

describe('D30-002 — les tableaux grille sont enfermes dans un conteneur defilable', () => {
  const tousLesFichiers = fichiersTsx(RACINE_SRC);
  const sources = tousLesFichiers.map((c) => ({
    chemin: c,
    relatif: cheminRelatif(c),
    base: c.split(sep).pop()!,
    source: readFileSync(c, 'utf8'),
  }));
  /** Résout un nom de fichier nu (`CompaniesListPage.tsx`) vers sa source. */
  const resoudre = (base: string) => sources.find((f) => f.base === base)?.source;

  const porteurs = sources
    .map((f) => ({ ...f, inspection: inspecter(f.source, resoudre) }))
    .filter((f) => f.inspection.grilles.length > 0);

  it('TEMOIN 2 : la sonde trouve bien des fichiers a inspecter', () => {
    // Sans cette assertion, un chemin casse rendrait 0 fichier — et 0 defaut.
    expect(tousLesFichiers.length).toBeGreaterThan(50);
    // L'agent 30 en nomme neuf ; j'en trouve un dixieme (ScraperRunsPage).
    expect(porteurs.length).toBeGreaterThanOrEqual(10);
  });

  it('TEMOIN 1 : la sonde condamne le markup d’AVANT correctif', () => {
    // Recopie litterale de `RgpdRequestsPage.tsx:155-163` tel qu'il etait.
    const avant = `
      <Card padding="none" className="overflow-hidden">
        <div role="row" className={cn('sticky top-0 z-10 grid items-center gap-3 px-4 py-3')}
             style={{ gridTemplateColumns: GRID }}>
          <div>Type</div>
        </div>
      </Card>`;
    const insp = inspecter(avant);
    expect(insp.grilles).toHaveLength(1);
    expect(insp.defauts).toHaveLength(1);

    // ... et elle acquitte la meme chose une fois enfermee : elle sait dire OUI.
    const apres = `<TableScroll template={GRID}>${avant}</TableScroll>`;
    expect(inspecter(apres).defauts).toHaveLength(0);
  });

  it('TEMOIN 1 bis : la sonde ne compte PAS les mises en page responsive', () => {
    const misesEnPage = `
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-[1fr_360px]" />
      <div className="grid gap-4 lg:grid-cols-[320px_1fr]" />
      <div className="grid grid-cols-1 items-center gap-2 md:grid-cols-[1fr_120px_120px]" />`;
    expect(inspecter(misesEnPage).grilles).toHaveLength(0);
  });

  it('TEMOIN 1 ter : une dispense qui pointe vers un fichier sans conteneur reste un defaut', () => {
    const ligne = `
      // TABLEAU-DEFILABLE: enferme par Ailleurs.tsx — la ou vit le conteneur
      style={{ gridTemplateColumns: GRID }}`;
    // Le fichier nomme ne contient pas de <TableScroll> : la dispense ne prend pas.
    expect(inspecter(ligne, () => '<div>rien du tout</div>').defauts).toHaveLength(1);
    // Le fichier nomme est introuvable : elle ne prend pas non plus.
    expect(inspecter(ligne, () => undefined).defauts).toHaveLength(1);
    // Et elle prend quand le porteur enferme vraiment.
    expect(
      inspecter(ligne, () => `<TableScroll template={GRID}><div/></TableScroll>`).defauts,
    ).toHaveLength(0);
    // Un marqueur trop LOIN de la grille ne dispense pas : 400 caracteres de
    // remplissage entre les deux, et le defaut revient.
    const tropLoin = ligne.replace('style=', `${'x'.repeat(420)}\n      style=`);
    expect(
      inspecter(tropLoin, () => `<TableScroll template={GRID}><div/></TableScroll>`).defauts,
    ).toHaveLength(1);
  });

  it.each(
    // Le tableau des cas est construit a la lecture du disque : ajouter un
    // ecran a gabarit de colonnes l'ajoute ICI, sans toucher au test.
    porteurs.map((f) => [f.relatif, f] as const),
  )('%s — chaque gabarit de colonnes est enferme', (relatif, fichier) => {
    const details = fichier.inspection.defauts.map((d) => d.extrait).join('\n  ');
    expect(
      fichier.inspection.defauts.length,
      `${relatif} : ${fichier.inspection.defauts.length} gabarit(s) hors conteneur defilable :\n  ${details}`,
    ).toBe(0);
  });

  it('largeurMinimaleGrille rend la largeur que l’on calcule a la main', () => {
    // RgpdRequestsPage : 140+240+130+180+180+140 = 1010, 5 gouttieres de 12 = 60,
    // rembourrage px-4 = 2x16 = 32  ->  1102 px.
    expect(
      largeurMinimaleGrille('minmax(140px,1fr) minmax(240px,1.4fr) 130px 180px 180px 140px'),
    ).toBe(1102);
    // AuditLogsPage : 1000 + 6x12 + 32 = 1104 px.
    expect(
      largeurMinimaleGrille('160px 180px minmax(160px,1.2fr) minmax(160px,1fr) 100px 130px 110px'),
    ).toBe(1104);
    // RotationsPage : 650 + 4x12 + 2x20 (px-5) = 738 px.
    expect(largeurMinimaleGrille('minmax(180px,1fr) 90px 110px 90px 180px', { rembourrageX: 20 })).toBe(738);
    // TEMOIN 4 : un gabarit tout en `fr` n'a AUCUN minimum propre — la fonction
    // ne doit pas inventer de largeur, seulement les gouttieres et le rembourrage.
    expect(largeurMinimaleGrille('1fr 2fr auto')).toBe(56); // 0 + 2x12 + 32
    // ... et un gabarit vide rend 0, pas NaN.
    expect(largeurMinimaleGrille('', { gouttiere: 0, rembourrageX: 0 })).toBe(0);
  });

  it('TEMOIN 3 : la sonde de rendu condamne une reproduction du markup d’avant', () => {
    const { container } = render(
      <div className="overflow-hidden">
        <div role="row" style={{ gridTemplateColumns: '140px 240px' }}>
          <div>Type</div>
        </div>
      </div>,
    );
    const releve = relever(container);
    expect(releve.lignesDeGrille).toBe(1);
    expect(releve.sansConteneur).toBe(1);
  });

  it('TEMOIN 3 bis : la sonde de rendu leve si rien ne porte de gabarit', () => {
    const { container } = render(<div>Pas de grille ici.</div>);
    expect(() => relever(container)).toThrow(/SONDE INVALIDE/);
  });

  it('TableScroll pose bien le conteneur, la classe et la largeur minimale', () => {
    render(
      <TableScroll template="140px 240px" data-testid="ts">
        <div role="row" style={{ gridTemplateColumns: '140px 240px' }}>
          <div>Type</div>
        </div>
      </TableScroll>,
    );
    const conteneur = screen.getByTestId('ts');
    expect(conteneur.dataset.tableauDefilable).toBe('true');
    expect(conteneur.className).toContain('overflow-x-auto');
    const interieur = conteneur.firstElementChild as HTMLElement;
    // 140 + 240 = 380, 1 gouttiere de 12, rembourrage 32 -> 424 px
    expect(interieur.style.minWidth).toBe('424px');
    expect(relever(conteneur).sansConteneur).toBe(0);
  });

  it('RgpdRequestsPage rendu pour de vrai : toutes ses lignes sont dans le conteneur', async () => {
    await renderScreen(<RgpdRequestsPage />, {
      path: '/rgpd/requests',
      handlers: [getJson('/rgpd/requests', REQUETES_RGPD)],
    });
    // On attend les donnees : sans elles l'ecran rend un EmptyState et la sonde
    // n'aurait AUCUNE ligne a inspecter (cf. TEMOIN 3 bis).
    expect(await screen.findByText('jean.dupont@example.test')).toBeVisible();

    const releve = relever(document.body);
    expect(releve.lignesDeGrille).toBe(3); // en-tete + 2 lignes
    expect(releve.sansConteneur).toBe(0);
    expect(releve.largeursMin).toEqual(['1102px']);
  });
});
