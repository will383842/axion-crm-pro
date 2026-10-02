/**
 * THÈME — la console est TOUJOURS en clair.
 *
 * Décision permanente du propriétaire : pas de mode sombre sur la console
 * (audit UX du 02/10/2026, P1-9). Le sélecteur à trois boutons (☀ ⚙ ☾) de
 * l'en-tête et la carte « Thème » des Paramètres ont été retirés.
 *
 * Retirer le sélecteur ne suffisait pas : un navigateur qui avait déjà choisi
 * « sombre » garde `axion-theme=dark` dans son stockage local, et un ancien
 * onglet a pu laisser la classe `dark` sur `<html>`. Cette fonction, appelée à
 * l'amorçage (`main.tsx`), efface les deux et pose `data-theme="light"`.
 *
 * Les classes `dark:` des composants restent dans le code : elles ne
 * s'appliquent que sous `html.dark` (`@variant dark` de `styles/index.css`),
 * qui n'est plus jamais posée. Les retirer serait un très gros diff sans effet
 * visible.
 */
export const CLE_THEME_HISTORIQUE = 'axion-theme';

export function forcerThemeClair(racine: HTMLElement = document.documentElement): void {
  racine.classList.remove('dark');
  racine.setAttribute('data-theme', 'light');
  racine.style.colorScheme = 'light';
  try {
    window.localStorage.removeItem(CLE_THEME_HISTORIQUE);
  } catch {
    // Stockage indisponible (navigation privée stricte) : rien à effacer.
  }
}
