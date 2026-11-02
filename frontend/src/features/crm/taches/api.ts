import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { NouvelleTache, ReponseTaches, Tache } from './types';

export const CLE_TACHES = ['crm', 'taches'] as const;

export function useTaches() {
  return useQuery({
    queryKey: CLE_TACHES,
    queryFn: async ({ signal }) => (await api.get<ReponseTaches>('/crm/taches', { signal })).data,
  });
}

export function useCocherTache() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, faite }: { id: string; faite: boolean }) => (await api.patch<Tache>(`/crm/taches/${id}`, { faite })).data,
    // La case réagit tout de suite ; le serveur confirme ensuite (et corrige si besoin).
    onMutate: async ({ id, faite }) => {
      await client.cancelQueries({ queryKey: CLE_TACHES });
      client.setQueryData<ReponseTaches>(CLE_TACHES, (avant) =>
        avant ? { ...avant, data: avant.data.map((t) => (t.id === id ? { ...t, faite, faite_le: faite ? new Date(avant.aujourdhui).toISOString() : null } : t)) } : avant,
      );
    },
    onSettled: () => client.invalidateQueries({ queryKey: CLE_TACHES }),
  });
}

export function useAjouterTache() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: async (tache: NouvelleTache) => (await api.post<Tache>('/crm/taches', tache)).data,
    onSettled: () => client.invalidateQueries({ queryKey: CLE_TACHES }),
  });
}

export function useRappelMatin() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: async (actif: boolean) => (await api.put<{ actif: boolean }>('/crm/taches/rappel-matin', { actif })).data,
    onMutate: async (actif) => {
      await client.cancelQueries({ queryKey: CLE_TACHES });
      client.setQueryData<ReponseTaches>(CLE_TACHES, (avant) => (avant ? { ...avant, rappel_matin: actif } : avant));
    },
    onSettled: () => client.invalidateQueries({ queryKey: CLE_TACHES }),
  });
}
