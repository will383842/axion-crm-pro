/**
 * GARDE ANTI-TUTOIEMENT — la console VOUVOIE, partout.
 *
 * Audit UX du 02/10/2026 (P0-4) : 35 textes tutoyaient dans 20 fichiers, y
 * compris l'écran de connexion (« Connecte-toi à ton workspace »), la recherche
 * (« Tape au moins 2 caractères ») et le tableau de bord (« Lance ton premier
 * scrape », « Que veux-tu faire maintenant ? »). Ils ont été réécrits ; cette
 * garde ROUGIT si un tutoiement revient dans un texte affiché.
 *
 * Périmètre : chaînes littérales et texte JSX de `src/**\/*.{ts,tsx}`, valeurs
 * de `src/locales/fr.json`. Les commentaires ne sont PAS lus (voir
 * `tests/helpers/tutoiement.ts`).
 *
 * Motifs : « tu », « ton », « ta », « tes », « toi », « te », l'élision « t' »,
 * l'inversion « -tu » / « -toi », et les impératifs de 2e personne courants en
 * tête de phrase (Lance, Choisis, Crée, Ajoute, Saisis, Vérifie, Reçois,
 * Essaie, Tape, Invite, Configure, Renseigne, Compose, Explore, Découvre,
 * Améliore, Reprends, Visualise, Réinitialise, Clique…).
 *
 * ═══ EXCEPTIONS ═══
 *
 * Une exception se déclare dans `EXCEPTIONS` ci-dessous, avec le fichier, le
 * texte EXACT et la raison. Aucune n'est nécessaire à ce jour : les faux
 * positifs connus (« Complète », « Relance » comme nom, la propriété `ton` des
 * pastilles) sont déjà écartés par le détecteur lui-même — frontière de mot
 * écrite pour les lettres accentuées, impératifs sensibles à la casse, noms
 * d'identifiants non lus. Si un mot légitime tombe sous un motif (un nom
 * propre, une citation), on l'ajoute ici plutôt que d'affaiblir le motif.
 */
import { existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { describe, expect, it } from 'vitest';

import { chercherTutoiement, motifTutoiement } from '../helpers/tutoiement';

interface Exception {
  fichier: string;
  texte: string;
  raison: string;
}

const EXCEPTIONS: Exception[] = [];

/** Même remontée que les autres gardes de code source (`process.cwd()` varie). */
function trouverRacineSrc(): string {
  let courant = process.cwd();
  for (let i = 0; i < 6; i += 1) {
    const candidat = join(courant, 'src');
    if (existsSync(join(candidat, 'locales', 'fr.json'))) return candidat;
    const parent = dirname(courant);
    if (parent === courant) break;
    courant = parent;
  }
  return join(process.cwd(), 'frontend', 'src');
}

describe('Détecteur de tutoiement — témoins', () => {
  // Sans ces témoins, un détecteur cassé (motif vide, mauvaise frontière de
  // mot) laisserait la garde ci-dessous au vert pour une mauvaise raison.
  it.each([
    'Connecte-toi à ton workspace Axion CRM Pro.',
    'Que veux-tu faire maintenant ?',
    'Saisis l’adresse e-mail, on t’envoie un lien.',
    'Vérifie ta boîte mail (et les spams).',
    'Lance ton premier scrape',
    'Choisis un département sur la carte France.',
    'Reçois un lien de connexion par email.',
    'Explore tes entreprises',
    'Tape au moins 2 caractères pour rechercher.',
  ])('repère « %s »', (texte) => {
    expect(motifTutoiement(texte)).not.toBeNull();
  });

  it.each([
    'Accédez à votre CRM.',
    'Fiche complète',
    'Requête RGPD créée',
    'Relance',
    'Statut de la collecte',
    'Tête de réseau',
    'Saint-Étienne',
    'Récupérer des entreprises',
    'Inviter quelqu’un',
  ])('ne confond pas « %s » avec un tutoiement', (texte) => {
    expect(motifTutoiement(texte)).toBeNull();
  });
});

describe('Vouvoiement — aucun texte affiché ne tutoie', () => {
  it('aucune occurrence hors exceptions documentées', () => {
    const trouvees = chercherTutoiement(trouverRacineSrc()).filter(
      (o) => !EXCEPTIONS.some((e) => e.fichier === o.fichier && e.texte === o.texte),
    );

    expect(
      trouvees.map((o) => `${o.fichier}:${o.ligne} [${o.motif}] ${o.texte}`),
      'Tutoiement dans un texte affiché. La console VOUVOIE (ordre permanent). ' +
        'GESTE : réécrire le texte au vouvoiement (« Saisissez », « votre »…). ' +
        'Faux positif avéré seulement : le déclarer dans EXCEPTIONS avec sa raison.',
    ).toEqual([]);
  });

  it('chaque exception déclarée existe encore (pas d’exception morte)', () => {
    const toutes = chercherTutoiement(trouverRacineSrc());
    for (const e of EXCEPTIONS) {
      expect(
        toutes.some((o) => o.fichier === e.fichier && o.texte === e.texte),
        `Exception morte : « ${e.texte} » (${e.fichier}) n’existe plus. La retirer.`,
      ).toBe(true);
    }
  });
});
