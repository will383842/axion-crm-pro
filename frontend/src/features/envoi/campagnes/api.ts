/**
 * DM2-CAM — appels d'API des campagnes d'Envoi (contrat : `backend/openapi/envoi/campagnes.yaml`).
 * Les mêmes appels servent en démo (faux serveur dans le navigateur) et, plus
 * tard, avec le vrai serveur : aucun écran ne change.
 */
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import { api } from '@/lib/api';
import type { components, operations } from '@/features/envoi/api/campagnes';

export type Campagne = components['schemas']['CampagneSuivi'];
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

/** Une clé de rejeu neuve (8 à 100 caractères) : générée une fois par clic, voir `GestesCampagne`. */
export function nouvelleCleIdempotence(): string {
  return crypto.randomUUID();
}

export type PreparationCampagne = FicheCampagne['preparation'];

/**
 * Un texte français par code de refus du contrat (`campagnes.yaml`, 409 / 404 / 422) : jamais le message technique du serveur.
 * `materialisation_en_cours` dit où en est la préparation (`preparation` vient de la fiche lue par l'écran).
 */
export function texteRefus(erreur: unknown, preparation: PreparationCampagne = null): string | null {
  if (!isAxiosError(erreur)) return null;
  const code = (erreur.response?.data as { error?: unknown } | undefined)?.error;
  switch (code) {
    case 'transition_refusee': return 'Ce geste n’est pas possible dans l’état actuel de la campagne.';
    case 'sans_sequence': return 'Cette campagne n’a pas encore de séquence de messages : elle ne peut être ni lancée ni arrêtée. Renvoyez-la à valider ou dupliquez-la.';
    case 'materialisation_en_cours':
      return preparation === null
        ? 'La préparation n’est pas terminée. Réessayez quand elle sera finie.'
        : `La préparation n’est pas terminée : ${preparation.inscrits} ${preparation.inscrits > 1 ? 'personnes préparées' : 'personne préparée'} sur ${preparation.membres}. Réessayez quand elle sera finie.`;
    case 'materialisation_bloquee': return 'La préparation de la liste est bloquée : la campagne ne peut pas être lancée. Arrêtez-la ou dupliquez-la.';
    case 'aucun_destinataire': return 'Personne à écrire : tous les destinataires ont été écartés. Arrêtez la campagne.';
    case 'frein_arret_general': return 'L’arrêt général est actif : aucune campagne ne peut être lancée tant qu’il n’est pas levé.';
    case 'frein_pause_automatique': return 'Une pause automatique est active : la campagne ne peut pas être lancée tant qu’elle n’est pas levée.';
    case 'reprise_refusee_base': return 'Le lancement a été refusé par la base : rien n’a été changé.';
    case 'concurrence': return 'Une autre opération touche cette campagne : réessayez dans un instant.';
    case 'version_perimee': return 'Cette campagne a changé depuis votre lecture. Rechargez la page.';
    case 'cle_reutilisee': return 'Cette action a déjà été envoyée. Rechargez la page.';
    case 'pilotee_par_la_campagne': return 'Cette séquence appartient à une campagne : reprenez la campagne.';
    case 'non_trouve': return 'Cette campagne est introuvable dans votre espace.';
    case 'validation': return 'La demande n’a pas pu être lue. Rechargez la page puis réessayez.';
    default: return null;
  }
}

/** Les gestes sur une campagne. Chacun rafraîchit tout ce qui touche aux campagnes. */
export function useGestesCampagne() {
  const client = useQueryClient();
  const apres = () => client.invalidateQueries({ queryKey: ['envoi'] });
  const poster = async <T,>(chemin: string, corps?: unknown, cle?: string): Promise<T> =>
    (await api.post<T>(chemin, corps, cle === undefined ? undefined : { headers: { 'Idempotency-Key': cle } })).data;
  return {
    lancer: useMutation({
      mutationFn: ({ id, cle }: { id: string; cle: string }) => poster<Campagne>(`/envoi/campagnes/${encodeURIComponent(id)}/lancer`, undefined, cle),
      onSuccess: apres,
    }),
    arreter: useMutation({
      mutationFn: ({ id, cle }: { id: string; cle: string }) => poster<Campagne>(`/envoi/campagnes/${encodeURIComponent(id)}/arreter`, undefined, cle),
      onSuccess: apres,
    }),
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
