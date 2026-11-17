/**
 * Appels de l'assistant « Nouvelle campagne » et des messages types.
 * Contrat : `backend/openapi/envoi/creation.yaml` (types générés par `pnpm api:envoi`).
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import { api } from '@/lib/api';
import type { components } from '@/features/envoi/api/creation';

type S = components['schemas'];

export type Cible = S['Cible'];
export type CibleProposee = S['CibleProposee'];
export type Brouillon = S['Brouillon'];
export type ContenuEtape = S['ContenuEtape'];
export type EtapeSequence = S['EtapeSequence'];
export type Controle = S['Controle'];
export type ControleContenu = S['ControleContenu'];
export type BoiteChoix = S['BoiteChoix'];
export type ChampUtilise = S['ChampUtilise'];
export type MessageTypeEtat = S['MessageTypeEtat'];
export type BlocReutilisable = S['BlocReutilisable'];
export type DestinataireExemple = S['DestinataireExemple'];
export type SaisieMessage = S['SaisieMessage'];

export interface Estimation {
  total: number;
  exclus: number;
  restant: number;
  exclusions: Record<string, number>;
}

export const CLES = {
  cibles: (entreprise: string | null) => ['envoi', 'creation', 'cibles', entreprise] as const,
  boites: ['envoi', 'creation', 'boites'] as const,
  contexte: ['envoi', 'creation', 'contexte'] as const,
  exemples: ['envoi', 'creation', 'exemples'] as const,
  messages: ['envoi', 'messages'] as const,
  message: (id: string) => ['envoi', 'messages', id] as const,
};

// ── Lectures ──────────────────────────────────────────────────────────────

export function useCibles(entreprise: string | null) {
  return useQuery({
    queryKey: CLES.cibles(entreprise),
    queryFn: async () => (await api.get<{ data: CibleProposee[] }>('/envoi/creation/cibles', { params: entreprise === null ? {} : { entreprise } })).data.data,
  });
}

export function useBoites() {
  return useQuery({
    queryKey: CLES.boites,
    queryFn: async () => (await api.get<{ data: BoiteChoix[] }>('/envoi/creation/boites')).data.data,
  });
}

export function useContexte() {
  return useQuery({
    queryKey: CLES.contexte,
    queryFn: async () => (await api.get<{ maintenant: string; fuseau: string }>('/envoi/creation/contexte')).data,
    staleTime: 60_000,
  });
}

export function useExemples() {
  return useQuery({
    queryKey: CLES.exemples,
    queryFn: async () => (await api.get<{ data: DestinataireExemple[] }>('/envoi/creation/destinataires-exemples')).data.data,
  });
}

/** Un réseau proposé pour restreindre la cible, avec le nombre de personnes venues par lui. */
export interface ReseauPropose {
  reseau: string;
  personnes: number;
}

/** Motif du serveur pour un réseau (`FiltreOrigine`). */
export const JETON_RESEAU = /^[a-z0-9][a-z0-9._-]{0,63}$/;
export const RESEAUX_MAX = 10;

export type ReseauxOrigine = { etat: 'actif'; reseaux: ReseauPropose[] } | { etat: 'inactif' } | { etat: 'indisponible' };

/**
 * Réseaux d'origine proposables (lecture seule, `GET /crm/origines/par-reseau`).
 * 404 (écran éteint ou fonction d'espace fermée) ou `actif: false` : « inactif » —
 * le bloc n'est pas affiché. Toute autre erreur : « indisponible » (rien n'est proposé).
 * Jamais de réessai automatique : un refus ne doit pas se répéter seul.
 */
export function useReseauxOrigine() {
  return useQuery<ReseauxOrigine>({
    queryKey: ['envoi', 'creation', 'reseaux-origine'],
    retry: false,
    staleTime: 60_000,
    queryFn: async () => {
      try {
        const r = (await api.get<{ actif?: boolean; data?: Array<{ reseau: string | null; personnes: number }> }>('/crm/origines/par-reseau')).data;
        if (r.actif !== true) return { etat: 'inactif' };
        // Un réseau peut revenir sur plusieurs canaux ou comptes : on regroupe.
        const parReseau = new Map<string, number>();
        for (const l of r.data ?? []) {
          if (l.reseau === null || !JETON_RESEAU.test(l.reseau)) continue;
          parReseau.set(l.reseau, (parReseau.get(l.reseau) ?? 0) + l.personnes);
        }
        const reseaux = [...parReseau].map(([reseau, personnes]) => ({ reseau, personnes })).sort((a, b) => b.personnes - a.personnes || a.reseau.localeCompare(b.reseau));
        return { etat: 'actif', reseaux };
      } catch (e) {
        return isAxiosError(e) && e.response?.status === 404 ? { etat: 'inactif' } : { etat: 'indisponible' };
      }
    },
  });
}

/** Un mot ou un guide proposé, avec le nombre de personnes venues par lui. */
export interface ContenuPropose {
  valeur: string;
  personnes: number;
}
export type ContenuOrigine = { etat: 'actif'; valeurs: ContenuPropose[] } | { etat: 'inactif' } | { etat: 'indisponible' };

/**
 * Mots (`par-mot`) ou guides (`par-guide`) d'origine proposables (lecture seule). Mêmes portes que les réseaux :
 * 404 ou `actif: false` ⇒ « inactif » (liste absente) ; autre erreur ⇒ « indisponible » ; jamais de réessai.
 */
function useContenuOrigine(genre: 'mot' | 'guide', enabled: boolean) {
  return useQuery<ContenuOrigine>({
    enabled,
    queryKey: ['envoi', 'creation', `${genre}s-origine`],
    retry: false,
    staleTime: 60_000,
    queryFn: async () => {
      try {
        const r = (await api.get<{ actif?: boolean; data?: Array<Record<string, string | number | null>> }>(`/crm/origines/par-${genre}`)).data;
        if (r.actif !== true) return { etat: 'inactif' };
        const parValeur = new Map<string, number>();
        for (const l of r.data ?? []) {
          const v = l[genre];
          if (typeof v !== 'string' || !JETON_RESEAU.test(v)) continue;
          parValeur.set(v, (parValeur.get(v) ?? 0) + Number(l['personnes'] ?? 0));
        }
        const valeurs = [...parValeur].map(([valeur, personnes]) => ({ valeur, personnes })).sort((a, b) => b.personnes - a.personnes || a.valeur.localeCompare(b.valeur));
        return { etat: 'actif', valeurs };
      } catch (e) {
        return isAxiosError(e) && e.response?.status === 404 ? { etat: 'inactif' } : { etat: 'indisponible' };
      }
    },
  });
}

export const useMotsOrigine = (enabled = true) => useContenuOrigine('mot', enabled);
export const useGuidesOrigine = (enabled = true) => useContenuOrigine('guide', enabled);

export function useBrouillon(id: string | null) {
  return useQuery({
    queryKey: ['envoi', 'brouillon', id],
    enabled: id !== null,
    queryFn: async () => (await api.get<Brouillon>(`/envoi/brouillons/${id ?? ''}`)).data,
    staleTime: Infinity,
  });
}

export function useEstimation(brouillonId: string | null, cible: Cible | null) {
  return useQuery({
    queryKey: ['envoi', 'estimation', cible],
    enabled: cible !== null,
    placeholderData: (precedent) => precedent,
    queryFn: async () => (await api.post<Estimation>(`/envoi/brouillons/${brouillonId ?? 'nouveau'}/cible/estimation`, cible)).data,
  });
}

export function useChamps(cible: Cible | null, sequence: readonly EtapeSequence[], repli: Record<string, string>, brouillonId: string | null = null) {
  return useQuery({
    queryKey: ['envoi', 'champs', cible, sequence, repli, brouillonId],
    enabled: cible !== null && sequence.length > 0,
    placeholderData: (precedent) => precedent,
    queryFn: async () =>
      (await api.post<{ champs: ChampUtilise[]; bloquants: number }>('/envoi/creation/champs', { cible, sequence, repli, ...(brouillonId === null ? {} : { brouillon_id: brouillonId }) })).data,
  });
}

export function useControles(brouillonId: string | null, empreinte: string) {
  return useQuery({
    queryKey: ['envoi', 'controles', brouillonId, empreinte],
    enabled: brouillonId !== null,
    gcTime: 0,
    queryFn: async () => (await api.get<{ controles: Controle[]; bloquants: number }>(`/envoi/brouillons/${brouillonId ?? ''}/controles`)).data,
  });
}

export function useMessagesTypes(archives: boolean) {
  return useQuery({
    queryKey: [...CLES.messages, { archives }],
    queryFn: async () =>
      (await api.get<{ data: MessageTypeEtat[]; blocs: BlocReutilisable[] }>('/envoi/messages', { params: { archives } })).data,
  });
}

export function useMessageType(id: string | null) {
  return useQuery({
    queryKey: CLES.message(id ?? ''),
    enabled: id !== null,
    queryFn: async () => (await api.get<MessageTypeEtat>(`/envoi/messages/${id ?? ''}`)).data,
  });
}

// ── Gestes ────────────────────────────────────────────────────────────────

export async function creerBrouillon(nom: string): Promise<Brouillon> {
  return (await api.post<Brouillon>('/envoi/brouillons', { nom })).data;
}

export async function enregistrerEtape(id: string, etape: number, contenu: ContenuEtape): Promise<Brouillon> {
  return (await api.put<Brouillon>(`/envoi/brouillons/${id}/etapes/${etape}`, contenu)).data;
}

export async function envoyerTestCampagne(id: string, etape: number): Promise<string> {
  const r = await api.post<{ ok: true; message?: string }>(`/envoi/brouillons/${id}/test`, { etape });
  return r.data.message ?? 'Test envoyé à vos adresses de test.';
}

export async function demanderCode(id: string): Promise<{ envoye_a: string; expire_le: string }> {
  return (await api.post<{ envoye_a: string; expire_le: string }>(`/envoi/brouillons/${id}/validation/code`)).data;
}

export async function validerCampagne(id: string, code: string): Promise<{ campagne: { id: string; nom: string }; annulable_jusqua: string }> {
  return (await api.post<{ campagne: { id: string; nom: string }; annulable_jusqua: string }>(`/envoi/brouillons/${id}/valider`, { code })).data;
}

export async function annulerValidation(campagneId: string): Promise<Brouillon> {
  return (await api.post<Brouillon>(`/envoi/campagnes/${campagneId}/annuler-validation`)).data;
}

export function useGestesMessages() {
  const client = useQueryClient();
  const rafraichir = async () => {
    await client.invalidateQueries({ queryKey: CLES.messages });
  };
  return {
    creer: useMutation({
      mutationFn: async (s: SaisieMessage) => (await api.post<MessageTypeEtat>('/envoi/messages', s)).data,
      onSuccess: rafraichir,
    }),
    enregistrer: useMutation({
      mutationFn: async ({ id, saisie }: { id: string; saisie: SaisieMessage }) => (await api.put<MessageTypeEtat>(`/envoi/messages/${id}`, saisie)).data,
      onSuccess: rafraichir,
    }),
    restaurer: useMutation({
      mutationFn: async ({ id, numero }: { id: string; numero: number }) => (await api.post<MessageTypeEtat>(`/envoi/messages/${id}/versions/${numero}/restaurer`)).data,
      onSuccess: rafraichir,
    }),
    valider: useMutation({
      mutationFn: async ({ id, numero }: { id: string; numero: number }) => (await api.post<MessageTypeEtat>(`/envoi/messages/${id}/versions/${numero}/valider`)).data,
      onSuccess: rafraichir,
    }),
    archiver: useMutation({
      mutationFn: async ({ id, archive }: { id: string; archive: boolean }) => (await api.post<MessageTypeEtat>(`/envoi/messages/${id}/archivage`, { archive })).data,
      onSuccess: rafraichir,
    }),
  };
}

export async function controlerMessage(s: SaisieMessage): Promise<{ controles: ControleContenu[]; bloquants: number }> {
  return (await api.post<{ controles: ControleContenu[]; bloquants: number }>('/envoi/messages/controler', s)).data;
}

export async function apercuMessage(s: { objet: string; corps: string; bloc_information: boolean; destinataire_exemple_id?: string }): Promise<{ objet: string; corps: string; de: string }> {
  return (await api.post<{ objet: string; corps: string; de: string }>('/envoi/messages/apercu', s)).data;
}

export async function envoyerTestMessage(s: {
  objet: string;
  objet_b: string | null;
  corps: string;
  a_moi: boolean;
  boites_temoins: boolean;
  deux_versions: boolean;
  destinataire_exemple_id?: string;
}): Promise<number> {
  return (await api.post<{ ok: true; adresses: number }>('/envoi/messages/test', s)).data.adresses;
}

export async function suggererSequence(): Promise<{ suggestion: true; messages: Array<{ delai_jours: number; objet: string; corps: string }> }> {
  return (await api.post<{ suggestion: true; messages: Array<{ delai_jours: number; objet: string; corps: string }> }>('/envoi/messages/suggestion', {})).data;
}
