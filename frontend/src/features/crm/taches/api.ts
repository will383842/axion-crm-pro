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
    onSettled: () => client.invalidateQueries({ queryKey: CLE_TACHES }),
  });
}
