/**
 * LA LISTE DES AUDIENCES — une seule requête, partagée par l'écran Audiences
 * et par le bloc « Mes audiences » de l'accueil (03/10/2026).
 *
 * Même clé de cache, même fonction : l'accueil réutilise la réponse déjà
 * chargée par l'écran (et inversement), sans second appel ni seconde forme.
 */
import { api } from '@/lib/api';
import type { EmailAudience } from './AudiencesListPage';

export interface AudiencesListResponse {
  data: EmailAudience[];
  /**
   * `true` quand le serveur n'a PAS PU lire les audiences (il rend alors une
   * liste vide) : ce n'est pas « aucune audience ».
   */
  degraded?: boolean;
}

export const AUDIENCES_KEY = ['audiences'] as const;

export async function chargerAudiences(): Promise<AudiencesListResponse> {
  return (await api.get<AudiencesListResponse>('/audiences')).data;
}
