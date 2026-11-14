/**
 * DM0-COQ — LES DROITS PAR PRODUIT (ADR-12 du plan 29).
 *
 *  - `useProduits()` : les droits de l'ESPACE, posés par la console et servis
 *    par `/config/features` (`produits: { crm, envoi }`). Absent du serveur =
 *    `{ crm: 'actif', envoi: 'absent' }` : le CRM d'aujourd'hui, sans Envoi.
 *  - `useAccesProduit(produit)` : l'accès de l'UTILISATEUR (case « accès à
 *    Envoi » / « accès au CRM »), lu dans `me.acces_produits`. Absent = accès.
 *
 * Côté serveur, la garde `produit-envoi` protège CHAQUE route `/envoi/*` au niveau de l'ESPACE
 * seulement : 404 dès que l'état n'est pas `actif` (suspendu et résilié compris, donc plus
 * fermé que l'affichage ci-dessous). L'accès par UTILISATEUR (`me.acces_produits`) n'est PAS
 * contrôlé côté serveur. Ces hooks ne font que décider de ce qu'on AFFICHE.
 */
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import {
  PRODUITS_PAR_DEFAUT,
  produitsDe,
  useConsoleFeatures,
  type EtatProduit,
  type ProduitsEspace,
} from '@/features/crm-console/useConsoleFeatures';
import type { EspaceProduit } from '../produit/dernierePage';

export { PRODUITS_PAR_DEFAUT, produitsDe, type EtatProduit, type ProduitsEspace };

/** Réponse de `/auth/me`, pour la seule partie lue ici. */
export interface MeAccesProduits {
  acces_produits?: Partial<Record<EspaceProduit, boolean>>;
}

export const CLE_ME = ['auth', 'me'] as const;

export async function lireMe(): Promise<MeAccesProduits> {
  return (await api.get<MeAccesProduits>('/auth/me')).data;
}

/** Un produit « présent » se montre (actif, suspendu ou résilié : lecture gardée). */
export function produitPresent(etat: EtatProduit): boolean {
  return etat !== 'absent';
}

/** L'utilisateur a-t-il accès au produit ? Absent de la réponse = oui. */
export function accesDe(me: MeAccesProduits | undefined, produit: EspaceProduit): boolean {
  return me?.acces_produits?.[produit] !== false;
}

/** Le produit est-il visible pour cet espace ET cet utilisateur ? */
export function produitVisible(produits: ProduitsEspace, me: MeAccesProduits | undefined, produit: EspaceProduit): boolean {
  return produitPresent(produits[produit]) && accesDe(me, produit);
}

export function useProduits(): ProduitsEspace {
  return produitsDe(useConsoleFeatures());
}

export function useAccesProduit(produit: EspaceProduit): boolean {
  const { data } = useQuery<MeAccesProduits>({
    queryKey: CLE_ME,
    queryFn: lireMe,
    retry: false,
    staleTime: 5 * 60 * 1000,
  });
  return accesDe(data, produit);
}

/** Ce que la coquille affiche : chaque produit, visible ou non. */
export function useProduitsVisibles(): Record<EspaceProduit, boolean> {
  const produits = useProduits();
  const crm = useAccesProduit('crm');
  const envoi = useAccesProduit('envoi');
  return {
    crm: produitPresent(produits.crm) && crm,
    envoi: produitPresent(produits.envoi) && envoi,
  };
}
