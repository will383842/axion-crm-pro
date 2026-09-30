/**
 * Relation, étape, pays, taille non renseignée et joignabilité comme critères
 * d'audience (chantiers B, C, D — 2026-10-01).
 *
 * Module PUR : il transforme l'état de l'écran en conditions du DSL serveur
 * (`AudienceBuilderService`, blocs `all` / `any` / `not`). Les champs sont
 * EXACTEMENT les colonnes de `companies` que la liste blanche du serveur
 * accepte : `relation_type`, `lifecycle_stage`, `country_code`,
 * `size_category`, `joignabilite`.
 *
 * Le défaut d'une audience de PROSPECTION — exclure clients, partenaires,
 * presse, fournisseurs et investisseurs — vient de la liste GÉNÉRÉE depuis le
 * serveur (`RelationsProspection`) : l'écran le montre pré-coché, il se
 * décoche. Ce n'est pas un filtre caché.
 */
import { RELATIONS_HORS_PROSPECTION } from '@/lib/referentiels.generated';
import type { AudienceCondition, AudienceCriteria } from './AudiencesListPage';

/** Types de relation exclus par défaut d'une audience de prospection. */
export const RELATIONS_EXCLUES_PAR_DEFAUT: readonly string[] = RELATIONS_HORS_PROSPECTION;

/** Puce spéciale « taille non renseignée » (effectif inconnu ou organisation). */
export const SANS_TAILLE = '__sans_taille';

export type ChoixPays = 'tous' | 'france' | 'etranger';

export interface EtatRelation {
  relationsVisees: string[];
  relationsExclues: string[];
  etapesVisees: string[];
  etapesExclues: string[];
  pays: ChoixPays;
  /** Codes de `TAILLES`, et éventuellement `SANS_TAILLE`. */
  tailles: string[];
  joignabilitesVisees: string[];
  joignabilitesExclues: string[];
}

function dans(field: string, value: string[]): AudienceCondition {
  return { field, op: 'in', value };
}

/**
 * Les critères complets : les conditions `all` déjà construites par la page,
 * enrichies des conditions de relation, pays, taille et joignabilité.
 * Un bloc vide n'est jamais envoyé.
 */
export function construireCriteres(allDeBase: AudienceCondition[], etat: EtatRelation): AudienceCriteria {
  const all: AudienceCondition[] = [...allDeBase];
  const any: AudienceCondition[] = [];
  const not: AudienceCondition[] = [];

  if (etat.relationsVisees.length > 0) all.push(dans('relation_type', etat.relationsVisees));
  if (etat.etapesVisees.length > 0) all.push(dans('lifecycle_stage', etat.etapesVisees));
  if (etat.joignabilitesVisees.length > 0) all.push(dans('joignabilite', etat.joignabilitesVisees));

  if (etat.pays === 'france') all.push({ field: 'country_code', op: 'eq', value: 'FR' });
  if (etat.pays === 'etranger') all.push({ field: 'country_code', op: 'neq', value: 'FR' });

  const sansTaille = etat.tailles.includes(SANS_TAILLE);
  const tailles = etat.tailles.filter((t) => t !== SANS_TAILLE);
  if (sansTaille && tailles.length === 0) {
    all.push({ field: 'size_category', op: 'is_null', value: null });
  } else if (sansTaille) {
    any.push(dans('size_category', tailles), { field: 'size_category', op: 'is_null', value: null });
  } else if (tailles.length > 0) {
    all.push(dans('size_category', tailles));
  }

  if (etat.relationsExclues.length > 0) not.push(dans('relation_type', etat.relationsExclues));
  if (etat.etapesExclues.length > 0) not.push(dans('lifecycle_stage', etat.etapesExclues));
  if (etat.joignabilitesExclues.length > 0) not.push(dans('joignabilite', etat.joignabilitesExclues));

  const criteres: AudienceCriteria = { all };
  if (any.length > 0) criteres.any = any;
  if (not.length > 0) criteres.not = not;

  return criteres;
}

/** Au moins un critère POSITIF (`all` ou `any`) : l'exclusion seule ne suffit pas. */
export function aUnCriterePositif(criteres: AudienceCriteria): boolean {
  return (criteres.all?.length ?? 0) > 0 || (criteres.any?.length ?? 0) > 0;
}
