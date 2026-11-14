/**
 * Drapeau de la console CRM v2 — lu au RUNTIME, jamais au build.
 *
 * Une variable `VITE_*` est figée au moment du `vite build` : activer la console
 * supposerait alors de reconstruire et redéployer le frontend, donc de ne PAS
 * pouvoir revenir en arrière en une minute. Le drapeau vit côté API
 * (`CRM_CONSOLE_V2_ENABLED`), et c'est la MÊME poignée que celle qui ouvre les
 * routes : il ne peut pas y avoir de désaccord entre ce que l'interface propose
 * et ce que l'API accepte.
 *
 * `universes` dit ce qu'on a le droit d'AFFICHER : une entrée de navigation qui
 * mène à un 403 ne doit pas exister. L'étanchéité se lit dans la navigation
 * elle-même (conception §2.2), elle ne se découvre pas au clic.
 */
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

/**
 * DM0-COQ (ADR-12 du plan 29) — droit d'un espace à un produit, posé par la
 * console des automatisations. `suspendu` : envois en pause, lecture gardée ;
 * `resilie` : lecture seule ; `absent` : le produit n'existe pas pour l'espace.
 */
export type EtatProduit = 'actif' | 'suspendu' | 'resilie' | 'absent';

export interface ProduitsEspace {
  crm: EtatProduit;
  envoi: EtatProduit;
}

export interface ConsoleFeatures {
  console_v2: boolean;
  universes: { business: boolean; vivier: boolean };
  /**
   * DM0-COQ — droits par produit, publiés par `/config/features` (D3, contrat `Features.produits`).
   * Optionnel côté type par prudence (ancien serveur) : ABSENT = `{ crm: 'actif', envoi: 'absent' }`,
   * le comportement sans rien d'Envoi (voir `PRODUITS_PAR_DEFAUT`, `produitsDe`).
   */
  produits?: ProduitsEspace;
}

/** Sans `produits` côté serveur : le CRM seul, comme aujourd'hui. */
export const PRODUITS_PAR_DEFAUT: ProduitsEspace = { crm: 'actif', envoi: 'absent' };

const ETATS_PRODUIT: ReadonlySet<string> = new Set(['actif', 'suspendu', 'resilie', 'absent']);

/**
 * Les droits par produit d'une réponse de `/config/features`. Une valeur
 * inconnue vaut le défaut du produit : un état qu'on ne sait pas lire ne doit
 * JAMAIS ouvrir Envoi.
 */
export function produitsDe(features: ConsoleFeatures | undefined): ProduitsEspace {
  const brut = features?.produits;
  const lire = (cle: keyof ProduitsEspace): EtatProduit => {
    const v: unknown = brut?.[cle];
    return typeof v === 'string' && ETATS_PRODUIT.has(v) ? (v as EtatProduit) : PRODUITS_PAR_DEFAUT[cle];
  };
  return { crm: lire('crm'), envoi: lire('envoi') };
}

export const CONSOLE_FEATURES_CLOSED: ConsoleFeatures = {
  console_v2: false,
  universes: { business: false, vivier: false },
};

export const CONSOLE_FEATURES_KEY = ['crm', 'features'] as const;

/** La lecture elle-même — partagée par le hook et par la garde de route `exigerProduit`. */
export async function lireConsoleFeatures(): Promise<ConsoleFeatures> {
  return (await api.get<ConsoleFeatures>('/config/features')).data;
}

/**
 * La requête brute — exposée pour distinguer « on SAIT que c'est fermé » de
 * « on ne sait pas ENCORE ». Les deux ferment la console, mais on ne dit pas
 * la même chose à l'écran (cf. `ConsoleGate`).
 */
export function useConsoleFeaturesQuery() {
  return useQuery<ConsoleFeatures>({
    queryKey: CONSOLE_FEATURES_KEY,
    queryFn: lireConsoleFeatures,
    // Pas de réessai : si l'endpoint n'existe pas encore sur ce serveur (image
    // antérieure au lot L6), la bonne réponse est « console fermée », tout de
    // suite, pas trois requêtes en échec avant de conclure la même chose.
    retry: false,
    staleTime: 5 * 60 * 1000,
  });
}

export function useConsoleFeatures(): ConsoleFeatures {
  const { data } = useConsoleFeaturesQuery();

  // FERMÉ par défaut : tant qu'on ne sait pas, on n'affiche rien. Un drapeau
  // dont l'état inconnu vaut « ouvert » n'est pas un drapeau.
  return data ?? CONSOLE_FEATURES_CLOSED;
}
