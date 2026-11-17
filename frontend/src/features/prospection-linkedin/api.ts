/** LI-D1 — contrat de l'API de la file LinkedIn (LI-C, `ProspectionLinkedInController`). Aucun appel vers LinkedIn. */
import { api } from '@/lib/api';
import { isAxiosError } from 'axios';

export interface ProspectFile {
  id: string;
  /** Lien `https://www.linkedin.com/in/<slug>/` : ouvert seulement par un clic du fondateur. */
  profil: string;
  entreprise: string | null;
  score: number;
  /** Texte prêt à copier ; `null` si le serveur l'a jugé non conforme (jamais de texte dégradé). */
  texte: string | null;
}
export interface FileDuJour {
  prospects: ProspectFile[];
  raison: string | null;
  arret: boolean;
  restants: { jour: number; semaine: number };
}

export const GESTES = [
  { geste: 'invitation_faite', libelle: 'Invitation faite' },
  { geste: 'invitation_acceptee', libelle: 'Invitation acceptée' },
  { geste: 'message_fait', libelle: 'Message fait' },
  { geste: 'a_repondu', libelle: 'A répondu' },
  { geste: 'plus_tard', libelle: 'Plus tard' },
  { geste: 'hors_cible', libelle: 'Hors cible' },
] as const;
export type Geste = (typeof GESTES)[number]['geste'] | 'opposition';

export const statutHttp = (e: unknown): number | null => (isAxiosError(e) ? (e.response?.status ?? null) : null);
export const refusDe = (e: unknown): string | null => {
  if (!isAxiosError(e)) return null;
  const r = (e.response?.data as { refus?: unknown } | undefined)?.refus;
  return typeof r === 'string' ? r : null;
};

export async function lireFile(): Promise<FileDuJour> {
  return (await api.get<FileDuJour>('/prospection-linkedin/file')).data;
}

/** `cle` : générée par carte et par geste, réutilisée à l'identique pour un nouvel essai (16 à 128 caractères). */
export async function poserGeste(id: string, geste: Geste, cle: string): Promise<void> {
  await api.post(`/prospection-linkedin/prospects/${id}/gestes`, { geste }, { headers: { 'Idempotency-Key': cle } });
}

export async function poserArret(geste: 'arret' | 'reprise', motif: string): Promise<void> {
  if (geste === 'arret') await api.post('/prospection-linkedin/arret', { motif });
  else await api.post('/prospection-linkedin/reprise', { motif });
}

export function nouvelleCle(): string {
  const c = globalThis.crypto;
  return typeof c?.randomUUID === 'function' ? c.randomUUID() : `cle-${Date.now()}-${Math.random().toString(36).slice(2, 12)}`;
}
