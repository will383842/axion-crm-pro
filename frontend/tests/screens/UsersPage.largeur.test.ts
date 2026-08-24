/**
 * GARDE X39-039 — LE TABLEAU DES UTILISATEURS NE DOIT PAS DEBORDER.
 *
 * ── CE QUI ETAIT MESURE, LE 2026-08-24 ────────────────────────────────────
 *
 * L'ajout de la colonne d'actions (X39-038) a porte les minima a
 * 200+200+150+130+170+210 = 1060 px, soit **1152 px** imposes une fois les cinq
 * gouttieres et les deux rembourrages comptes. Sur un portable de 1366 px, la
 * barre laterale et les marges laissent environ 1050 px : le tableau debordait
 * d'une centaine de pixels, et Will l'a vu avant moi.
 *
 * `TableScroll` (D30-002) rendait ce debordement ATTEIGNABLE — sans lui les
 * colonnes de droite auraient ete coupees en silence. Mais rendre un
 * debordement navigable n'est pas la meme chose que ne pas deborder.
 *
 * ── CE QUE CETTE GARDE MESURE ─────────────────────────────────────────────
 *
 * Les deux gabarits tiennent sous leur plafond, CALCULE par la meme fonction
 * que celle qu'utilise `TableScroll` a l'affichage. Une colonne ajoutee demain
 * fera rougir ce test au lieu de refabriquer une barre de defilement.
 *
 * ⚠️ LES PLAFONDS NE SE RELEVENT PAS POUR ACCOMMODER UNE NOUVELLE COLONNE.
 * C'est le piege n. 8 du dossier, et il vaut ici : si une colonne ne rentre
 * pas, c'est qu'une autre doit passer en mode etroit — pas que l'ecran doit
 * s'elargir.
 */

import { describe, it, expect } from 'vitest';

import { largeurMinimaleGrille } from '@/components/ui/TableScroll';
import { GRID_COMPACT, GRID_COMPLET } from '@/features/users/UsersPage';

/** Zone utile mesuree sur un portable de 1366 px, barre laterale deduite. */
const PLAFOND_LARGE = 1000;

/** Zone utile d'une tablette en portrait (768 px), marges deduites. */
const PLAFOND_ETROIT = 700;

describe('X39-039 — les gabarits du tableau des utilisateurs tiennent a l ecran', () => {
  it('le gabarit COMPLET tient sur un portable', () => {
    const largeur = largeurMinimaleGrille(GRID_COMPLET);

    expect(
      largeur,
      `Le gabarit complet impose ${largeur} px, au-dela des ${PLAFOND_LARGE} px ` +
        'utiles d un portable de 1366 px barre laterale deduite. GESTE : retirer ' +
        'une colonne du mode large, ou reduire un minimum — JAMAIS relever ce plafond.',
    ).toBeLessThanOrEqual(PLAFOND_LARGE);
  });

  it('le gabarit COMPACT tient sur une tablette en portrait', () => {
    const largeur = largeurMinimaleGrille(GRID_COMPACT);

    expect(
      largeur,
      `Le gabarit compact impose ${largeur} px, au-dela des ${PLAFOND_ETROIT} px ` +
        'utiles d une tablette en portrait.',
    ).toBeLessThanOrEqual(PLAFOND_ETROIT);
  });

  it('TEMOIN — le gabarit d AVANT le correctif aurait ete refuse', () => {
    // Celui qui debordait, garde ici pour que la garde prouve qu elle mord.
    const avant = 'minmax(200px,1.2fr) minmax(200px,1.4fr) minmax(150px,1fr) 130px 170px 210px';

    expect(largeurMinimaleGrille(avant)).toBeGreaterThan(PLAFOND_LARGE);
  });
});
