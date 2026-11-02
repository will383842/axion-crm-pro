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
  const mutation = useMutation({
    mutationFn: async ({ id, faite }: { id: string; faite: boolean }) => (await api.patch<Tache>(`/crm/taches/${id}`, { faite })).data,
    onSettled: () => client.invalidateQueries({ queryKey: CLE_TACHES }),
  });
  return {
    ...mutation,
    /**
     * La case réagit TOUT DE SUITE : le cache est modifié dans le geste même
     * (une case contrôlée qui attendrait une micro-tâche reviendrait en arrière
     * le temps d'un rendu) ; le serveur confirme ensuite et corrige si besoin.
     */
    cocher: (id: string, faite: boolean) => {
      void client.cancelQueries({ queryKey: CLE_TACHES });
      client.setQueryData<ReponseTaches>(CLE_TACHES, (avant) =>
        avant ? { ...avant, data: avant.data.map((t) => (t.id === id ? { ...t, faite, faite_le: faite ? `${avant.aujourdhui}T12:00:00.000Z` : null } : t)) } : avant,
      );
      mutation.mutate({ id, faite });
    },
  };
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
  const mutation = useMutation({
    mutationFn: async (actif: boolean) => (await api.put<{ actif: boolean }>('/crm/taches/rappel-matin', { actif })).data,
    onSettled: () => client.invalidateQueries({ queryKey: CLE_TACHES }),
  });
  return {
    ...mutation,
    basculer: (actif: boolean) => {
      void client.cancelQueries({ queryKey: CLE_TACHES });
      client.setQueryData<ReponseTaches>(CLE_TACHES, (avant) => (avant ? { ...avant, rappel_matin: actif } : avant));
      mutation.mutate(actif);
    },
  };
}
