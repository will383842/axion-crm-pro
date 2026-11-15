/**
 * F-SEQ — appels d'API des séquences d'Envoi (contrat : `backend/openapi/envoi/sequences.yaml`).
 * Des compteurs seulement : aucun corps de message, aucune personne.
 * Pause : `users.manage` ; reprise : `workspaces.manage` (le serveur reste l'autorité, 403 sinon).
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { PERMISSION_GERER_ESPACE, PERMISSION_GERER_UTILISATEURS, permissionsDe } from '@/features/origines/droits';
import type { components, operations } from '@/features/envoi/api/sequences';

export type SequenceResume = components['schemas']['SequenceResume'];
export type SequenceDetail = components['schemas']['SequenceDetail'];
export type EtapeSequence = components['schemas']['EtapeSequence'];
export type StatutSequence = components['schemas']['StatutSequence'];

type Reponse<K extends keyof operations> = operations[K] extends { responses: { 200: { content: { 'application/json': infer R } } } } ? R : never;
export type ListeSequences = Reponse<'listerSequences'>;

export const CLE = ['envoi', 'sequences'] as const;

export function useSequences() {
  return useQuery({ queryKey: [...CLE, 'liste'], queryFn: async () => (await api.get<ListeSequences>('/envoi/sequences')).data });
}

export function useSequence(id: string) {
  return useQuery({
    queryKey: [...CLE, 'fiche', id],
    queryFn: async () => (await api.get<SequenceDetail>(`/envoi/sequences/${encodeURIComponent(id)}`)).data,
    retry: false,
  });
}

/** Les deux gestes. Chacun rafraîchit la liste et la fiche. */
export function useGestesSequence() {
  const client = useQueryClient();
  const apres = () => client.invalidateQueries({ queryKey: CLE });
  return {
    pause: useMutation({
      mutationFn: async (id: string) => (await api.post<SequenceDetail>(`/envoi/sequences/${encodeURIComponent(id)}/pause`)).data,
      onSuccess: apres,
    }),
    reprise: useMutation({
      mutationFn: async (id: string) => (await api.post<SequenceDetail>(`/envoi/sequences/${encodeURIComponent(id)}/reprise`)).data,
      onSuccess: apres,
    }),
  };
}

export interface DroitsSequences {
  /** Mettre en pause (`users.manage`). */
  pause: boolean;
  /** Reprendre (`workspaces.manage`). */
  reprise: boolean;
}

/** Droits lus dans `/auth/me` : tant qu'ils sont inconnus, aucun geste n'est proposé. */
export function useDroitsSequences(): DroitsSequences {
  const { data } = useQuery({
    queryKey: ['auth', 'me'],
    queryFn: async () => (await api.get<{ roles?: string[]; permissions?: string[] }>('/auth/me')).data,
    retry: false,
    staleTime: 5 * 60 * 1000,
  });
  const p = permissionsDe(data);
  return { pause: p.has(PERMISSION_GERER_UTILISATEURS), reprise: p.has(PERMISSION_GERER_ESPACE) };
}
