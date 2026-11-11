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

export function useDroitsBoites(): { suspendre: boolean; reactiver: boolean } {
  const { data } = useQuery({
    queryKey: ['auth', 'me'],
    queryFn: async () => (await api.get<{ roles?: string[]; permissions?: string[] }>('/auth/me')).data,
    retry: false,
    staleTime: 5 * 60 * 1000,
  });
  const p = permissionsDe(data);
  return { suspendre: p.has(PERMISSION_SUSPENDRE), reactiver: p.has(PERMISSION_REACTIVER) };
}
