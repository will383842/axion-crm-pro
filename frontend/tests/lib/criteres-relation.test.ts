/**
 * Relation, étape, pays, taille non renseignée et joignabilité comme critères
 * d'audience — `src/features/audiences/criteres-relation.ts`.
 *
 * Les champs doivent être EXACTEMENT les colonnes de la liste blanche du
 * serveur ; un bloc vide n'est jamais envoyé.
 */
import { describe, expect, it } from 'vitest';

import {
  RELATIONS_EXCLUES_PAR_DEFAUT,
  SANS_TAILLE,
  aUnCriterePositif,
  construireCriteres,
  type EtatRelation,
} from '@/features/audiences/criteres-relation';
import { RELATIONS_HORS_PROSPECTION } from '@/lib/referentiels.generated';

const VIDE: EtatRelation = {
  relationsVisees: [],
  relationsExclues: [],
  etapesVisees: [],
  etapesExclues: [],
  pays: 'tous',
  tailles: [],
  joignabilitesVisees: [],
  joignabilitesExclues: [],
};

describe('critères de relation et de joignabilité', () => {
  it('rien de choisi : seules les conditions de la page, aucun bloc any/not', () => {
    const base = [{ field: 'has_email', op: 'eq', value: true }];
    expect(construireCriteres(base, VIDE)).toEqual({ all: base });
  });

  it('le défaut de prospection exclut clients, partenaires, presse, fournisseurs, investisseurs', () => {
    expect([...RELATIONS_EXCLUES_PAR_DEFAUT]).toEqual([...RELATIONS_HORS_PROSPECTION]);
    expect([...RELATIONS_EXCLUES_PAR_DEFAUT]).toEqual(['client', 'partenaire', 'presse_media', 'fournisseur', 'investisseur']);
    expect(construireCriteres([], { ...VIDE, relationsExclues: [...RELATIONS_EXCLUES_PAR_DEFAUT] })).toEqual({
      all: [],
      not: [{ field: 'relation_type', op: 'in', value: [...RELATIONS_EXCLUES_PAR_DEFAUT] }],
    });
  });

  it('types, étapes et joignabilités visés : conditions all / in', () => {
    const c = construireCriteres([], {
      ...VIDE,
      relationsVisees: ['client'],
      etapesVisees: ['opportunite'],
      joignabilitesVisees: ['email_valide'],
    });
    expect(c.all).toEqual([
      { field: 'relation_type', op: 'in', value: ['client'] },
      { field: 'lifecycle_stage', op: 'in', value: ['opportunite'] },
      { field: 'joignabilite', op: 'in', value: ['email_valide'] },
    ]);
    expect(c.not).toBeUndefined();
  });

  it('étapes et joignabilités exclues : bloc not / in', () => {
    const c = construireCriteres([], { ...VIDE, etapesExclues: ['perdu'], joignabilitesExclues: ['email_invalide', 'email_interdit'] });
    expect(c.not).toEqual([
      { field: 'lifecycle_stage', op: 'in', value: ['perdu'] },
      { field: 'joignabilite', op: 'in', value: ['email_invalide', 'email_interdit'] },
    ]);
  });

  it('pays : France = country_code eq FR, étranger = country_code neq FR', () => {
    expect(construireCriteres([], { ...VIDE, pays: 'france' }).all).toEqual([{ field: 'country_code', op: 'eq', value: 'FR' }]);
    expect(construireCriteres([], { ...VIDE, pays: 'etranger' }).all).toEqual([{ field: 'country_code', op: 'neq', value: 'FR' }]);
  });

  it('taille non renseignée seule : size_category is_null', () => {
    expect(construireCriteres([], { ...VIDE, tailles: [SANS_TAILLE] })).toEqual({
      all: [{ field: 'size_category', op: 'is_null', value: null }],
    });
  });

  it('tailles + taille non renseignée : bloc any (dans ces tailles OU sans taille)', () => {
    expect(construireCriteres([], { ...VIDE, tailles: ['pme', SANS_TAILLE] })).toEqual({
      all: [],
      any: [
        { field: 'size_category', op: 'in', value: ['pme'] },
        { field: 'size_category', op: 'is_null', value: null },
      ],
    });
  });

  it('tailles seules : size_category in', () => {
    expect(construireCriteres([], { ...VIDE, tailles: ['tpe', 'pme'] }).all).toEqual([
      { field: 'size_category', op: 'in', value: ['tpe', 'pme'] },
    ]);
  });

  it('une exclusion seule n est pas un critère suffisant', () => {
    expect(aUnCriterePositif(construireCriteres([], { ...VIDE, relationsExclues: ['client'] }))).toBe(false);
    expect(aUnCriterePositif(construireCriteres([], { ...VIDE, tailles: ['pme', SANS_TAILLE] }))).toBe(true);
    expect(aUnCriterePositif(construireCriteres([], { ...VIDE, pays: 'france' }))).toBe(true);
  });
});
