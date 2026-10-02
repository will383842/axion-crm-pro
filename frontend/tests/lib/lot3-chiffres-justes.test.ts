import { describe, expect, it } from 'vitest';
import { libelleActivite } from '@/features/dashboard/activite';
import { etatQualite } from '@/features/dashboard/qualite';
import { scoreAffichable, statsCouverture } from '@/features/coverage/statsCouverture';
import { misAJour } from '@/lib/fraicheur';
import { indicateursEntreprises } from '@/features/companies/indicateurs';

/**
 * LOT 3 « DES CHIFFRES JUSTES » (audit visuel du 2026-10-02) — les règles
 * d'affichage, sans écran : chacune rougit sur le comportement d'avant.
 */

describe('activité récente : des phrases, pas des lignes techniques', () => {
  it('traduit les requêtes de la console', () => {
    expect(libelleActivite('POST', 'api/v1/auth/password/reset')).toBe('Mot de passe réinitialisé');
    expect(libelleActivite('POST', 'api/v1/campaigns/2/start')).toBe('Collecte démarrée');
    expect(libelleActivite('DELETE', 'api/v1/users/334d1e9c')).toBe('Utilisateur retiré');
    expect(libelleActivite('PUT', 'api/v1/workspace')).toBe('Paramètres de l’espace modifiés');
  });

  it('traduit les traitements en lot', () => {
    expect(libelleActivite('IMPORT_PRESSE', 'artisan crm:presse:importer')).toBe('Import de la liste presse');
    expect(libelleActivite('FUSION_FICHES', 'doublons — fusion 99')).toBe('Fusion de deux fiches en double');
  });

  it('masque la progression des lots (null)', () => {
    expect(libelleActivite('JOIGNABILITE_LOT', 'artisan crm:joignabilite:calculer — ids 1-1000')).toBeNull();
    expect(libelleActivite('RELATIONS_IMPORT_PAQUET', 'artisan crm:relations:importer — paquet 1')).toBeNull();
  });

  it('ne montre jamais une route ni un verbe HTTP, même inconnus', () => {
    const phrase = libelleActivite('PATCH', 'api/v1/quelque-chose/12');
    expect(phrase).toBe('Modification dans la console');
    // Un code inconnu n'est ni déguisé en phrase, ni masqué sans bruit.
    expect(libelleActivite('NOUVEL_EVENEMENT', null)).toBe('Autre opération');
    expect(libelleActivite('crm.sync.retry', 'artisan crm:sync')).toBe('Autre opération');
    expect(libelleActivite('', null)).toBe('Autre opération');
  });
});

describe('qualité moyenne : jamais un 0 trompeur', () => {
  it('scores périmés au-delà du seuil : en attente', () => {
    expect(etatQualite({ quality_avg: 10, quality_a_recalculer_pct: 79 })).toEqual({ etat: 'en_attente', pctARecalculer: 79 });
  });
  it('pas de moyenne : indisponible (et non 0)', () => {
    expect(etatQualite({ quality_avg: null, quality_a_recalculer_pct: 0 })).toEqual({ etat: 'indisponible' });
    expect(etatQualite({})).toEqual({ etat: 'indisponible' });
  });
  it('moyenne fiable : affichée telle que le serveur la rend', () => {
    expect(etatQualite({ quality_avg: 62, quality_a_recalculer_pct: 2 })).toEqual({ etat: 'ok', moyenne: 62 });
  });
});

describe('carte de France : indicateurs sur les vraies cellules', () => {
  it('101 départements, totaux additionnés en NOMBRES (pas concaténés)', () => {
    // Le serveur rendait des chaînes (SUM → numeric) : `0 + "615507"` concaténait.
    const cells = [
      { code: '75', name: 'Paris', total: '615507' as unknown as number, complete: 0, partial: 100 },
      { code: '974', name: 'La Réunion', total: 12, complete: 0, partial: 0 },
    ];
    const s = statsCouverture(cells, 'department');
    expect(s.totalAll).toBe(615_519);
    expect(s.denom).toBe(101);
    expect(s.covered).toBe(2);
    expect(s.withScore).toBe(100);
  });
  it('ne dépasse jamais 100 %', () => {
    const cells = Array.from({ length: 105 }, (_, i) => ({ code: String(i), name: String(i), total: 1 }));
    expect(statsCouverture(cells, 'department').pct).toBe(100);
  });
});

describe('entreprises : vignettes de toute la base', () => {
  it('sans chiffre du serveur : « — », jamais une valeur de la page', () => {
    const k = indicateursEntreprises(undefined);
    expect(k.enrichies).toBe('—');
    expect(k.taille).toBe('—');
    expect(k.secteur).toBe('—');
  });
  it('libellés lisibles et estimation annoncée', () => {
    const k = indicateursEntreprises({
      total: 4_346_269,
      enrichies_pct: 18,
      top_taille: { code: 'tpe', n: 4_042_240, pct: 93 },
      top_secteur: { code: 'btp', n: 578_844, pct: 13 },
    });
    expect(k.enrichies).toBe('≈ 18 %');
    expect(k.enrichiesSous).toMatch(/estimation/);
    expect(k.taille).not.toBe('tpe');
    expect(k.secteur).toBe('Bâtiment et travaux publics');
  });
});

describe('relecture A09 de #284', () => {
  it('carte : « dont N au score ≥ 50 » masqué tant que les scores sont périmés ou inconnus', () => {
    expect(scoreAffichable(79)).toBe(false);
    expect(scoreAffichable(null)).toBe(false);
    expect(scoreAffichable(undefined)).toBe(false);
    expect(scoreAffichable(2)).toBe(true);
  });

  it('chiffres en cache : « mis à jour il y a N min »', () => {
    const t0 = Date.parse('2026-10-02T10:00:00Z');
    expect(misAJour('2026-10-02T09:53:00Z', t0)).toBe('mis à jour il y a 7 min');
    expect(misAJour('2026-10-02T07:00:00Z', t0)).toBe('mis à jour il y a 3 h');
    expect(misAJour(null, t0)).toBeNull();
  });

  it('entreprises : la part est dite « de toutes les fiches »', () => {
    const k = indicateursEntreprises({
      total: 10,
      enrichies_pct: 50,
      top_taille: { code: 'tpe', n: 6, pct: 60 },
      top_secteur: { code: 'btp', n: 4, pct: 40 },
      computed_at: new Date().toISOString(),
    });
    expect(k.tailleSous).toBe('60 % de toutes les fiches');
    expect(k.secteurSous).toMatch(/40 % de toutes les fiches/);
    expect(k.fraicheur).toMatch(/mis à jour/);
  });
});
