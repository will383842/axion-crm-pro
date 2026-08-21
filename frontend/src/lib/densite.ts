/**
 * DENSITÉ D'AFFICHAGE — constat D26-003, onglet « Apparence ».
 *
 * ═══ CE QU'IL Y AVAIT AVANT ═══
 *
 *     const [density, setDensity] = useState<'comfortable' | 'compact'>('comfortable');
 *
 * Un `useState` local à `SettingsPage`. Il pilotait la COULEUR de ses deux
 * propres boutons, et rien d'autre : aucune table n'était plus dense, rien
 * n'était persisté, et le choix mourait au changement d'onglet. L'exploitant
 * croyait régler l'espacement de ses listes longues ; il repeignait un bouton.
 *
 * ═══ CE QUE CE MODULE FAIT ═══
 *
 * Le réglage devient un ATTRIBUT SUR `<html>` (`data-density`), que
 * `src/styles/index.css` consulte pour resserrer les cellules de tableau, et
 * une entrée de `localStorage` relue à l'amorçage (`main.tsx`). C'est le même
 * patron que le thème (`DarkModeToggle`), qui est justement la seule commande
 * de l'onglet qui marchait.
 *
 * ⚠️ On écrit l'attribut MÊME pour « comfortable ». Un attribut absent ne se
 * distingue pas d'un module jamais chargé : la garde
 * `SettingsPage.commandes-mortes.test.tsx` lit cet attribut, et son témoin
 * exige de voir la valeur par défaut, pas `null`.
 */
export type Densite = 'comfortable' | 'compact';

export const CLE_DENSITE = 'axion-crm:density';

export const DENSITE_PAR_DEFAUT: Densite = 'comfortable';

/** L'attribut lu par la feuille de style. Exporté pour que la garde ne le devine pas. */
export const ATTRIBUT_DENSITE = 'data-density';

function estDensite(valeur: string | null): valeur is Densite {
  return valeur === 'comfortable' || valeur === 'compact';
}

/**
 * Le choix persisté, ou le défaut.
 *
 * `try/catch` : `localStorage` lève en navigation privée Safari et derrière
 * certaines politiques d'entreprise. Un réglage d'apparence ne doit pas être
 * capable de faire tomber l'écran.
 */
export function lireDensite(): Densite {
  try {
    const brut = window.localStorage.getItem(CLE_DENSITE);
    return estDensite(brut) ? brut : DENSITE_PAR_DEFAUT;
  } catch {
    return DENSITE_PAR_DEFAUT;
  }
}

/** Applique la densité au document ET la persiste. Les deux, ou le réglage ment à nouveau. */
export function appliquerDensite(densite: Densite): void {
  if (typeof document !== 'undefined') {
    document.documentElement.setAttribute(ATTRIBUT_DENSITE, densite);
  }
  try {
    window.localStorage.setItem(CLE_DENSITE, densite);
  } catch {
    /* le document porte quand même le réglage pour la session en cours */
  }
}
