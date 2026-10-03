/**
 * La règle des nouveaux essais automatiques de `main.tsx` (audit UX P0-1,
 * relecture de #301).
 *
 * Les tests d'écran tournent avec un client `retry: false` : ils ne prouvent
 * RIEN sur la règle réellement branchée en production. On la teste donc
 * directement, et on vérifie qu'elle est bien celle que `main.tsx` branche.
 */
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { doitReessayer, NOUVEAUX_ESSAIS_MAX } from '@/lib/nouvel-essai';

const racine = path.dirname(fileURLToPath(import.meta.url));

/** Une erreur axios réduite à ce que la règle lit. */
function reponse(status: number): unknown {
  return { isAxiosError: true, response: { status } };
}

describe('doitReessayer', () => {
  it('409 (no_workspace, workspace_not_selected) : jamais de nouvel essai', () => {
    expect(doitReessayer(0, reponse(409))).toBe(false);
    expect(doitReessayer(1, reponse(409))).toBe(false);
  });

  it('401 et 403 : jamais de nouvel essai', () => {
    expect(doitReessayer(0, reponse(401))).toBe(false);
    expect(doitReessayer(0, reponse(403))).toBe(false);
  });

  it('500 : nouvel essai, deux fois au plus', () => {
    expect(doitReessayer(0, reponse(500))).toBe(true);
    expect(doitReessayer(1, reponse(500))).toBe(true);
    expect(doitReessayer(NOUVEAUX_ESSAIS_MAX, reponse(500))).toBe(false);
  });

  it('pas de réponse du serveur (réseau, délai) : nouvel essai', () => {
    expect(doitReessayer(0, new Error('Network Error'))).toBe(true);
    expect(doitReessayer(0, null)).toBe(true);
  });

  it('main.tsx branche CETTE règle, et aucune copie locale', () => {
    const source = readFileSync(path.resolve(racine, '../../src/main.tsx'), 'utf8');
    expect(source).toMatch(/retry:\s*doitReessayer\b/);
    expect(source).toContain("from './lib/nouvel-essai'");
  });
});
