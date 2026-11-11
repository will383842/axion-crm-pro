/**
 * DM2-INF — accès à l'API d'infrastructure d'Envoi (contrat :
 * `backend/openapi/envoi/infrastructure.yaml`). Un petit crochet par écran ;
 * chaque geste invalide les lectures qu'il change.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { components, operations } from '@/features/envoi/api/infrastructure';
import type { components as communComponents } from '@/features/envoi/api/commun';

export type Boite = communComponents['schemas']['BoiteResume'];
export type Domaine = communComponents['schemas']['DomaineResume'];
export type Serveur = communComponents['schemas']['ServeurResume'];
export type PolitiqueDmarc = communComponents['schemas']['PolitiqueDmarc'];
export type FluxEnvoi = communComponents['schemas']['FluxEnvoi'];
export type EtatFeu = communComponents['schemas']['EtatFeu'];
export type EvenementEnvoi = communComponents['schemas']['EvenementEnvoi'];
export type ReponseResume = communComponents['schemas']['ReponseResume'];
export type JourActivite = components['schemas']['JourActivite'];
export type EnregistrementDns = components['schemas']['EnregistrementDns'];
export type ListeNoire = components['schemas']['ListeNoire'];
export type Configuration = components['schemas']['Configuration'];
export type VersionConfiguration = components['schemas']['VersionConfiguration'];
export type ReglagesBoite = components['schemas']['ReglagesBoite'];
export type SanteServeur = components['schemas']['SanteServeur'];

type Json<K extends keyof operations> = operations[K] extends { responses: { 200: { content: { 'application/json': infer R } } } } ? R : never;
export type ListeDomaines = Json<'listerDomaines'>;
export type FicheBoite = Json<'lireBoite'>;
export type FicheDomaine = Json<'lireDomaine'>;
export type Chauffe = Json<'lireChauffe'>;
export type RessourceChauffe = Chauffe['ressources'][number];

/** Plafond d'envois par boîte et par jour en V0 (décision du responsable du projet). */
export const PLAFOND_PAR_BOITE = 150;

export const CLES = {
  boites: ['envoi', 'boites'] as const,
  boite: (id: string) => ['envoi', 'boites', id] as const,
  domaines: ['envoi', 'domaines'] as const,
  domaine: (id: string) => ['envoi', 'domaines', id] as const,
  serveurs: ['envoi', 'serveurs'] as const,
  sante: (id: string) => ['envoi', 'serveurs', id, 'sante'] as const,
  configuration: ['envoi', 'configuration'] as const,
  chauffe: ['envoi', 'chauffe'] as const,
};

const lire = async <T,>(chemin: string): Promise<T> => (await api.get<T>(chemin)).data;

export const useBoites = () => useQuery({ queryKey: CLES.boites, queryFn: async () => (await lire<{ data: Boite[] }>('/envoi/boites')).data });
export const useBoite = (id: string) => useQuery({ queryKey: CLES.boite(id), queryFn: () => lire<FicheBoite>(`/envoi/boites/${id}`) });
export const useDomaines = () => useQuery({ queryKey: CLES.domaines, queryFn: () => lire<ListeDomaines>('/envoi/domaines') });
export const useDomaine = (id: string) => useQuery({ queryKey: CLES.domaine(id), queryFn: () => lire<FicheDomaine>(`/envoi/domaines/${id}`) });
export const useServeurs = () => useQuery({ queryKey: CLES.serveurs, queryFn: async () => (await lire<{ data: Serveur[] }>('/envoi/serveurs')).data });
export const useSanteServeur = (id: string) => useQuery({ queryKey: CLES.sante(id), queryFn: () => lire<SanteServeur>(`/envoi/serveurs/${id}/sante`) });
export const useConfiguration = () => useQuery({ queryKey: CLES.configuration, queryFn: () => lire<Configuration>('/envoi/configuration') });
export const useChauffe = () => useQuery({ queryKey: CLES.chauffe, queryFn: () => lire<Chauffe>('/envoi/chauffe') });

/** Un geste : appelle l'API puis invalide tout `envoi` (les lectures sont petites). */
function useGeste<Entree, Sortie>(appel: (entree: Entree) => Promise<Sortie>) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: appel,
    onSuccess: () => client.invalidateQueries({ queryKey: ['envoi'] }),
  });
}

const mettre = async <T,>(chemin: string, corps: unknown): Promise<T> => (await api.put<T>(chemin, corps)).data;
const poster = async <T,>(chemin: string, corps?: unknown): Promise<T> => (await api.post<T>(chemin, corps)).data;

export const useSuspendreBoite = () => useGeste((id: string) => poster<Boite>(`/envoi/boites/${id}/suspendre`));
export const useReactiverBoite = () => useGeste((id: string) => poster<Boite>(`/envoi/boites/${id}/reactiver`));
export const useEnregistrerReglages = (id: string) =>
  useGeste((r: ReglagesBoite) => mettre<ReglagesBoite>(`/envoi/boites/${id}/reglages`, r));
export const useAjouterDomaine = () => useGeste((nom: string) => poster<{ domaine: Domaine; dns: EnregistrementDns[] }>('/envoi/domaines', { nom }));
export const useVerifierDns = (id: string) => useGeste((_: void) => poster<{ dns: EnregistrementDns[] }>(`/envoi/domaines/${id}/dns/verifier`));
export const useAvancerDmarc = (id: string) => useGeste((c: { politique: PolitiqueDmarc; rappel_le?: string }) => poster<Domaine>(`/envoi/domaines/${id}/dmarc`, c));
export const useAjouterIp = () =>
  useGeste((c: { serveur_id: string; adresse: string; flux: FluxEnvoi; domaine_id: string }) =>
    poster<Configuration>(`/envoi/serveurs/${c.serveur_id}/adresses-ip`, { adresse: c.adresse, flux: c.flux, domaine_id: c.domaine_id }),
  );
export const useAppliquerConfiguration = () => useGeste((_: void) => poster<Configuration>('/envoi/configuration/appliquer'));
export const useRevenirConfiguration = () => useGeste((numero: number) => poster<Configuration>(`/envoi/configuration/versions/${numero}/revenir`));
export const useLancerTest = () => useGeste((id: string) => poster<SanteServeur>(`/envoi/serveurs/${id}/test`));
export const useReculerChauffe = () => useGeste((id: string) => poster<RessourceChauffe>(`/envoi/chauffe/${id}/reculer`));
