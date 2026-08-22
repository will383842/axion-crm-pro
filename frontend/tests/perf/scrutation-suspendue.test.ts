/**
 * G41-012 — DEUX ÉCRANS QUI SE RAPPELAIENT TOUTES LES DIX SECONDES, SANS FIN.
 *
 * Mesure du 2026-08-22 : `CampaignsListPage` et `ScraperRunsPage` posaient
 * `refetchInterval: 10_000` en CONSTANTE, sans aucune condition. Ni l'un ni
 * l'autre ne s'arrêtait sur un état terminal. Ouverts la nuit sur une liste où
 * plus rien ne bouge, ils interrogeaient le serveur 8 640 fois par jour pour la
 * même réponse. Le patron de la réparation existait déjà à côté :
 * `CampaignDetailPage` pose `refetchInterval` en EXPRESSION depuis G42-007.
 *
 * Nuance que le constat portait et qu'il ne faut pas enterrer : la moitié « sur
 * un serveur qui ne sert qu'une requête à la fois » n'est plus vraie —
 * `infra/php/fpm-axion.conf` pose `pm.max_children = ${PHP_FPM_MAX_CHILDREN:-16}`.
 * Le gaspillage reste, sa gravité était surestimée.
 *
 * Deux moitiés ici, et elles se complètent :
 *  1. la DÉCISION (quand suspendre) est testée sur les fonctions qui la portent ;
 *  2. le BRANCHEMENT (que les écrans l'appliquent) est lu dans le source —
 *     et cette moitié-là le dit franchement : elle lit un texte, elle ne fait
 *     pas tourner de minuteur.
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { uneCampagneEnMouvement } from '@/features/campaigns/types';
import { uneCourseEnMouvement } from '@/features/scraping/ScraperRunsPage';

const racine = path.dirname(fileURLToPath(import.meta.url));
function lire(relatif: string): string {
  return readFileSync(path.resolve(racine, '../../src', relatif), 'utf8');
}

describe('G41-012 — quand la scrutation doit-elle continuer', () => {
  it('continue tant qu’une campagne peut encore bouger toute seule', () => {
    expect(uneCampagneEnMouvement([{ status: 'running' }])).toBe(true);
    expect(uneCampagneEnMouvement([{ status: 'scheduled' }])).toBe(true);
    // Une reprise peut venir d'un autre onglet ou d'un collègue : l'écran doit
    // la voir. Même choix que la fiche de campagne.
    expect(uneCampagneEnMouvement([{ status: 'paused' }])).toBe(true);
  });

  it('s’arrête quand plus rien ne peut changer sans un geste', () => {
    expect(
      uneCampagneEnMouvement([{ status: 'completed' }, { status: 'failed' }, { status: 'cancelled' }]),
      'G41-012 : la liste continue de se rappeler alors que toutes les campagnes ' +
        'sont dans un état terminal. GESTE : vérifier `ETATS_EN_MOUVEMENT` dans ' +
        '`src/features/campaigns/types.ts`.',
    ).toBe(false);

    // Un brouillon ne bouge que si quelqu'un le lance, et le lancement se fait
    // depuis la fiche, d'où l'on revient à la liste (rechargée au montage).
    expect(
      uneCampagneEnMouvement([{ status: 'draft' }]),
      'G41-012 : un brouillon oublié dans un espace de travail condamne l’écran à ' +
        'interroger le serveur toutes les dix secondes, indéfiniment. GESTE : ' +
        'garder `draft` HORS de `ETATS_EN_MOUVEMENT`.',
    ).toBe(false);

    expect(uneCampagneEnMouvement([])).toBe(false);
  });

  it('les journaux de collecte suivent la même règle', () => {
    expect(uneCourseEnMouvement([{ status: 'running' }])).toBe(true);
    expect(uneCourseEnMouvement([{ status: 'pending' }])).toBe(true);
    expect(
      uneCourseEnMouvement([{ status: 'success' }, { status: 'failed' }, { status: 'cancelled' }]),
      'G41-012 : les journaux continuent de se rappeler alors que toutes les ' +
        'courses sont terminées. GESTE : vérifier ' +
        '`ETATS_DE_COURSE_EN_MOUVEMENT` dans ' +
        '`src/features/scraping/ScraperRunsPage.tsx`.',
    ).toBe(false);
  });
});

describe('G41-012 — les deux écrans appliquent bien cette décision', () => {
  // ⚠️ CETTE GARDE LIT LE SOURCE, et rien d'autre. Elle ne fait tourner aucun
  // minuteur et ne mesure aucune requête : elle vérifie que la cadence n'est
  // plus une CONSTANTE. C'est exactement la forme du défaut relevé.
  const ecrans: Array<[string, string]> = [
    ['campagnes', 'features/campaigns/CampaignsListPage.tsx'],
    ['journaux de collecte', 'features/scraping/ScraperRunsPage.tsx'],
  ];

  for (const [nom, chemin] of ecrans) {
    it(`la liste des ${nom} ne pose plus de cadence constante`, () => {
      const source = lire(chemin);

      expect(
        /refetchInterval:\s*\d/.test(source),
        `G41-012 : \`${chemin}\` repose \`refetchInterval\` en CONSTANTE. La ` +
          'scrutation redevient inconditionnelle : l’écran laissé ouvert la nuit ' +
          'interroge le serveur 8 640 fois par jour pour la même réponse. ' +
          'GESTE : repasser à une EXPRESSION qui rend `false` quand plus rien ne ' +
          'bouge, sur le modèle de `CampaignDetailPage` (G42-007).',
      ).toBe(false);

      expect(
        /refetchInterval:\s*\(/.test(source),
        `G41-012 : \`${chemin}\` n’a plus de \`refetchInterval\` en expression. ` +
          'Attention : SUPPRIMER la scrutation n’est pas la réparation — une ' +
          'liste figée pendant qu’une collecte tourne est une régression. GESTE : ' +
          'rétablir l’expression conditionnée à l’état des éléments.',
      ).toBe(true);
    });
  }
});
