/**
 * Critères d'audience en phrases lisibles — `src/features/audiences/criteres-lisibles.ts`.
 *
 * Audit UX lot 14 (2026-10-03) : l'onglet « Critères » montrait le JSON brut
 * du serveur. Il dit désormais « Département : 69, 38 », « Taille : TPE ».
 * Un champ inconnu n'affiche JAMAIS son nom technique.
 */
import { describe, expect, it } from 'vitest';

import { criteresLisibles } from '@/features/audiences/criteres-lisibles';

describe('criteresLisibles', () => {
  it('traduit les champs connus et leurs valeurs en clair', () => {
    const lignes = criteresLisibles({
      all: [
        { field: 'department_code', op: 'in', value: ['69', '38'] },
        { field: 'size_category', op: 'in', value: ['tpe', 'pme'] },
        { field: 'quality_score', op: 'gte', value: 60 },
        { field: 'has_email', op: 'eq', value: true },
        { field: 'country_code', op: 'eq', value: 'FR' },
      ],
    });
    expect(lignes.map((l) => `${l.libelle} : ${l.valeur}`)).toEqual([
      'Département : 69, 38',
      'Taille : TPE, PME',
      'Score qualité : au moins 60',
      'E-mail connu : oui',
      'Pays : France',
    ]);
  });

  it('nomme les blocs « au moins un de » et « sauf »', () => {
    const lignes = criteresLisibles({
      all: [],
      any: [{ field: 'size_category', op: 'is_null', value: null }],
      not: [{ field: 'relation_type', op: 'in', value: ['client'] }],
    });
    expect(lignes).toEqual([
      { bloc: 'Au moins un de', libelle: 'Taille', valeur: 'non renseigné' },
      { bloc: 'Sauf', libelle: 'Type de relation', valeur: 'Clients' },
    ]);
  });

  it('un champ inconnu devient « Autre critère », jamais son nom technique', () => {
    const [ligne] = criteresLisibles({ all: [{ field: 'champ_mystere_x', op: 'in', value: ['a'] }] });
    expect(ligne?.libelle).toBe('Autre critère');
    expect(JSON.stringify(ligne)).not.toContain('champ_mystere_x');
  });

  it('sans critère : aucune ligne', () => {
    expect(criteresLisibles({})).toEqual([]);
    expect(criteresLisibles(null)).toEqual([]);
  });
});
