import type { HTMLAttributes, ReactNode } from 'react';
import { cn } from './cn';

/**
 * D30-002 — le conteneur à défilement horizontal des tableaux « grille ».
 *
 * LE DÉFAUT. Dix écrans dessinent leur tableau avec `display:grid` et un
 * gabarit de colonnes à minima en pixels. Additionnés, ces minima valent de
 * **738 px** (`RotationsPage`) à **1 104 px** (`AuditLogsPage`) ; le conteneur
 * visible sur téléphone en fait **343**. Or le tableau était enfermé dans un
 * `<Card padding="none" className="overflow-hidden">` : `overflow:hidden`
 * **coupe** ce qui dépasse et n'offre **aucun** moyen de l'atteindre — ni barre,
 * ni glissement au doigt. Entre 52 % et 68 % de chaque ligne était donc
 * inatteignable, sans le moindre signal à l'écran. Sur `/rgpd/requests`, les
 * colonnes perdues portaient les actions de « Rectification · art. 16 » et
 * « Opposition · art. 21 ».
 *
 * L'IDIOME EXISTAIT DÉJÀ dans le dépôt — `MediaListPage.tsx:271` et
 * `JournalistsListPage.tsx:109` font exactement
 * `<div class="overflow-x-auto"><table class="w-full min-w-[860px]">`. Il n'a
 * simplement jamais été porté sur les tableaux en `grid`. Ce composant le
 * porte, en une seule pièce plutôt qu'en dix copies, et calcule la largeur
 * minimale à partir du gabarit lui-même (voir `largeurMinimaleGrille`) : un
 * `min-w-[1102px]` recopié à la main se désaccorde du gabarit au premier
 * changement de colonne, et personne ne le voit.
 *
 * ⚠️ POURQUOI UNE LARGEUR MINIMALE, ET PAS SEULEMENT `overflow-x-auto`.
 * Sans elle, la grille déborde de sa propre boîte mais les fonds
 * (`bg-slate-50/80` de l'en-tête, `hover:bg-slate-50/70` des lignes) ne font
 * que la largeur du conteneur : en défilant vers la droite on découvre des
 * cellules posées sur du vide. La largeur minimale est ce qui rend le
 * défilement *présentable*, pas seulement possible.
 *
 * ⚠️ CE QUE ÇA NE CHANGE PAS. Le `sticky top-0` des en-têtes n'est pas
 * dégradé : il était **déjà** inerte vis-à-vis du défilement de page, puisque
 * `Card` porte `overflow-hidden`, ce qui fait d'elle le conteneur de
 * défilement de référence du `sticky`. On remplace un conteneur de défilement
 * par un autre ; l'en-tête se comporte exactement pareil.
 */

export interface OptionsLargeurGrille {
  /**
   * L'écart entre colonnes, en px. Défaut 12 — c'est `gap-3`, utilisé par les
   * dix tableaux.
   *
   * ⚠️ `| undefined` explicite : le dépôt compile avec
   * `exactOptionalPropertyTypes: true`, où « propriété absente » et « propriété
   * valant `undefined` » sont deux types différents. `TableScroll` relaie ses
   * props telles quelles, `undefined` compris.
   */
  gouttiere?: number | undefined;
  /** Le rembourrage horizontal d'UN côté, en px. Défaut 16 — c'est `px-4`. `px-5` → 20. */
  rembourrageX?: number | undefined;
}

/** Découpe un gabarit `grid-template-columns` en pistes, sans casser les `minmax(a,b)`. */
function pistes(gabarit: string): string[] {
  const out: string[] = [];
  let courant = '';
  let profondeur = 0;
  for (const c of gabarit.trim()) {
    if (c === '(') profondeur += 1;
    if (c === ')') profondeur -= 1;
    if (/\s/.test(c) && profondeur === 0) {
      if (courant) out.push(courant);
      courant = '';
    } else {
      courant += c;
    }
  }
  if (courant) out.push(courant);
  return out;
}

/**
 * Le minimum en pixels d'UNE piste.
 *
 * `140px` → 140. `minmax(240px,1.4fr)` → 240 (la borne basse, seule opposable).
 * `1fr`, `auto`, `2fr` → **0** : ces pistes n'imposent rien par elles-mêmes, et
 * leur contenu n'est pas connaissable ici. Renvoyer autre chose que 0 serait
 * inventer une largeur — voir le témoin `'1fr 2fr auto'` de la garde.
 */
function minimumPiste(piste: string): number {
  const mm = /^minmax\(\s*([^,]+)\s*,/.exec(piste);
  const borne = (mm ? mm[1]! : piste).trim();
  const px = /^(\d+(?:\.\d+)?)px$/.exec(borne);
  return px ? Number(px[1]) : 0;
}

/**
 * La largeur en dessous de laquelle le tableau est amputé :
 * somme des minima des pistes + les gouttières + les deux rembourrages.
 */
export function largeurMinimaleGrille(gabarit: string, options: OptionsLargeurGrille = {}): number {
  const { gouttiere = 12, rembourrageX = 16 } = options;
  const liste = pistes(gabarit);
  const somme = liste.reduce((acc, p) => acc + minimumPiste(p), 0);
  const gouttieres = Math.max(0, liste.length - 1) * gouttiere;
  return somme + gouttieres + rembourrageX * 2;
}

export interface TableScrollProps extends HTMLAttributes<HTMLDivElement> {
  /** Le gabarit `grid-template-columns` des lignes enfermées. */
  template: string;
  /** `gap-*` des lignes, en px. Défaut 12 (`gap-3`). */
  gouttiere?: number;
  /** `px-*` des lignes, en px pour UN côté. Défaut 16 (`px-4`). */
  rembourrageX?: number;
  children: ReactNode;
}

export function TableScroll({
  template,
  gouttiere,
  rembourrageX,
  className,
  children,
  ...reste
}: TableScrollProps) {
  const largeurMin = largeurMinimaleGrille(template, { gouttiere, rembourrageX });
  return (
    // `data-tableau-defilable` n'est pas décoratif : sous jsdom, Tailwind n'est
    // pas compilé, `getComputedStyle(...).overflowX` rend `visible` même quand
    // la classe est là. La garde a besoin d'un marqueur qu'elle puisse croire,
    // ET elle vérifie en plus la présence littérale de `overflow-x-auto`.
    <div data-tableau-defilable="true" className={cn('overflow-x-auto', className)} {...reste}>
      <div style={{ minWidth: `${largeurMin}px` }}>{children}</div>
    </div>
  );
}
