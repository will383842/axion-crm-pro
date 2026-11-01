/**
 * DM0-COQ — la dernière page ouverte de chaque produit (ADR-14 : « le
 * sélecteur garde la dernière page ouverte de chaque produit »).
 *
 * `sessionStorage` et non `localStorage` : la mémoire vaut pour l'onglet en
 * cours ; un nouvel onglet repart de l'accueil du produit. Le stockage peut
 * être refusé (navigation privée stricte) : on retombe alors sur l'accueil.
 */
import type { Produit } from './useProduitCourant';

export type EspaceProduit = Exclude<Produit, 'commun'>;

const CLE = (espace: EspaceProduit | 'espace') => `axion-crm:derniere-page:${espace}`;

/** L'accueil de chaque produit. */
export const ACCUEIL_DU_PRODUIT: Record<EspaceProduit, string> = {
  crm: '/',
  envoi: '/envoi/campagnes',
};

function lire(cle: string): string | null {
  try {
    return window.sessionStorage.getItem(cle);
  } catch {
    return null;
  }
}

function ecrire(cle: string, valeur: string): void {
  try {
    window.sessionStorage.setItem(cle, valeur);
  } catch {
    /* stockage refusé : la mémoire est perdue, rien d'autre */
  }
}

/** Retient la page ouverte (chemin + recherche) de son produit. */
export function memoriserDernierePage(espace: EspaceProduit, adresse: string): void {
  ecrire(CLE(espace), adresse);
  ecrire(CLE('espace'), espace);
}

/** La dernière page ouverte d'un produit, sinon son accueil. */
export function dernierePage(espace: EspaceProduit): string {
  const v = lire(CLE(espace));
  // Une adresse lue dans le stockage n'est pas sûre : on n'accepte qu'un
  // chemin interne du bon espace.
  if (v === null || !v.startsWith('/') || v.startsWith('//')) return ACCUEIL_DU_PRODUIT[espace];
  const estEnvoi = v === '/envoi' || v.startsWith('/envoi/') || v.startsWith('/envoi?');
  if ((espace === 'envoi') !== estEnvoi) return ACCUEIL_DU_PRODUIT[espace];
  return v;
}

/** Le dernier espace parcouru — un écran commun garde la barre de ce produit. */
export function dernierEspace(): EspaceProduit | null {
  const v = lire(CLE('espace'));
  return v === 'crm' || v === 'envoi' ? v : null;
}
