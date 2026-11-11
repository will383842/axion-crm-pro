/**
 * Vues sauvegardées de l'écran Entreprises (`SavedViewsController`, entité
 * « companies ») : un nom et les filtres renseignés.
 */
import { api } from '@/lib/api';
import { EMPTY_FILTER, type Filter } from '../filtresUrl';

export const ENTITE_VUES = 'companies';

export interface VueSauvegardee {
  id: number;
  name: string;
  filters: Partial<Filter>;
  is_default: boolean;
}

export const CLE_VUES = ['saved-views', ENTITE_VUES] as const;

export async function chargerVues(): Promise<VueSauvegardee[]> {
  const { data } = await api.get<{ data: VueSauvegardee[] }>(`/saved-views?entity=${ENTITE_VUES}`);
  return data.data;
}

/** Seuls les filtres renseignés sont gardés dans la vue. */
export function filtresARetenir(filtre: Filter): Partial<Filter> {
  return Object.fromEntries(Object.entries(filtre).filter(([, v]) => v !== ''));
}

export async function enregistrerVue(nom: string, filtre: Filter): Promise<VueSauvegardee> {
  const { data } = await api.post<{ data: VueSauvegardee }>('/saved-views', {
    entity: ENTITE_VUES,
    name: nom.trim(),
    filters: filtresARetenir(filtre),
  });
  return data.data;
}

/** Le filtre complet d'une vue : les champs absents repartent de zéro. */
export function filtreDeLaVue(vue: VueSauvegardee): Filter {
  // Seules les clés connues du filtre, avec des valeurs texte, sont reprises : le reste est ignoré.
  const retenu: Partial<Filter> = {};
  for (const cle of Object.keys(EMPTY_FILTER) as Array<keyof Filter>) {
    const valeur: unknown = (vue.filters as Record<string, unknown> | null | undefined)?.[cle];
    if (typeof valeur === 'string') (retenu as Record<string, string>)[cle] = valeur;
  }
  return { ...EMPTY_FILTER, ...retenu };
}
