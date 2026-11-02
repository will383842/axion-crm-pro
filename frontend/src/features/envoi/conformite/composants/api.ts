/**
 * Appels d'API de la zone « conformité » (contrat : `backend/openapi/envoi/conformite.yaml`).
 * Un seul fichier d'accès : les écrans n'écrivent jamais une adresse d'API.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { components, operations } from '@/features/envoi/api/conformite';
import type { components as commun } from '@/features/envoi/api/commun';

type S = components['schemas'];
type C = commun['schemas'];

export type Opposition = C['Opposition'];
export type RegleAlerte = C['RegleAlerte'];
export type AlerteEnvoi = S['AlerteEnvoi'];
export type ReglagesAlertes = S['ReglagesAlertes'];
export type ReglesPrudence = S['Regles'];
export type Identite = S['Identite'];
export type ConnexionCrm = S['ConnexionCrm'];
export type ApercuReglages = S['ApercuReglages'];
export type ModeAbsence = S['ModeAbsence'];
export type EntreeCarnet = S['EntreeCarnet'];
export type PuisJeEcrire = operations['puisJeEcrire']['responses'][200]['content']['application/json'];
export type PageDesinscription = operations['lirePageDesinscription']['responses'][200]['content']['application/json'];
export type RegistreOpposition = operations['listerOpposition']['responses'][200]['content']['application/json'];

export const CLES = {
  opposition: (q: string) => ['envoi', 'opposition', q] as const,
  alertes: ['envoi', 'alertes'] as const,
  reglesAlertes: ['envoi', 'reglages', 'alertes'] as const,
  apercu: ['envoi', 'reglages', 'apercu'] as const,
  regles: ['envoi', 'reglages', 'regles'] as const,
  identite: ['envoi', 'reglages', 'identite'] as const,
  connexionCrm: ['envoi', 'reglages', 'connexion-crm'] as const,
  carnet: (q: string) => ['envoi', 'carnet', q] as const,
  publique: (jeton: string) => ['envoi', 'public', 'desinscription', jeton] as const,
};

export function useRegistre(q: string) {
  return useQuery({
    queryKey: CLES.opposition(q),
    queryFn: async () => (await api.get<RegistreOpposition>('/envoi/opposition', { params: { q, par_page: 50 } })).data,
  });
}

export function useInscrireOpposition() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: async (corps: { portee: Opposition['portee']; valeur: string; motif: string }) =>
      (await api.post<Opposition>('/envoi/opposition', corps)).data,
    onSuccess: () => client.invalidateQueries({ queryKey: ['envoi', 'opposition'] }),
  });
}

export async function demanderPuisJeEcrire(adresse: string): Promise<PuisJeEcrire> {
  return (await api.get<PuisJeEcrire>('/envoi/puis-je-ecrire', { params: { adresse } })).data;
}

/** Télécharge le registre en CSV (preuve) : l'export est produit côté serveur. */
export async function exporterRegistre(): Promise<void> {
  const reponse = await api.get<string>('/envoi/opposition/export', { responseType: 'text' });
  const fichier = new Blob(['﻿', reponse.data], { type: 'text/csv;charset=utf-8' });
  const adresse = URL.createObjectURL(fichier);
  const lien = document.createElement('a');
  lien.href = adresse;
  lien.download = 'registre-opposition.csv';
  document.body.appendChild(lien);
  lien.click();
  lien.remove();
  URL.revokeObjectURL(adresse);
}

export function useAlertes() {
  return useQuery({ queryKey: CLES.alertes, queryFn: async () => (await api.get<{ data: AlerteEnvoi[] }>('/envoi/alertes')).data.data });
}

export function useRegler() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: async (id: string) => (await api.post<AlerteEnvoi>(`/envoi/alertes/${id}/regler`)).data,
    onSuccess: () => client.invalidateQueries({ queryKey: CLES.alertes }),
  });
}

export function useReglesAlertes() {
  return useQuery({ queryKey: CLES.reglesAlertes, queryFn: async () => (await api.get<ReglagesAlertes>('/envoi/reglages/alertes')).data });
}

export function useEnregistrerReglesAlertes() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: async (corps: ReglagesAlertes) => (await api.put<ReglagesAlertes>('/envoi/reglages/alertes', corps)).data,
    onSuccess: (donnees) => client.setQueryData(CLES.reglesAlertes, donnees),
  });
}

export function useAlerteDeTest() {
  return useMutation({ mutationFn: async () => (await api.post<unknown>('/envoi/reglages/alertes/test')).data });
}

export function useApercu() {
  return useQuery({ queryKey: CLES.apercu, queryFn: async () => (await api.get<ApercuReglages>('/envoi/reglages/apercu')).data });
}

export function useModeAbsence() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: async (corps: ModeAbsence) => (await api.put<ModeAbsence>('/envoi/reglages/absence', corps)).data,
    onSuccess: () => client.invalidateQueries({ queryKey: CLES.apercu }),
  });
}

export function useRegles() {
  return useQuery({ queryKey: CLES.regles, queryFn: async () => (await api.get<ReglesPrudence>('/envoi/reglages/regles')).data });
}

export function useEnregistrerRegles() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: async (corps: ReglesPrudence) => (await api.put<ReglesPrudence>('/envoi/reglages/regles', corps)).data,
    onSuccess: (donnees) => {
      client.setQueryData(CLES.regles, donnees);
      void client.invalidateQueries({ queryKey: CLES.apercu });
    },
  });
}

export function useIdentite() {
  return useQuery({ queryKey: CLES.identite, queryFn: async () => (await api.get<Identite>('/envoi/reglages/identite')).data });
}

export function useEnregistrerIdentite() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: async (corps: Identite) => (await api.put<Identite>('/envoi/reglages/identite', corps)).data,
    onSuccess: (donnees) => client.setQueryData(CLES.identite, donnees),
  });
}

export function useConnexionCrm() {
  return useQuery({ queryKey: CLES.connexionCrm, queryFn: async () => (await api.get<ConnexionCrm>('/envoi/reglages/connexion-crm')).data });
}

export function useTesterConnexionCrm() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: async () => (await api.post<ConnexionCrm>('/envoi/reglages/connexion-crm/tester')).data,
    onSuccess: (donnees) => client.setQueryData(CLES.connexionCrm, donnees),
  });
}

export function useCarnet(q: string) {
  return useQuery({
    queryKey: CLES.carnet(q),
    queryFn: async () =>
      (await api.get<{ data: EntreeCarnet[]; meta: { total: number } }>('/envoi/carnet', { params: { q, par_page: 50 } })).data,
  });
}

export function useAjouterAuCarnet() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: async (corps: { adresse: string; prenom?: string; nom?: string; organisation?: string }) =>
      (await api.post<EntreeCarnet>('/envoi/carnet', corps)).data,
    onSuccess: () => client.invalidateQueries({ queryKey: ['envoi', 'carnet'] }),
  });
}

export function usePageDesinscription(jeton: string) {
  return useQuery({
    queryKey: CLES.publique(jeton),
    retry: false,
    queryFn: async () => (await api.get<PageDesinscription>(`/public/desinscription/${encodeURIComponent(jeton)}`)).data,
  });
}

export function useConfirmerDesinscription(jeton: string) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: async (toute_l_organisation: boolean) =>
      (await api.post<unknown>(`/public/desinscription/${encodeURIComponent(jeton)}`, { toute_l_organisation })).data,
    onSuccess: () => client.invalidateQueries({ queryKey: CLES.publique(jeton) }),
  });
}
