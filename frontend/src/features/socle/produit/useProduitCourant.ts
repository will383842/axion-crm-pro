/**
 * DM0-COQ — LE PRODUIT COURANT, LU DANS L'ADRESSE (ADR-13 du plan 29).
 *
 *  - `/envoi` et tout ce qui est dessous : **Envoi** (turquoise pétrole) ;
 *  - les écrans PARTAGÉS par les deux produits : **Commun** (bandeau ardoise) —
 *    connexion et code, Mon compte et sécurité, Utilisateurs, Journal des
 *    actions, Demandes RGPD (l'écran introuvable est rendu hors coquille) ;
 *  - tout le reste : **CRM Pro** (bleu de marque).
 *
 * La racine de la coquille porte la valeur dans `data-produit` (RootLayout) :
 * les jetons `*-produit` de `styles/index.css` en découlent. Aucune couleur
 * d'identité n'est écrite en dur dans un écran.
 */
import { useRouterState } from '@tanstack/react-router';

export type Produit = 'crm' | 'envoi' | 'commun';

/** Les écrans communs (préfixes exacts : `/users` et `/users/…`). */
const ECRANS_COMMUNS: readonly string[] = [
  '/login',
  '/2fa',
  '/magic-link',
  '/password-reset',
  '/compte',
  '/users',
  '/audit-logs',
  '/rgpd/requests',
];

function sousLeChemin(chemin: string, prefixe: string): boolean {
  return chemin === prefixe || chemin.startsWith(`${prefixe}/`);
}

/** Fonction pure : le produit d'un chemin. Testée seule (`identite-produit.test.tsx`). */
export function produitDeChemin(chemin: string): Produit {
  if (sousLeChemin(chemin, '/envoi')) return 'envoi';
  if (ECRANS_COMMUNS.some((prefixe) => sousLeChemin(chemin, prefixe))) return 'commun';
  return 'crm';
}

/** Le produit de la page affichée. */
export function useProduitCourant(): Produit {
  const chemin = useRouterState({ select: (s) => s.location.pathname });
  return produitDeChemin(chemin);
}

/** Nom affiché de chaque identité (sur-titre, pastilles, marques de Ctrl K). */
export const NOM_DU_PRODUIT: Record<Produit, string> = {
  crm: 'CRM Pro',
  envoi: 'Envoi',
  commun: 'Commun',
};

/** Nom court des marques de produit (cloche, Ctrl K), comme dans les maquettes. */
export const MARQUE_DU_PRODUIT: Record<Produit, string> = {
  crm: 'CRM',
  envoi: 'Envoi',
  commun: 'Commun',
};
