import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Affaire, EtapeAffaire, ReponseAffaires } from './etapes';

export const CLE_AFFAIRES = ['crm', 'affaires'] as const;

export function useAffaires() {
  return useQuery({
    queryKey: CLE_AFFAIRES,
    queryFn: async ({ signal }) => (await api.get<ReponseAffaires>('/crm/affaires', { signal })).data,
  });
}

export function useDeplacerAffaire() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, etape, raison_perte }: { id: string; etape: EtapeAffaire; raison_perte?: string }) =>
      (await api.patch<Affaire>(`/crm/affaires/${id}`, { etape, raison_perte })).data,
    onSettled: () => client.invalidateQueries({ queryKey: CLE_AFFAIRES }),
  });
}
