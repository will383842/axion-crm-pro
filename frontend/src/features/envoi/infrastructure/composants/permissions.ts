/**
 * L8-F10 — droits de gestion des boîtes. Le serveur reste l'autorité (403 sinon) ;
 * l'écran ne fait que griser le geste, avec la raison. Source : `/auth/me`.
 */
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { permissionsDe } from '@/features/origines/droits';

/** Suspendre une boîte. */
export const PERMISSION_SUSPENDRE = 'users.manage';
/** Réactiver une boîte suspendue. */
export const PERMISSION_REACTIVER = 'workspaces.manage';

export interface DroitsBoites {
  charges: boolean;
  /** `/auth/me` a échoué : les droits sont INCONNUS (ce n'est pas une permission manquante). */
  erreur: boolean;
  suspendre: boolean;
  reactiver: boolean;
}

/** Phrase à afficher quand un geste est grisé : connexion en cause, ou permission réellement absente. */
export function messageDroits(droits: Pick<DroitsBoites, 'erreur'>, permission: string): string {
  return droits.erreur
    ? 'Vos droits n’ont pas pu être vérifiés (connexion interrompue). Rechargez la page.'
    : `Il vous manque la permission ${permission}.`;
}

export function useDroitsBoites(): DroitsBoites {
  const { data, isPending, isError } = useQuery({
    queryKey: ['auth', 'me'],
    queryFn: async () => (await api.get<{ roles?: string[]; permissions?: string[] }>('/auth/me')).data,
    retry: false,
    staleTime: 5 * 60 * 1000,
  });
  const p = permissionsDe(data);
  return { charges: !isPending, erreur: isError, suspendre: p.has(PERMISSION_SUSPENDRE), reactiver: p.has(PERMISSION_REACTIVER) };
}
