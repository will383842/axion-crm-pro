/**
 * GARDE — interdits de communication (règle de fer n° 9, DM0-DEMO).
 *
 * Jamais dans un texte, une donnée de démo ou un commentaire visible :
 * « Grenoble », « Aucun gain garanti », « certifié », « Qualiopi », « agréé »,
 * « labellisé », « accrédité ». Contrôle insensible à la casse ET aux accents
 * (« Certifie », « AGREE », « accréditée » tombent tous), sur `frontend/src/**`
 * et `backend/openapi/**`.
 *
 * Exceptions : une seule, écrite, motivée (voir `EXCEPTIONS`). En ajouter une
 * est une décision de revue.
 */
import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ICI = path.dirname(fileURLToPath(import.meta.url));
const RACINE_FRONT = path.resolve(ICI, '..', '..');
const RACINE_DEPOT = path.resolve(RACINE_FRONT, '..');
const DOSSIERS = [path.join(RACINE_FRONT, 'src'), path.join(RACINE_DEPOT, 'backend', 'openapi')];

/**
 * Chemin relatif au dépôt → motif. `fr-zones.ts` est le référentiel INSEE des
 * communes (code 38185) servi au ciblage géographique des campagnes : c'est une
 * donnée administrative, pas un texte de communication, et ce fichier
 * appartient à un autre lot. Signalé dans la PR DM0-DEMO.
 */
const EXCEPTIONS: Record<string, string> = {
  'frontend/src/features/collectes/fr-zones.ts': 'référentiel INSEE des communes (ciblage géographique)',
};

export const MOTIF_INTERDIT =
  /\b(grenoble|aucun gain garanti|certifi(?:e|ee|es|ees)|qualiopi|agree(?:e|s|es)?|labellis(?:e|ee|es|ees)|accredit(?:e|ee|es|ees))\b/gi;

export function sansAccents(texte: string): string {
  return texte.normalize('NFD').replace(/[̀-ͯ]/g, '');
}

export function interditsDans(texte: string): string[] {
  return [...sansAccents(texte).matchAll(MOTIF_INTERDIT)].map((m) => m[0]);
}

function lister(dossier: string, sortie: string[] = []): string[] {
  if (!existsSync(dossier)) return sortie;
  for (const entree of readdirSync(dossier)) {
    const complet = path.join(dossier, entree);
    if (statSync(complet).isDirectory()) lister(complet, sortie);
    else if (/\.(tsx?|mjs|js|json|ya?ml|css|html|md)$/.test(entree)) sortie.push(complet);
  }
  return sortie;
}

const fichiers = DOSSIERS.flatMap((d) => lister(d));

describe('interdits de communication', () => {
  it('a bien des fichiers sous les yeux, dans les deux dossiers', () => {
    expect(fichiers.length).toBeGreaterThan(150);
    expect(fichiers.some((f) => f.includes(`${path.sep}openapi${path.sep}`))).toBe(true);
    expect(fichiers.some((f) => f.includes(`${path.sep}demo${path.sep}`))).toBe(true);
  });

  it('aucun mot interdit dans frontend/src ni backend/openapi', () => {
    const fautes: string[] = [];
    for (const f of fichiers) {
      const relatif = path.relative(RACINE_DEPOT, f).split(path.sep).join('/');
      if (EXCEPTIONS[relatif] !== undefined) continue;
      const trouves = interditsDans(readFileSync(f, 'utf8'));
      if (trouves.length > 0) fautes.push(`${relatif} : ${[...new Set(trouves)].join(', ')}`);
    }
    expect(fautes).toEqual([]);
  });

  it('chaque exception vise un fichier qui existe encore', () => {
    const fantomes = Object.keys(EXCEPTIONS).filter((f) => !existsSync(path.join(RACINE_DEPOT, f)));
    expect(fantomes).toEqual([]);
  });

  it('TÉMOIN : voit les mots, quelles que soient la casse et les accents', () => {
    expect(interditsDans('Organisme CERTIFIÉ Qualiopi')).toEqual(['CERTIFIE', 'Qualiopi']);
    expect(interditsDans('formation agréée, accréditée et labellisée')).toHaveLength(3);
    expect(interditsDans('Salon de Grenoble ; aucun gain garanti')).toHaveLength(2);
    expect(interditsDans('Salon des artisans de Vienne')).toEqual([]);
  });
});
