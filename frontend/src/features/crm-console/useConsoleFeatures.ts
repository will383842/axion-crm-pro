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

export interface ConsoleFeatures {
  console_v2: boolean;
  universes: { business: boolean; vivier: boolean };
}

export const CONSOLE_FEATURES_CLOSED: ConsoleFeatures = {
  console_v2: false,
  universes: { business: false, vivier: false },
};

export const CONSOLE_FEATURES_KEY = ['crm', 'features'] as const;

/**
 * La requête brute — exposée pour distinguer « on SAIT que c'est fermé » de
 * « on ne sait pas ENCORE ». Les deux ferment la console, mais on ne dit pas
 * la même chose à l'écran (cf. `ConsoleGate`).
 */
export function useConsoleFeaturesQuery() {
  return useQuery<ConsoleFeatures>({
    queryKey: CONSOLE_FEATURES_KEY,
    queryFn: async () => (await api.get<ConsoleFeatures>('/config/features')).data,
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
