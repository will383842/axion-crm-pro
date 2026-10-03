/**
 * FICHE ENTREPRISE LISIBLE (constat en production du 03/10/2026, fiche
 * PROVENCE PAYSAGE MEDITERRANEE) : « Base Adresse Nationale · success »,
 * « Forme juridique : 5710 », « Effectif INSEE : 03 », « NAF : 81.30Z ».
 *
 * Ce que ces gardes tiennent :
 *  1. chaque statut de source se dit en français ; une valeur inconnue devient
 *     « Autre », jamais le mot anglais brut ;
 *  2. une catégorie juridique connue a son libellé, une inconnue retombe sur
 *     sa famille, et un code sans famille connue n'invente rien (`null`) ;
 *  3. la tranche d'effectif reprend le libellé DÉJÀ affiché par la liste ;
 *     inconnue → `null` ;
 *  4. à l'écran : libellé + code en petit ; code inconnu → le code seul.
 */
import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';

import { libelleStatutSource, deriveTimelineFromSignals } from '@/features/companies/components/EnrichmentTimeline';
import {
  CATEGORIES_JURIDIQUES,
  libelleCategorieJuridique,
  normaliserCategorieJuridique,
} from '@/lib/categories-juridiques';
import { EFFECTIF_OPTIONS, effectifLabel, libelleEffectif } from '@/features/companies/effectif';
import { LibelleEtCode } from '@/features/companies/components/LibelleEtCode';

describe('Statut des sources consultées', () => {
  it.each([
    ['success', 'Réussi'],
    ['SUCCESS', 'Réussi'],
    ['failed', 'Échec'],
    ['gave_up', 'Échec'],
    ['skipped', 'Ignoré'],
    ['partial', 'Partiel'],
    ['pending', 'En attente'],
    ['not_found', 'Rien trouvé'],
    ['rate_limited', 'Limite atteinte'],
    ['running', 'En cours'],
  ])('« %s » → « %s »', (statut, libelle) => {
    expect(libelleStatutSource(statut)).toBe(libelle);
  });

  it.each(['wobbly', '', null, undefined])('valeur inconnue (%s) → « Autre »', (statut) => {
    expect(libelleStatutSource(statut)).toBe('Autre');
  });

  it('le statut par défaut d’un signal sans statut se traduit', () => {
    const etapes = deriveTimelineFromSignals({ ban: { city: 'Marseille' } });
    expect(etapes).toHaveLength(1);
    expect(etapes[0]?.label).toBe('Base Adresse Nationale');
    expect(libelleStatutSource(etapes[0]?.status)).toBe('Réussi');
  });
});

describe('Catégorie juridique INSEE', () => {
  it.each([
    ['5710', 'SAS, société par actions simplifiée'],
    ['5499', 'SARL, société à responsabilité limitée'],
    ['1000', 'Entrepreneur individuel'],
    ['5599', 'SA à conseil d’administration'],
    ['5699', 'SA à directoire'],
    ['5202', 'Société en nom collectif'],
    ['6540', 'Société civile immobilière (SCI)'],
    ['9220', 'Association déclarée'],
    ['7210', 'Commune et commune nouvelle'],
  ])('%s → %s', (code, libelle) => {
    expect(libelleCategorieJuridique(code)).toBe(libelle);
  });

  it('le code peut arriver avec un point ou des espaces', () => {
    expect(normaliserCategorieJuridique(' 57.10 ')).toBe('5710');
    expect(libelleCategorieJuridique('57.10')).toBe('SAS, société par actions simplifiée');
  });

  it('code absent de la table : le libellé de la famille, jamais un libellé deviné', () => {
    expect(CATEGORIES_JURIDIQUES['5770']).toBeUndefined();
    expect(libelleCategorieJuridique('5770')).toBe('SAS');
    expect(libelleCategorieJuridique('5415')).toBe('SARL');
    expect(libelleCategorieJuridique('5570')).toBe('SA');
    expect(libelleCategorieJuridique('9299')).toBe('Association loi 1901 ou assimilé');
    expect(libelleCategorieJuridique('7999')).toBe('Personne morale de droit public');
  });

  it.each(['3120', '0000', '12', 'abc', '', null, undefined])('code inconnu ou mal formé (%s) → null', (code) => {
    expect(libelleCategorieJuridique(code)).toBeNull();
  });

  it('tous les codes de la table sont des codes à 4 chiffres', () => {
    for (const code of Object.keys(CATEGORIES_JURIDIQUES)) {
      expect(code).toMatch(/^\d{4}$/);
    }
  });
});

describe('Tranche d’effectif INSEE', () => {
  it('reprend le libellé de la liste (une seule table)', () => {
    expect(libelleEffectif('03')).toBe('6 à 9 salariés');
    const optionListe = EFFECTIF_OPTIONS.find((o) => o.value === '03');
    expect(libelleEffectif('03')).toBe(optionListe?.label);
  });

  it('« NN » : non employeuse', () => {
    expect(libelleEffectif('NN')).toBe('Non employeuse');
    expect(libelleEffectif('nn')).toBe('Non employeuse');
  });

  it.each(['99', 'ZZ', '', null, undefined])('tranche inconnue (%s) → null ; la liste garde « — »', (code) => {
    expect(libelleEffectif(code)).toBeNull();
    expect(effectifLabel(code)).toBe('—');
  });
});

describe('Affichage libellé + code', () => {
  it('libellé connu : libellé, puis le code en petit et en infobulle', () => {
    render(<LibelleEtCode libelle="SAS, société par actions simplifiée" code="5710" infobulle="Catégorie juridique INSEE" />);
    const el = screen.getByTitle('Catégorie juridique INSEE : 5710');
    expect(el).toHaveTextContent('SAS, société par actions simplifiée(5710)');
  });

  it('code inconnu : le code seul', () => {
    const { container } = render(<LibelleEtCode libelle={null} code="3120" infobulle="Catégorie juridique INSEE" />);
    expect(container).toHaveTextContent(/^3120$/);
  });

  it('pas de code : « — »', () => {
    const { container } = render(<LibelleEtCode libelle={null} code={null} infobulle="x" />);
    expect(container).toHaveTextContent(/^—$/);
  });

  it('code masqué : seulement en infobulle', () => {
    render(<LibelleEtCode libelle="6 à 9 salariés" code="03" infobulle="Tranche d’effectif INSEE" codeVisible={false} />);
    expect(screen.getByTitle('Tranche d’effectif INSEE : 03')).toHaveTextContent(/^6 à 9 salariés$/);
  });
});
