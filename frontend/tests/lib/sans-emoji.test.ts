/**
 * GARDE « SANS ÉMOJI » — finitions P2 de l'audit UX du 2026-10-02.
 *
 * La console n'affiche plus d'émoji : ni dans les titres (« Bonjour … 👋 »),
 * ni dans les états vides (🚀 🏢 📰), ni dans les listes déroulantes (types de
 * médias, « ✅ Prêtes pour une campagne »). Les pictogrammes sont des icônes
 * `lucide-react`, cohérentes d'un écran à l'autre.
 *
 * Périmètre : chaînes littérales et texte JSX de `src/**\/*.{ts,tsx}` (les
 * commentaires ne sont PAS lus — même extracteur que la garde de vouvoiement)
 * et valeurs de `src/locales/fr.json`.
 */
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, join, relative, sep } from 'node:path';
import { describe, expect, it } from 'vitest';

import { textesDuModule } from '../helpers/tutoiement';

/**
 * Fichiers encore porteurs d'émojis, avec la raison. Une entrée qui ne porte
 * plus d'émoji fait ROUGIR la garde (exception morte) : on la retire alors.
 */
const EXCEPTIONS: Record<string, string> = {
  'features/companies/CompaniesListPage.tsx':
    'pastilles 🟢🟡🔴 du filtre qualité et état vide 🏢 — fichier en cours de modification dans une autre PR, à reprendre après sa fusion',
  'features/dashboard/components/NextActions.tsx':
    'icônes des actions suggérées — fichier en cours de modification dans une autre PR, à reprendre après sa fusion',
  'features/coverage/FranceCoverageMap.tsx':
    'messages de diagnostic de la carte écrits en console du navigateur, jamais affichés à l’écran',
};

const EMOJI = /\p{Extended_Pictographic}/u;

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

function fichiers(dossier: string, acc: string[] = []): string[] {
  for (const entree of readdirSync(dossier)) {
    const chemin = join(dossier, entree);
    if (statSync(chemin).isDirectory()) fichiers(chemin, acc);
    else if (/\.tsx?$/.test(chemin) && !chemin.endsWith('.d.ts')) acc.push(chemin);
  }
  return acc;
}

function emojisParFichier(): Map<string, string[]> {
  const racine = trouverRacineSrc();
  const trouves = new Map<string, string[]>();
  for (const chemin of fichiers(racine)) {
    const rel = relative(racine, chemin).split(sep).join('/');
    for (const { ligne, texte } of textesDuModule(chemin, readFileSync(chemin, 'utf8'))) {
      if (EMOJI.test(texte)) trouves.set(rel, [...(trouves.get(rel) ?? []), `${ligne}: ${texte.trim()}`]);
    }
  }
  const fr = JSON.stringify(JSON.parse(readFileSync(join(racine, 'locales', 'fr.json'), 'utf8')));
  if (EMOJI.test(fr)) trouves.set('locales/fr.json', ['(valeur du dictionnaire)']);
  return trouves;
}

describe('Interface sans émoji', () => {
  it('le détecteur reconnaît bien un émoji (témoin)', () => {
    expect(EMOJI.test('Bonjour Will 👋')).toBe(true);
    expect(EMOJI.test('✍️ Blog')).toBe(true);
    expect(EMOJI.test('⏳ Reste à enrichir')).toBe(true);
    expect(EMOJI.test('Bonjour Will')).toBe(false);
  });

  it('aucun texte affiché ne porte d’émoji, hors exceptions documentées', () => {
    const trouves = [...emojisParFichier()]
      .filter(([fichier]) => !(fichier in EXCEPTIONS))
      .flatMap(([fichier, lignes]) => lignes.map((l) => `${fichier}:${l}`));

    expect(
      trouves,
      'Émoji dans un texte affiché. GESTE : le retirer, ou le remplacer par une icône `lucide-react`.',
    ).toEqual([]);
  });

  it('chaque exception porte encore un émoji (pas d’exception morte)', () => {
    const trouves = emojisParFichier();
    for (const fichier of Object.keys(EXCEPTIONS)) {
      expect(trouves.has(fichier), `Exception morte : ${fichier} n’a plus d’émoji. La retirer.`).toBe(true);
    }
  });
});
