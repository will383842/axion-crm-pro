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

/** Plus de 366 jours entre « Du » et « Au » : le serveur répondrait 422. */
export function periodeTropLongue(du: string, au: string): boolean {
  if (du === '' || au === '') return false;
  const jours: number = (Date.parse(au) - Date.parse(du)) / 86_400_000;
  return Number.isFinite(jours) && jours > 366;
}

export function parametres(f: Filtres): Record<string, string> {
  const sortie: Record<string, string> = {};
  for (const k of Object.keys(f) as Array<keyof Filtres>) {
    const v = f[k];
    if (v !== '' && !(k === 'oppose' && v === 'tous')) sortie[k] = v;
  }
  if (periodeTropLongue(f.du, f.au)) {
    delete sortie['du'];
    delete sortie['au'];
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
  meta: { total: number; par_page: number; suivant: number | null };
}

export const statutHttp = (e: unknown): number | null => (isAxiosError(e) ? (e.response?.status ?? null) : null);
/** 403 et 404 : l'écran n'est pas ouvert pour cet utilisateur ou cet espace. */
export const estRefuse = (e: unknown): boolean => [403, 404].includes(statutHttp(e) ?? 0);

export const lire = async <T,>(chemin: string, params: Record<string, string | number> = {}): Promise<T> => (await api.get<T>(chemin, { params })).data;

export async function exporterCsv(f: Filtres): Promise<string> {
  const r = await api.get<string>('/crm/origines/export', { params: parametres(f), responseType: 'text', transformResponse: (d: string) => d });
  return r.data;
}
