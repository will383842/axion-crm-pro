/** Contrat de lecture des origines (PR-A, `OriginesController`) — rien de plus que le plan §4. */
import { api } from '@/lib/api';
import { isAxiosError } from 'axios';

export interface Filtres {
  famille: string;
  detail: string;
  reseau: string;
  mot: string;
  oppose: 'tous' | 'oui' | 'non';
  du: string;
  au: string;
}
export const FILTRES_VIDES: Filtres = { famille: '', detail: '', reseau: '', mot: '', oppose: 'tous', du: '', au: '' };

export const filtresActifs = (f: Filtres): boolean => Object.keys(parametres(f)).length > 0;

const JOUR_MS = 86_400_000;
const MAX_JOURS = 366;
/** Motif du serveur pour « Mot » : ^[a-z0-9][a-z0-9._-]{0,63}$ */
export const MOT_VALIDE = /^[a-z0-9][a-z0-9._-]{0,63}$/;

/** Plus de 366 jours entre « Du » et « Au » : le serveur répondrait 422. */
export function periodeTropLongue(du: string, au: string): boolean {
  if (du === '' || au === '') return false;
  const jours: number = (Date.parse(au) - Date.parse(du)) / JOUR_MS;
  return Number.isFinite(jours) && jours > MAX_JOURS;
}

/**
 * Les règles que le serveur applique (sinon 422), reproduites ICI pour n'envoyer
 * aucune requête perdue : (a) une borne seule à plus de 366 jours d'aujourd'hui,
 * (b) « Du » postérieur à « Au », (c) « Mot » hors du motif du serveur, et
 * (d) Du/Au à plus de 366 jours l'un de l'autre. Rend le message, ou null.
 */
export function erreurFiltres(f: Filtres, aujourdhui: Date = new Date()): string | null {
  if (f.mot !== '' && !MOT_VALIDE.test(f.mot)) {
    return 'Mot refusé : lettres minuscules sans accent, chiffres, point, tiret ou tiret bas, 64 signes au maximum, sans espace.';
  }
  if (f.du !== '' && f.au !== '' && Date.parse(f.du) > Date.parse(f.au)) {
    return 'Période incohérente : la date « Du » doit être avant la date « Au ».';
  }
  if (periodeTropLongue(f.du, f.au)) {
    return 'Période trop longue : 366 jours au maximum entre « Du » et « Au ». Le serveur refuserait la demande (erreur 422).';
  }
  for (const borne of [f.du, f.au]) {
    if (borne === '') continue;
    const jours = Math.abs(Date.parse(borne) - aujourdhui.getTime()) / JOUR_MS;
    if (Number.isFinite(jours) && jours > MAX_JOURS) {
      return 'Date trop éloignée : 366 jours au maximum avant ou après aujourd’hui. Le serveur refuserait la demande (erreur 422).';
    }
  }
  return null;
}

export function parametres(f: Filtres): Record<string, string> {
  const sortie: Record<string, string> = {};
  for (const k of Object.keys(f) as Array<keyof Filtres>) {
    const v = f[k];
    if (v !== '' && !(k === 'oppose' && v === 'tous')) sortie[k] = v;
  }
  if (sortie['detail'] !== undefined && sortie['famille'] === undefined) delete sortie['detail'];
  return sortie;
}

export interface Apercu {
  actif: boolean;
  personnes: number;
  demandes: number;
  accords: number;
  opposees: number;
  par_famille: Array<{ famille: string | null; demandes: number; personnes: number }>;
}
export interface LigneReseau { reseau: string | null; canal: string | null; compte: string | null; demandes: number; personnes: number }
export interface LigneMot { mot: string | null; famille: string | null; detail: string | null; demandes: number; personnes: number }
export interface PointTendance { jour: string; demandes: number }
export interface OrigineEntree {
  famille: string | null; detail: string | null; canal: string | null; reseau: string | null; compte: string | null;
  mot: string | null; guide: string | null; date: string | null; post_ref: string | null;
}
export interface LignePersonne {
  id: number; email: string | null; first_name: string | null; last_name: string | null; entreprise: string | null;
  demandes: number; derniere_origine: OrigineEntree | null;
}
export interface Historique {
  actif: boolean;
  data: OrigineEntree[];
  /** `suivant` : curseur OPAQUE rendu par le serveur, renvoyé tel quel dans `curseur`. */
  meta: { total: number; par_page: number; suivant: string | number | null };
}

export const statutHttp = (e: unknown): number | null => (isAxiosError(e) ? (e.response?.status ?? null) : null);
/** 403 et 404 : l'écran n'est pas ouvert pour cet utilisateur ou cet espace. */
export const estRefuse = (e: unknown): boolean => [403, 404].includes(statutHttp(e) ?? 0);

export const lire = async <T,>(chemin: string, params: Record<string, string | number> = {}): Promise<T> => (await api.get<T>(chemin, { params })).data;

export async function exporterCsv(f: Filtres): Promise<string> {
  const r = await api.get<string>('/crm/origines/export', { params: parametres(f), responseType: 'text', transformResponse: (d: string) => d });
  return r.data;
}
