// @vitest-environment node
/**
 * GARDE — le contrat d'API d'Envoi et ses types générés ne divergent jamais.
 *
 * Les écrans d'Envoi tirent leurs types de `src/features/envoi/api/<zone>.d.ts`,
 * générés depuis `backend/openapi/envoi/<zone>.yaml` par `pnpm api:envoi`. Si
 * quelqu'un modifie une description sans régénérer (ou retouche un `.d.ts` à
 * la main), les écrans compilent contre un contrat qui n'existe pas : le
 * branchement sur le vrai serveur (lot BR) le découvrirait trop tard.
 *
 * Deux règles :
 *  · régénérer les types ne change RIEN (`--verifier`) ;
 *  · chaque document est un OpenAPI 3.1 valide (validation Redocly « minimal »
 *    faite par openapi-typescript, plus la version exacte 3.1.x).
 */
import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import { execFileSync } from 'node:child_process';
import { cpSync, mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ICI = path.dirname(fileURLToPath(import.meta.url));
const RACINE_FRONT = path.resolve(ICI, '..', '..');
const RACINE_DEPOT = path.resolve(RACINE_FRONT, '..');
const SCRIPT = path.join(RACINE_FRONT, 'scripts', 'generer-api-envoi.mjs');
const SOURCES = path.join(RACINE_DEPOT, 'backend', 'openapi', 'envoi');
const TYPES = path.join(RACINE_FRONT, 'src', 'features', 'envoi', 'api');

const ZONES = ['commun', 'socle', 'campagnes', 'creation', 'pilotage', 'reponses', 'infrastructure', 'conformite'];

function lancer(args: string[]): { code: number; sortie: string } {
  try {
    const sortie = execFileSync(process.execPath, [SCRIPT, ...args], { cwd: RACINE_FRONT, encoding: 'utf8', stdio: 'pipe' });
    return { code: 0, sortie };
  } catch (e) {
    const err = e as { status?: number; stdout?: string; stderr?: string };
    return { code: err.status ?? 1, sortie: `${err.stdout ?? ''}${err.stderr ?? ''}` };
  }
}

let temporaire = '';
beforeAll(() => {
  temporaire = mkdtempSync(path.join(tmpdir(), 'contrat-envoi-'));
});
afterAll(() => {
  if (temporaire !== '') rmSync(temporaire, { recursive: true, force: true });
});

describe('contrat d’Envoi — fichiers présents', () => {
  it('une description par zone, et un fichier de types par description', () => {
    const yaml = readdirSync(SOURCES).filter((f) => f.endsWith('.yaml')).map((f) => f.replace('.yaml', '')).sort();
    expect(yaml).toEqual([...ZONES].sort());
    const dts = readdirSync(TYPES).filter((f) => f.endsWith('.d.ts')).map((f) => f.replace('.d.ts', '')).sort();
    expect(dts).toEqual([...ZONES].sort());
  });

  it('chaque document de zone est un OpenAPI 3.1 qui référence commun.yaml', () => {
    for (const zone of ZONES) {
      const texte = readFileSync(path.join(SOURCES, `${zone}.yaml`), 'utf8');
      expect(texte, zone).toMatch(/^openapi: ['"]?3\.1\.\d+['"]?$/m);
      expect(texte, zone).toMatch(/^info:$/m);
      expect(texte, zone).toMatch(/^paths:/m);
      if (zone !== 'commun') expect(texte, zone).toContain('commun.yaml#/components/');
    }
  });

  it('le contrat prévoit la mesure des ouvertures (décision de Will)', () => {
    const commun = readFileSync(path.join(SOURCES, 'commun.yaml'), 'utf8');
    expect(commun).toContain('suivre_ouvertures');
    expect(commun).toContain('automatique_probable');
    expect(commun).toMatch(/estimation/i);
  });
});

describe('contrat d’Envoi — à jour et valide', () => {
  it('régénérer les types ne change rien, et tout document est valide', () => {
    const { code, sortie } = lancer(['--verifier']);
    expect(sortie).toContain('8 documents');
    expect(code, sortie).toBe(0);
  }, 60_000);

  it('TÉMOIN : un type retouché à la main est vu', () => {
    const sources = path.join(temporaire, 'sources');
    const cible = path.join(temporaire, 'types');
    cpSync(SOURCES, sources, { recursive: true });
    cpSync(TYPES, cible, { recursive: true });
    writeFileSync(path.join(cible, 'socle.d.ts'), '// retouché\n', 'utf8');
    const { code, sortie } = lancer(['--verifier', '--source', sources, '--cible', cible]);
    expect(code).not.toBe(0);
    expect(sortie).toContain('socle');
  }, 60_000);

  it('TÉMOIN : un document invalide est refusé', () => {
    const sources = path.join(temporaire, 'invalide');
    const cible = path.join(temporaire, 'types-invalide');
    cpSync(SOURCES, sources, { recursive: true });
    cpSync(TYPES, cible, { recursive: true });
    // Une réponse sans description et un type inconnu : structure OpenAPI fausse.
    writeFileSync(
      path.join(sources, 'socle.yaml'),
      "openapi: 3.1.0\ninfo:\n  title: x\n  version: '1'\npaths:\n  /x:\n    get:\n      responses:\n        '200':\n          content: {}\n          type: 12\n",
      'utf8',
    );
    const { code } = lancer(['--verifier', '--source', sources, '--cible', cible]);
    expect(code).not.toBe(0);
  }, 60_000);

  it('TÉMOIN : un document en OpenAPI 3.0 est refusé', () => {
    const sources = path.join(temporaire, 'version');
    const cible = path.join(temporaire, 'types-version');
    cpSync(SOURCES, sources, { recursive: true });
    cpSync(TYPES, cible, { recursive: true });
    const texte = readFileSync(path.join(sources, 'socle.yaml'), 'utf8').replace(/^openapi: .*$/m, 'openapi: 3.0.3');
    writeFileSync(path.join(sources, 'socle.yaml'), texte, 'utf8');
    const { code, sortie } = lancer(['--verifier', '--source', sources, '--cible', cible]);
    expect(code).not.toBe(0);
    expect(sortie).toContain('3.1');
  }, 60_000);
});
