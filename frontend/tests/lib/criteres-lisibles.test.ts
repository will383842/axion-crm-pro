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

  it('opérateurs serveur is_null / is_not_null (AudienceBuilderService)', () => {
    const lignes = criteresLisibles({
      all: [
        { field: 'enriched_at', op: 'is_not_null', value: null },
        { field: 'commune_code', op: 'is_null', value: null },
      ],
    });
    expect(lignes.map((l) => `${l.libelle} : ${l.valeur}`)).toEqual([
      'Date d’enrichissement : renseigné',
      'Commune : non renseigné',
    ]);
  });

  it('un code qui porte le nom d’une propriété d’objet (« constructor ») reste tel quel', () => {
    const [ligne] = criteresLisibles({ all: [{ field: 'size_category', op: 'in', value: ['constructor', 'toString'] }] });
    expect(ligne?.valeur).toBe('constructor, toString');
    const [champ] = criteresLisibles({ all: [{ field: 'constructor', op: 'eq', value: 'x' }] });
    expect(champ?.libelle).toBe('Autre critère');
  });

  it('étiquettes automatiques : métier, type et zone de média en clair', () => {
    const lignes = criteresLisibles({
      all: [
        { field: 'tags', op: 'contains_any', value: ['metier-experts-comptables'] },
        { field: 'tags', op: 'contains_any', value: ['media-type:presse-quotidienne'] },
        { field: 'tags', op: 'contains_any', value: ['media-zone:national'] },
        { field: 'tags', op: 'contains_any', value: ['salon-gofab', 'metier-dentistes'] },
      ],
    });
    expect(lignes.map((l) => `${l.libelle} : ${l.valeur}`)).toEqual([
      'Métier : Experts-comptables et cabinets comptables',
      'Type de média : Presse quotidienne',
      'Zone de diffusion : Nationale',
      'Étiquettes : salon-gofab, Dentistes',
    ]);
  });

  it('listes manuelles : leur nom si l’écran le connaît, sinon « une liste manuelle »', () => {
    const criteres = {
      all: [{ field: 'liste_manuelle', op: 'in', value: [12] }],
      not: [{ field: 'liste_manuelle', op: 'not_in', value: [12, 13, 14] }],
    };
    expect(criteresLisibles(criteres).map((l) => l.valeur)).toEqual([
      'une liste manuelle',
      'tout sauf 3 listes manuelles',
    ]);
    expect(criteresLisibles(criteres, { 12: 'Salon GOFAB' }).map((l) => l.valeur)).toEqual([
      'Salon GOFAB',
      'tout sauf Salon GOFAB, 2 listes manuelles',
    ]);
  });

  it('priorité, confiance de l’e-mail, statut de prospection et date d’enrichissement : la VALEUR en clair', () => {
    const lignes = criteresLisibles({
      all: [
        { field: 'priority', op: 'in', value: ['haute', 'gelee'] },
        { field: 'best_email_confidence', op: 'in', value: ['A'] },
        { field: 'prospection_status', op: 'in', value: ['ready_for_outreach'] },
        { field: 'enriched_at', op: 'gte', value: '2026-09-01' },
        { field: 'commune_code', op: 'in', value: ['69123'] },
      ],
    });
    expect(lignes.map((l) => `${l.libelle} : ${l.valeur}`)).toEqual([
      'Priorité : Haute, Gelée',
      'Confiance de l’e-mail : A — adresse du domaine officiel',
      'Statut de prospection : Contactables (e-mail exploitable)',
      `Date d’enrichissement : depuis le ${new Date('2026-09-01').toLocaleDateString('fr-FR')}`,
      'Commune : 69123',
    ]);
  });

  it('sans critère : aucune ligne', () => {
    expect(criteresLisibles({})).toEqual([]);
    expect(criteresLisibles(null)).toEqual([]);
  });
});
