/**
 * GARDE — chaque action du JOURNAL MÉTIER écrite par le serveur
 * (`business_events.action`, via `AuditLogger::log`) a une phrase dans le
 * dictionnaire partagé (`src/features/dashboard/activite.ts`), lu par
 * « Santé du système › 50 derniers événements » (2026-10-03).
 *
 * Relevé dans `backend/app` :
 *   - `AuditLogger::log('code.metier', …)` écrit en toutes lettres ;
 *   - `$this->journal('code.metier', …)` (listes manuelles) ;
 *   - `const EVENEMENT… = 'code.metier'` (commandes) ;
 *   - `? 'code.a' : 'code.b'` passé à `AuditLogger::log` (audiences).
 * Sans phrase, l'action s'afficherait « Autre opération ».
 */
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { describe, expect, it } from 'vitest';

import { EVENEMENTS } from '@/features/dashboard/activite';
import { libelleEvenementMetier } from '@/features/observability/libelles';

function racineBackendApp(): string {
  let courant = process.cwd();
  for (let i = 0; i < 6; i += 1) {
    const candidat = join(courant, 'backend', 'app');
    if (existsSync(candidat)) return candidat;
    const parent = dirname(courant);
    if (parent === courant) break;
    courant = parent;
  }
  throw new Error('Dossier backend/app introuvable : la garde ne peut rien vérifier.');
}

function fichiersPhp(dossier: string, acc: string[] = []): string[] {
  for (const entree of readdirSync(dossier)) {
    const chemin = join(dossier, entree);
    if (statSync(chemin).isDirectory()) fichiersPhp(chemin, acc);
    else if (chemin.endsWith('.php')) acc.push(chemin);
  }
  return acc;
}

const CODE = "'([a-z_]+(?:\\.[a-z_]+)+)'";

function actionsEcritesParLeServeur(): string[] {
  const codes = new Set<string>();
  const motifs = [
    new RegExp(`AuditLogger::log\\(\\s*${CODE}`, 'g'),
    new RegExp(`->journal\\(\\s*${CODE}`, 'g'),
    new RegExp(`const\\s+EVENEMENT\\w*\\s*=\\s*${CODE}`, 'g'),
  ];
  for (const chemin of fichiersPhp(racineBackendApp())) {
    const source = readFileSync(chemin, 'utf8');
    if (!source.includes('AuditLogger::log')) continue;
    for (const motif of motifs) {
      for (const m of source.matchAll(motif)) codes.add(m[1] ?? '');
    }
    for (const m of source.matchAll(new RegExp(`\\?\\s*${CODE}\\s*:\\s*${CODE}`, 'g'))) {
      codes.add(m[1] ?? '');
      codes.add(m[2] ?? '');
    }
  }
  return [...codes].filter((c) => c !== '').sort();
}

describe('Journal métier — une phrase pour chaque action écrite par le serveur', () => {
  const codes = actionsEcritesParLeServeur();

  it('le relevé trouve bien les actions du serveur (témoin)', () => {
    expect(codes).toContain('audience.refreshed');
    expect(codes).toContain('audience.refresh.failed');
    expect(codes).toContain('company.tags_synced');
    expect(codes).toContain('liste_manuelle.creee');
    expect(codes).toContain('company.presse_provenance_levee');
  });

  it('aucune action sans phrase', () => {
    const sansPhrase = codes.filter((c) => !(c in EVENEMENTS));
    expect(
      sansPhrase,
      'Action du journal métier sans phrase : elle s’afficherait « Autre opération ». ' +
        'GESTE : ajouter une phrase à `EVENEMENTS` dans `src/features/dashboard/activite.ts`.',
    ).toEqual([]);
  });

  it('chaque phrase est bien celle rendue à l’écran', () => {
    for (const c of codes) expect(libelleEvenementMetier(c)).toBe(EVENEMENTS[c]);
  });
});
