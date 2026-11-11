/**
 * DM2-CAM — appels d'API des campagnes d'Envoi (contrat : `backend/openapi/envoi/campagnes.yaml`).
 * Les mêmes appels servent en démo (faux serveur dans le navigateur) et, plus
 * tard, avec le vrai serveur : aucun écran ne change.
 */
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { components, operations } from '@/features/envoi/api/campagnes';

export type Campagne = components['schemas']['CampagneResume'];
export type Destinataire = components['schemas']['Destinataire'];
export type EtatDestinataire = components['schemas']['EtatDestinataire'];
export type StatutCampagne = components['schemas']['StatutCampagne'];
export type EvenementEnvoi = components['schemas']['EvenementEnvoi'];

type Reponse<K extends keyof operations> = operations[K] extends { responses: { 200: { content: { 'application/json': infer R } } } } ? R : never;

export type ListeCampagnes = Reponse<'listerCampagnes'>;
export type FicheCampagne = Reponse<'lireCampagne'>;
export type Calendrier = Reponse<'calendrierCampagnes'>;
export type PageDestinataires = Reponse<'listerDestinatairesCampagne'>;

export type GroupeDestinataires = 'en_file' | 'partis' | 'refuses' | 'ont_repondu' | 'arretes' | 'exclus';

export const CLE = ['envoi', 'campagnes'] as const;

export async function listerCampagnes(q: string): Promise<ListeCampagnes> {
  // Toutes les pages (200 par page) : une liste de plus de 200 campagnes ne doit pas être coupée en silence.
  const recherche = q.trim() !== '' ? { q: q.trim() } : {};
  const premiere = (await api.get<ListeCampagnes>('/envoi/campagnes', { params: { par_page: 200, page: 1, ...recherche } })).data;
  const data = [...premiere.data];
  for (let page = 2; page <= Math.min(premiere.meta.pages, 25); page += 1) {
    data.push(...(await api.get<ListeCampagnes>('/envoi/campagnes', { params: { par_page: 200, page, ...recherche } })).data.data);
  }
  return { ...premiere, data };
}

export async function lireCampagne(id: string): Promise<FicheCampagne> {
  return (await api.get<FicheCampagne>(`/envoi/campagnes/${encodeURIComponent(id)}`)).data;
}

export async function lireCalendrier(du: string | null, au: string | null): Promise<Calendrier> {
  const params = du !== null && au !== null ? { du, au } : {};
  return (await api.get<Calendrier>('/envoi/campagnes/calendrier', { params })).data;
}

export interface ParamsDestinataires {
  groupe: GroupeDestinataires | null;
  q: string;
  page: number;
}

export async function listerDestinataires(id: string, p: ParamsDestinataires): Promise<PageDestinataires> {
  const params: Record<string, string | number> = { page: p.page, par_page: 25 };
  if (p.groupe !== null) params['groupe'] = p.groupe;
  if (p.q.trim() !== '') params['q'] = p.q.trim();
  return (await api.get<PageDestinataires>(`/envoi/campagnes/${encodeURIComponent(id)}/destinataires`, { params })).data;
}

export function useCampagnes(q: string) {
  return useQuery({ queryKey: [...CLE, 'liste', q], queryFn: () => listerCampagnes(q), placeholderData: keepPreviousData });
}

export function useCampagne(id: string) {
  return useQuery({ queryKey: [...CLE, 'fiche', id], queryFn: () => lireCampagne(id), retry: false });
}

export function useCalendrier(du: string | null, au: string | null) {
  return useQuery({ queryKey: [...CLE, 'calendrier', du, au], queryFn: () => lireCalendrier(du, au), placeholderData: keepPreviousData });
}

export function useDestinataires(id: string, params: ParamsDestinataires, actif: boolean) {
  return useQuery({
    queryKey: [...CLE, 'destinataires', id, params],
    queryFn: () => listerDestinataires(id, params),
    enabled: actif,
    placeholderData: keepPreviousData,
  });
}

export interface CorpsDuplication {
  nom: string;
  garder_cible: boolean;
  garder_messages: boolean;
  exclure_deja_recus: boolean;
}

/** Les cinq gestes sur une campagne. Chacun rafraîchit tout ce qui touche aux campagnes. */
export function useGestesCampagne() {
  const client = useQueryClient();
  const apres = () => client.invalidateQueries({ queryKey: ['envoi'] });
  const poster = async <T,>(chemin: string, corps?: unknown): Promise<T> => (await api.post<T>(chemin, corps)).data;
  return {
    pause: useMutation({ mutationFn: (id: string) => poster<Campagne>(`/envoi/campagnes/${encodeURIComponent(id)}/pause`), onSuccess: apres }),
    reprise: useMutation({ mutationFn: (id: string) => poster<Campagne>(`/envoi/campagnes/${encodeURIComponent(id)}/reprise`), onSuccess: apres }),
    terminer: useMutation({ mutationFn: (id: string) => poster<Campagne>(`/envoi/campagnes/${encodeURIComponent(id)}/terminer`), onSuccess: apres }),
    dupliquer: useMutation({
      mutationFn: ({ id, corps }: { id: string; corps: CorpsDuplication }) => poster<Campagne>(`/envoi/campagnes/${encodeURIComponent(id)}/dupliquer`, corps),
      onSuccess: apres,
    }),
    date: useMutation({
      mutationFn: ({ id, le }: { id: string; le: string }) => poster<Campagne>(`/envoi/campagnes/${encodeURIComponent(id)}/date`, { le }),
      onSuccess: apres,
    }),
  };
}
