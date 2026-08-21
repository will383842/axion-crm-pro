import { useEffect, useRef } from 'react';

/**
 * D28-003 — tenir la promesse de `aria-modal="true"`.
 *
 * Le constat de l'agent 28 : `Modal`, `Drawer` et `GlobalSearch` déclarent tous
 * `role="dialog" aria-modal="true"`, et sa mesure au clavier donnait
 * **6 sorties sur 6 Tab**, **10 sur 10**, **8 sur 8**. `aria-modal` dit au
 * lecteur d'écran « le reste de la page n'existe plus » ; au clavier, le reste
 * de la page était toujours là. C'est une promesse d'accessibilité non tenue :
 * l'utilisateur qui tabule sort de la boîte de dialogue **sans le savoir**,
 * puisque rien à l'écran ne le lui dit.
 *
 * Ce crochet fait TROIS choses, et pas une quatrième :
 *   1. il **entre** le focus dans le dialogue à l'ouverture (sauf s'il y est
 *      déjà — `GlobalSearch` le pose lui-même via `autoFocus`, on ne le lui
 *      reprend pas) ;
 *   2. il **piège** Tab et Shift+Tab en cycle ;
 *   3. il **restitue** le focus au déclencheur à la fermeture.
 *
 * ⚠️ CE QU'IL NE FAIT PAS, et pourquoi. Il ne pose ni `inert` ni
 * `aria-hidden` sur l'arrière-plan — quatrième reproche de D28-003. Les trois
 * dialogues sont rendus **au milieu de l'arbre React**, pas dans une portail :
 * il n'existe aucun élément que l'on puisse rendre `inert` sans rendre `inert`
 * le dialogue lui-même, qui en est un descendant. Le faire proprement suppose
 * de passer les trois composants en `createPortal` vers `document.body` —
 * changement de structure du DOM, donc de sémantique (empilement, `z-index`,
 * sélecteurs CSS descendants, tests existants). Hors de ce lot : à décider,
 * pas à décider en passant.
 *
 * ⚠️ L'écouteur est posé en phase de CAPTURE sur `document`. En phase de
 * bulle, un `stopPropagation()` d'un composant tiers (cmdk en pose) suffirait à
 * neutraliser le piège sans que rien ne le signale.
 */

/**
 * Les éléments atteignables au clavier.
 *
 * ⚠️ Aucun filtrage sur la VISIBILITÉ : sous jsdom, `offsetParent` vaut
 * toujours `null` et `getComputedStyle` ne connaît pas les classes Tailwind —
 * un filtre « visible » viderait la liste dans les tests tout en la laissant
 * pleine dans le navigateur. Autrement dit il rendrait le piège vert au banc
 * et inerte en production. On s'en tient à ce que le DOM affirme lui-même :
 * la présence dans l'ordre de tabulation.
 */
const SELECTEUR_FOCALISABLES = [
  'a[href]',
  'area[href]',
  'button:not([disabled])',
  'input:not([disabled]):not([type="hidden"])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  'iframe',
  'audio[controls]',
  'video[controls]',
  '[contenteditable]:not([contenteditable="false"])',
  '[tabindex]:not([tabindex="-1"])',
].join(',');

/**
 * Les focalisables du conteneur, dans l'ordre du document.
 *
 * ⚠️ On ignore les `tabindex` POSITIFS : leur ordre réel dépend du document
 * entier, pas du dialogue. Le dépôt n'en pose aucun (`grep -rn 'tabIndex={[1-9]'`
 * → 0). Si l'un apparaît, ce cycle le rangera au mauvais endroit — d'où ce
 * commentaire plutôt qu'un silence.
 */
export function elementsFocalisables(conteneur: HTMLElement): HTMLElement[] {
  return Array.from(conteneur.querySelectorAll<HTMLElement>(SELECTEUR_FOCALISABLES)).filter(
    (el) => !el.hasAttribute('inert') && el.getAttribute('aria-hidden') !== 'true',
  );
}

export interface OptionsPiegeFocus {
  /**
   * Appelé à la fermeture quand le déclencheur d'origine a QUITTÉ le DOM.
   * `GlobalSearch` démonte son propre bouton pendant l'ouverture : sans ce
   * relais, le focus retomberait sur `<body>` — c'est exactement le
   * « focus restitué à BUTTON "Importer" » relevé par l'agent 28.
   */
  surDeclencheurDisparu?: () => void;
}

/**
 * @param actif   le dialogue est-il ouvert ?
 * @returns la ref à poser sur l'élément qui porte `role="dialog"`.
 */
export function usePiegeFocus<T extends HTMLElement = HTMLDivElement>(
  actif: boolean,
  options: OptionsPiegeFocus = {},
) {
  const ref = useRef<T | null>(null);
  const declencheur = useRef<Element | null>(null);
  // La callback est lue au moment du démontage : la garder dans une ref évite
  // de remonter le piège (et donc de re-déplacer le focus) à chaque rendu du
  // parent, ce qui arrive dès que `onClose` est une lambda en ligne.
  const surDisparu = useRef(options.surDeclencheurDisparu);
  surDisparu.current = options.surDeclencheurDisparu;

  useEffect(() => {
    if (!actif) return;
    const conteneur = ref.current;
    if (!conteneur) return;

    declencheur.current = document.activeElement;

    // Le conteneur doit pouvoir recevoir le focus quand il ne contient AUCUN
    // focalisable (dialogue purement informatif) : sans cela le focus resterait
    // sur `<body>` et la première tabulation repartirait dans la page.
    if (!conteneur.hasAttribute('tabindex')) conteneur.setAttribute('tabindex', '-1');

    if (!conteneur.contains(document.activeElement)) {
      const liste = elementsFocalisables(conteneur);
      (liste[0] ?? conteneur).focus();
    }

    const surTouche = (e: KeyboardEvent) => {
      if (e.key !== 'Tab') return;
      const liste = elementsFocalisables(conteneur);
      if (liste.length === 0) {
        e.preventDefault();
        conteneur.focus();
        return;
      }
      const premier = liste[0]!;
      const dernier = liste[liste.length - 1]!;
      const actifCourant = document.activeElement;

      if (!conteneur.contains(actifCourant)) {
        e.preventDefault();
        (e.shiftKey ? dernier : premier).focus();
        return;
      }
      // Cycle. Avec UN SEUL focalisable, `premier === dernier` : Tab est
      // neutralisé et le focus reste dessus — c'est le cas de `GlobalSearch`.
      if (!e.shiftKey && actifCourant === dernier) {
        e.preventDefault();
        premier.focus();
      } else if (e.shiftKey && actifCourant === premier) {
        e.preventDefault();
        dernier.focus();
      }
    };

    document.addEventListener('keydown', surTouche, true);
    return () => {
      document.removeEventListener('keydown', surTouche, true);
      const cible = declencheur.current;
      if (cible instanceof HTMLElement && cible.isConnected) cible.focus();
      else surDisparu.current?.();
      declencheur.current = null;
    };
  }, [actif]);

  return ref;
}
