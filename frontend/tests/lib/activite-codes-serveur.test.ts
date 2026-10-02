/**
 * GARDE — chaque code d'événement ÉCRIT PAR LE SERVEUR a une phrase dans
 * « Activité récente » (`src/features/dashboard/activite.ts`).
 *
 * Avis A09 du 2026-10-03 : six événements métier (MOT_DE_PASSE_MODIFIE,
 * GDPR_PURGE_PERSONNES…) n'avaient pas de phrase et auraient été masqués. Cette
 * garde lit le code du serveur (`backend/app`) et énumère les codes posés dans
 * `audit_logs.event_type` :
 *   - `'method' => 'CODE'` écrit en toutes lettres ;
 *   - dans un fichier qui écrit `'method' => $evenement`, chaque littéral
 *     `'CODE_EN_MAJUSCULES'` (c'est ainsi que les commandes passent leur code).
 * Les lignes de progression (`…_LOT`, `…_PAQUET`) sont exclues : le serveur les
 * écarte lui-même du fil (`AuditLogsController`, `metier=1`).
 */
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { describe, expect, it } from 'vitest';

import { EVENEMENTS, libelleActivite } from '@/features/dashboard/activite';

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

function codesEcritsParLeServeur(): string[] {
  const codes = new Set<string>();
  for (const chemin of fichiersPhp(racineBackendApp())) {
    const source = readFileSync(chemin, 'utf8');
    for (const m of source.matchAll(/'method'\s*=>\s*'([A-Z][A-Z0-9_]+)'/g)) codes.add(m[1] ?? '');
    if (/'method'\s*=>\s*\$evenement\b/.test(source)) {
      for (const m of source.matchAll(/'([A-Z]{2,}(?:_[A-Z0-9]+)+)'/g)) codes.add(m[1] ?? '');
    }
  }
  return [...codes].filter((c) => c !== '' && !/_(LOT|PAQUET)$/.test(c)).sort();
}

describe('Activité récente — une phrase pour chaque code écrit par le serveur', () => {
  const codes = codesEcritsParLeServeur();

  it('le relevé trouve bien les codes du serveur (témoin)', () => {
    expect(codes.length).toBeGreaterThan(15);
    expect(codes).toContain('MOT_DE_PASSE_MODIFIE');
    expect(codes).toContain('FUSION_FICHES_ANNULEE');
  });

  it('aucun code sans phrase', () => {
    const sansPhrase = codes.filter((c) => !(c in EVENEMENTS));
    expect(
      sansPhrase,
      'Code écrit par le serveur sans phrase : il s’afficherait « Autre opération ». ' +
        'GESTE : ajouter une phrase à `EVENEMENTS` dans `src/features/dashboard/activite.ts`.',
    ).toEqual([]);
  });

  it('chaque phrase est bien celle rendue par libelleActivite', () => {
    for (const c of codes) expect(libelleActivite(c, null)).toBe(EVENEMENTS[c]);
  });
});
