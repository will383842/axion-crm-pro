/** Appels d'API et mutations des écrans de réponses (contrat : `backend/openapi/envoi/reponses.yaml`). */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { components } from '@/features/envoi/api/reponses';
import type { components as communs } from '@/features/envoi/api/commun';

export type ReponseResume = communs['schemas']['ReponseResume'];
export type ReponseApresGeste = components['schemas']['ReponseApresGeste'];
export type MessageFil = components['schemas']['MessageFil'];
export type SuggestionRattachement = components['schemas']['SuggestionRattachement'];
export type ClassementReponse = communs['schemas']['ClassementReponse'];

export type FileReponses = 'a_traiter' | 'a_rattacher' | 'traitees';

export interface ListeReponses {
  data: ReponseResume[];
  par_file: Record<FileReponses, number>;
}

export interface DetailReponse {
  reponse: ReponseResume;
  fil: MessageFil[];
  suggestions_rattachement: SuggestionRattachement[];
}

export interface Raccourcis {
  data: Array<{ id: string; nom: string; corps: string }>;
  collegues: Array<{ id: string; nom: string }>;
}

const RACINE = ['envoi', 'reponses'] as const;

export function useListeReponses(file: FileReponses, q = '', classement = '') {
  return useQuery({
    queryKey: [...RACINE, 'liste', file, q, classement],
    queryFn: async () => {
      const { data } = await api.get<ListeReponses>('/envoi/reponses', {
        params: { file, par_page: 100, ...(q !== '' ? { q } : {}), ...(classement !== '' ? { classement } : {}) },
      });
      return data;
    },
  });
}

export function useDetailReponse(id: string | null) {
  return useQuery({
    queryKey: [...RACINE, 'detail', id],
    enabled: id !== null,
    queryFn: async () => {
      const { data } = await api.get<DetailReponse>(`/envoi/reponses/${String(id)}`);
      return data;
    },
  });
}

export function useRaccourcis() {
  return useQuery({
    queryKey: [...RACINE, 'raccourcis'],
    staleTime: 5 * 60_000,
    queryFn: async () => (await api.get<Raccourcis>('/envoi/reponses/raccourcis')).data,
  });
}

function useGeste<V>(appel: (v: V) => Promise<ReponseApresGeste>) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: appel,
    // Sans `return` : la mutation n’attend pas la relecture de la file. Les
    // rappels passés à `mutate(...)` partent alors AVANT que la réponse traitée
    // quitte la liste (et démonte l'écran qui les a posés).
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: RACINE });
    },
  });
}

export function useClasser() {
  return useGeste(async (v: { id: string; classement: ClassementReponse; creer_tache?: boolean; toute_organisation?: boolean }) => {
    const { id, ...corps } = v;
    return (await api.post<ReponseApresGeste>(`/envoi/reponses/${id}/classer`, corps)).data;
  });
}

export function useRepondre() {
  return useGeste(async (v: { id: string; corps: string; raccourci_id?: string; attribuer_a?: string | null }) => {
    const { id, ...corps } = v;
    return (await api.post<ReponseApresGeste>(`/envoi/reponses/${id}/repondre`, corps)).data;
  });
}

export function useRattacher() {
  return useGeste(async (v: { id: string; type: 'contact' | 'entreprise' | 'carnet'; reference: string }) => {
    const { id, ...corps } = v;
    return (await api.post<ReponseApresGeste>(`/envoi/reponses/${id}/rattacher`, corps)).data;
  });
}

export function useCreerFiche() {
  return useGeste(async (v: { id: string; prenom: string; nom: string; organisation: string | null; provenance: string }) => {
    const { id, ...corps } = v;
    return (await api.post<ReponseApresGeste>(`/envoi/reponses/${id}/creer-fiche`, corps)).data;
  });
}
