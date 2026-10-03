/**
 * « Santé du système » en clair (constaté en production le 03/10/2026) :
 * motifs d'archivage, actions du journal métier et éléments concernés ne
 * s'affichent plus en codes bruts. Un code inconnu retombe sur un libellé
 * neutre, jamais sur le code lui-même.
 */
import { describe, expect, it } from 'vitest';

import { AUTRE_OPERATION, EVENEMENTS } from '@/features/dashboard/activite';
import {
  AUTRE_RAISON,
  MOTIFS_ARCHIVAGE,
  elementConcerne,
  libelleEvenementMetier,
  libelleMotifArchivage,
} from '@/features/observability/libelles';

describe('Motifs d’archivage', () => {
  it('traduit les motifs vus en production', () => {
    expect(libelleMotifArchivage('entreprise_radiee')).toBe('Entreprise radiée');
    expect(libelleMotifArchivage('no_email')).toBe('Sans e-mail');
  });

  it('couvre toutes les valeurs de la contrainte companies_archive_reason_check', () => {
    expect(Object.keys(MOTIFS_ARCHIVAGE).sort()).toEqual(
      ['duplicate', 'entreprise_radiee', 'low_quality_score', 'manual', 'no_email'].sort(),
    );
  });

  it('tolère la casse (l’écran affichait « ENTREPRISE_RADIEE »)', () => {
    expect(libelleMotifArchivage('ENTREPRISE_RADIEE')).toBe('Entreprise radiée');
  });

  it.each(['motif_inconnu', '', null, undefined])('motif inconnu « %s » → « Autre raison »', (motif) => {
    expect(libelleMotifArchivage(motif)).toBe(AUTRE_RAISON);
  });
});

describe('Actions du journal métier', () => {
  it('réutilise le dictionnaire de « Activité récente »', () => {
    expect(libelleEvenementMetier('audience.refreshed')).toBe(EVENEMENTS['audience.refreshed']);
    expect(libelleEvenementMetier('audience.refreshed')).toBe('Audience mise à jour');
    expect(libelleEvenementMetier('audience.refresh.failed')).toBe('Échec de mise à jour d’audience');
    expect(libelleEvenementMetier('company.tags_synced')).toBe('Étiquettes synchronisées');
  });

  it.each(['company.nouveau_code', 'x', '', null, undefined])('action inconnue « %s » → « Autre opération »', (action) => {
    expect(libelleEvenementMetier(action)).toBe(AUTRE_OPERATION);
  });
});

describe('Élément concerné', () => {
  it('entreprise : « Entreprise n° … » avec le lien vers la fiche', () => {
    expect(elementConcerne('company', '5507510')).toEqual({
      texte: 'Entreprise n° 5507510',
      lien: { to: '/companies/$companyId', params: { companyId: '5507510' } },
    });
  });

  it('audience : « Audience n° 2 » avec le lien vers l’audience', () => {
    expect(elementConcerne('audience', '2')).toEqual({
      texte: 'Audience n° 2',
      lien: { to: '/audiences/$audienceId', params: { audienceId: '2' } },
    });
  });

  it('liste manuelle : lien vers la liste', () => {
    expect(elementConcerne('liste_manuelle', '7')?.lien).toEqual({ to: '/listes/$listeId', params: { listeId: '7' } });
  });

  it('adresse e-mail : pas de numéro, pas de lien', () => {
    expect(elementConcerne('email', 'a@exemple.fr')).toEqual({ texte: 'Adresse e-mail a@exemple.fr', lien: null });
  });

  it('type inconnu : libellé neutre, jamais le code brut', () => {
    const element = elementConcerne('widget', '3');
    expect(element).toEqual({ texte: 'Autre élément n° 3', lien: null });
    expect(element?.texte).not.toContain('widget');
  });

  it('identifiant non numérique : pas de lien', () => {
    expect(elementConcerne('company', 'abc')).toEqual({ texte: 'Entreprise n° abc', lien: null });
  });

  it('aucun élément : null', () => {
    expect(elementConcerne(null, null)).toBeNull();
    expect(elementConcerne('', '4')).toBeNull();
  });
});
