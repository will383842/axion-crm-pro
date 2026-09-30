/**
 * LISTES MANUELLES (2026-09-30) — types et appels partagés par les écrans.
 *
 * Une liste manuelle désigne des fiches qui EXISTENT déjà dans le CRM
 * (organisations ou personnes), cochées à la main ou rapprochées d'un
 * fichier. Rien n'y est jamais supprimé : retirer une fiche garde sa ligne
 * (`retire_le`), une liste va à la corbeille.
 */
import { api } from '@/lib/api';

export interface ListeManuelle {
  id: number;
  nom: string;
  description: string | null;
  organisations: number;
  personnes: number;
  contient: boolean;
  created_at: string | null;
  updated_at: string | null;
  deleted_at: string | null;
}

export interface MembreListe {
  id: number;
  type: 'organisation' | 'personne';
  origine: 'coche' | 'import';
  ajoute_le: string;
  company_id: number | null;
  contact_id: number | null;
  organisation_id: number | null;
  denomination: string | null;
  siren: string | null;
  email_generic: string | null;
  first_name: string | null;
  last_name: string | null;
  role: string | null;
  email: string | null;
}

export interface BilanAjout {
  ajoutes: number;
  reactives: number;
  deja_presents: number;
  introuvables: number;
  /**
   * Médias et journalistes REFUSÉS : la presse n'entre dans aucune liste tant
   * que son segment est fermé (`GardePresse` côté serveur).
   */
  presse_refusees?: number;
}

export interface BilanImport {
  lignes_lues: number;
  rapprochees: number;
  rejetees: { format_inconnu: number; introuvable: number };
  doublons_dans_le_fichier: number;
  rapprochees_a_plusieurs_fiches: number;
  par_type: { crm: number; siren: number; email_personne: number; email_organisation: number };
  organisations_retrouvees: number;
  personnes_retrouvees: number;
  /** Lignes rapprochées de la presse, que l'import refusera (segment fermé). */
  presse_refusees?: number;
  exemples_rejets: Array<{ ligne: number; motif: string }>;
  a_blanc: boolean;
  ajout?: BilanAjout;
}

export const MOTIFS_REJET: Record<string, string> = {
  introuvable: 'absente du CRM',
  format_inconnu: 'illisible (ni SIREN, ni identifiant, ni adresse)',
};

export async function chargerListes(params: { corbeille?: boolean; company_id?: number; contact_id?: number } = {}): Promise<ListeManuelle[]> {
  const query = new URLSearchParams();
  if (params.corbeille === true) query.set('corbeille', '1');
  if (params.company_id !== undefined) query.set('company_id', String(params.company_id));
  if (params.contact_id !== undefined) query.set('contact_id', String(params.contact_id));
  const suffixe = query.toString();
  const { data } = await api.get<{ data?: ListeManuelle[] }>(`/listes-manuelles${suffixe === '' ? '' : `?${suffixe}`}`);
  return data.data ?? [];
}

export async function creerListe(nom: string, description?: string): Promise<ListeManuelle> {
  const { data } = await api.post<{ data: ListeManuelle }>('/listes-manuelles', {
    nom,
    ...(description !== undefined && description !== '' ? { description } : {}),
  });
  return data.data;
}

export async function ajouterALaListe(
  listeId: number,
  fiches: { company_ids?: number[]; contact_ids?: number[] },
): Promise<BilanAjout> {
  const { data } = await api.post<{ data: BilanAjout }>(`/listes-manuelles/${listeId}/membres`, fiches);
  return data.data;
}

export async function retirerDeLaListe(
  listeId: number,
  fiches: { company_ids?: number[]; contact_ids?: number[] },
): Promise<{ retires: number; absents: number }> {
  const { data } = await api.post<{ data: { retires: number; absents: number } }>(
    `/listes-manuelles/${listeId}/membres/retirer`,
    fiches,
  );
  return data.data;
}

/** Le message qu'explique le serveur (422, 409), sinon `null`. */
export function messageServeur(err: unknown): string | null {
  if (typeof err === 'object' && err !== null) {
    const e = err as { response?: { data?: { message?: string; error?: string } } };
    return e.response?.data?.message ?? e.response?.data?.error ?? null;
  }
  return null;
}

/** « 3 ajoutée(s), 1 déjà présente(s), 2 introuvable(s) » — le compte réel, jamais « fait ». */
export function resumerAjout(b: BilanAjout): string {
  const morceaux = [`${b.ajoutes + b.reactives} fiche(s) ajoutée(s)`];
  if (b.deja_presents > 0) morceaux.push(`${b.deja_presents} déjà présente(s)`);
  if (b.introuvables > 0) morceaux.push(`${b.introuvables} introuvable(s)`);
  if ((b.presse_refusees ?? 0) > 0) morceaux.push(`${b.presse_refusees ?? 0} de presse refusée(s) (segment presse fermé)`);
  return `${morceaux.join(', ')}.`;
}
