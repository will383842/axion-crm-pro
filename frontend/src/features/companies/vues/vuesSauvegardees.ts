/**
 * Vues sauvegardées de l'écran Entreprises (`SavedViewsController`, entité
 * « companies ») : un nom et les filtres renseignés.
 */
import { api } from '@/lib/api';
import { EMPTY_FILTER, filtreDepuisRecherche, type Filter } from '../filtresUrl';

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
  // Seules les clés connues, avec une valeur TEXTE, sont reprises ; chacune passe par la même validation
  // que l'adresse (une vue ancienne ou abîmée ne peut pas injecter une valeur que l'écran refuserait).
  const brut: Record<string, unknown> = {};
  const source = vue.filters as unknown as Record<string, unknown> | null | undefined;
  for (const cle of Object.keys(EMPTY_FILTER)) {
    const valeur = source !== null && source !== undefined && Object.hasOwn(source, cle) ? source[cle] : undefined;
    if (typeof valeur === 'string') brut[cle] = valeur;
  }
  return filtreDepuisRecherche(brut);
}
