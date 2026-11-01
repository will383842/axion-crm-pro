/**
 * DM0-COQ — GARDE DE ROUTE PAR PRODUIT (`beforeLoad`).
 *
 *     createRoute({ …, path: '/envoi/campagnes', beforeLoad: exigerProduit('envoi') })
 *
 * Produit absent pour l'espace, ou utilisateur sans accès : la route répond
 * « Page introuvable », sans indice (ADR-12). C'est le cas de TOUTES les routes
 * d'Envoi en production tant que la console n'a pas posé `produits.envoi`.
 *
 * FERMÉ PAR DÉFAUT : si la lecture des droits échoue, ou si le routeur n'a pas
 * de `queryClient` dans son contexte, la route est introuvable. Une garde dont
 * l'état inconnu vaut « ouvert » n'est pas une garde.
 *
 * Les deux lectures passent par le cache React Query, sous les MÊMES clés que
 * la coquille (`['crm', 'features']`, `['auth', 'me']`) : aucune requête de
 * plus quand la coquille les a déjà faites.
 */
import { notFound } from '@tanstack/react-router';
import type { QueryClient } from '@tanstack/react-query';
import {
  CONSOLE_FEATURES_KEY,
  lireConsoleFeatures,
  produitsDe,
  type ConsoleFeatures,
} from '@/features/crm-console/useConsoleFeatures';
import { CLE_ME, lireMe, produitVisible, type MeAccesProduits } from './produits/useProduits';
import type { EspaceProduit } from './produit/dernierePage';

const CINQ_MINUTES = 5 * 60 * 1000;

/** Le produit est-il ouvert à cet utilisateur ? Toute erreur vaut « non ». */
export async function produitOuvert(queryClient: QueryClient | undefined, produit: EspaceProduit): Promise<boolean> {
  if (queryClient === undefined) return false;
  try {
    const features = await queryClient.fetchQuery<ConsoleFeatures>({
      queryKey: CONSOLE_FEATURES_KEY,
      queryFn: lireConsoleFeatures,
      staleTime: CINQ_MINUTES,
      retry: false,
    });
    const produits = produitsDe(features);
    if (produits[produit] === 'absent') return false;
    const me = await queryClient.fetchQuery<MeAccesProduits>({
      queryKey: CLE_ME,
      queryFn: lireMe,
      staleTime: CINQ_MINUTES,
      retry: false,
    });
    return produitVisible(produits, me, produit);
  } catch {
    return false;
  }
}

/** Fabrique le `beforeLoad` d'une route réservée à un produit. */
export function exigerProduit(produit: EspaceProduit) {
  return async ({ context }: { context: unknown }): Promise<void> => {
    const queryClient = (context as { queryClient?: QueryClient } | undefined)?.queryClient;
    if (!(await produitOuvert(queryClient, produit))) {
      // `notFound()` n'est pas une Error : c'est l'objet de contrôle que le
      // routeur intercepte, et `throw` est la forme prescrite par la librairie.
      // eslint-disable-next-line @typescript-eslint/only-throw-error
      throw notFound();
    }
  };
}
