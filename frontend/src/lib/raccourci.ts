/**
 * Libellé du raccourci de la recherche globale selon l'ordinateur.
 *
 * Le raccourci fonctionne avec Ctrl+K partout et avec Cmd+K sur Mac
 * (`GlobalSearch` écoute `metaKey || ctrlKey`). Seul l'AFFICHAGE change :
 * « ⌘K » n'est montré qu'à un utilisateur de Mac ; sur Windows et Linux, on
 * écrit « Ctrl+K », la touche qu'il a réellement sous les doigts.
 */
interface NavigatorAvecUaData {
  platform?: string;
  userAgent?: string;
  userAgentData?: { platform?: string };
}

export function estMac(nav: NavigatorAvecUaData | undefined = globalThis.navigator): boolean {
  if (!nav) return false;
  const plateforme = nav.userAgentData?.platform || nav.platform || nav.userAgent || '';
  return /mac|iphone|ipad|ipod/i.test(plateforme);
}

export function libelleRaccourciRecherche(nav?: NavigatorAvecUaData): string {
  return estMac(nav ?? globalThis.navigator) ? '⌘K' : 'Ctrl+K';
}
