import { describe, it, expect } from 'vitest';
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { useMockScrapers, drapeauSimulacresAmbigu } from '../src/config/mocks';

/**
 * GARDE — audit 360, C18-017 : le drapeau des simulacres.
 *
 * Mesure du 2026-08-22, sur le dépôt avant correction :
 *   · les ONZE workers portaient la MÊME ligne 7, recopiée mot pour mot ;
 *   · la bascule exige la chaîne EXACTE `'false'` — `0`, `off`, `FALSE`
 *     laissaient les simulacres actifs SANS RIEN DIRE ;
 *   · `MOCKS-STRATEGY.md` promettait « basculement en 1 ligne
 *     (`MOCK_MODE=false`) » alors que `MOCK_SCRAPERS` masque `MOCK_MODE` ;
 *   · `workers/src/scrapers/_stub.ts` n'était plus importé par personne.
 *
 * Ce qui est gardé ici, dans cet ordre de gravité :
 *   1. LE SIMULACRE NE PEUT PAS FUIR. C'est la seule propriété qui protège de
 *      l'appel réel facturé ; elle passe avant l'ergonomie du drapeau.
 *   2. La règle vit à UN SEUL endroit — pas onze.
 *   3. Une valeur ambiguë est détectable, donc dicible : c'est le silence qui
 *      coûtait cher, pas la sévérité de la règle.
 *   4. La documentation décrit les DEUX variables réellement lues.
 */

const WORKERS_DIR = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const REPO_ROOT = resolve(WORKERS_DIR, '..');

/** Lecture STRICTE : un fichier absent fait ROUGIR, il ne fait pas sauter le test. */
function lire(cheminRelatifAuDepot: string): string {
  const chemin = resolve(REPO_ROOT, cheminRelatifAuDepot);
  try {
    // Normalisation CRLF → LF : le dépôt est chargé en `\r\n` sous Windows, et
    // toute recherche d'un `\n` littéral y serait aveugle.
    return readFileSync(chemin, 'utf-8').replace(/\r\n/g, '\n');
  } catch {
    throw new Error(
      `C18-017 : fichier introuvable — ${chemin}. Si l'arborescence a bougé, corriger le chemin ; ` +
        'ne pas neutraliser la garde.',
    );
  }
}

describe('C18-017 — la règle du drapeau, mesurée', () => {
  it('sans aucune variable, le SIMULACRE est servi (il ne peut pas fuir)', () => {
    expect(
      useMockScrapers({}),
      "C18-017 : sans MOCK_SCRAPERS ni MOCK_MODE, les workers appellent les VRAIS services — trafic " +
        "sortant, proxies et captchas facturés sur un simple oubli de configuration. Rétablir le défaut " +
        "'true' dans workers/src/config/mocks.ts.",
    ).toBe(true);
  });

  it("seule la chaîne exacte 'false' bascule vers les vrais services", () => {
    expect(useMockScrapers({ MOCK_SCRAPERS: 'false' })).toBe(false);
    expect(useMockScrapers({ MOCK_MODE: 'false' })).toBe(false);
    expect(useMockScrapers({ MOCK_SCRAPERS: 'true' })).toBe(true);
  });

  it('MOCK_SCRAPERS MASQUE MOCK_MODE — et c’est ce que la doc doit dire', () => {
    expect(
      useMockScrapers({ MOCK_SCRAPERS: 'true', MOCK_MODE: 'false' }),
      'C18-017 : la priorité entre les deux poignées a changé. MOCK_SCRAPERS doit primer sur MOCK_MODE, ' +
        'sinon MOCKS-STRATEGY.md et workers/src/config/mocks.ts se contredisent.',
    ).toBe(true);
  });

  it("une valeur ambiguë ('0', 'off', 'FALSE') reste au simulacre, mais est DÉTECTÉE", () => {
    for (const valeur of ['0', 'off', 'FALSE', 'no', '']) {
      expect(
        useMockScrapers({ MOCK_MODE: valeur }),
        `C18-017 : MOCK_MODE=${valeur} bascule désormais vers les VRAIS scrapers. C'est une décision ` +
          "d'exploitation (coût, trafic sortant), pas un correctif : si elle est voulue, elle s'annonce " +
          'et se documente dans MOCKS-STRATEGY.md avant d’être codée.',
      ).toBe(true);

      expect(
        drapeauSimulacresAmbigu({ MOCK_MODE: valeur }),
        `C18-017 : MOCK_MODE=${valeur} redevient SILENCIEUX. Celui qui l'a posé croit avoir branché les ` +
          "vrais services et récolte des fixtures. Rétablir l'avertissement de workers/src/config/mocks.ts.",
      ).toBe(true);
    }

    // Et l'avertissement ne doit pas devenir du bruit sur les valeurs claires.
    expect(drapeauSimulacresAmbigu({ MOCK_SCRAPERS: 'true' })).toBe(false);
    expect(drapeauSimulacresAmbigu({ MOCK_SCRAPERS: 'false' })).toBe(false);
    expect(drapeauSimulacresAmbigu({})).toBe(false);
  });
});

describe('C18-017 — la règle vit à un seul endroit', () => {
  // ÉNUMÉRATION INTERDITE : la liste des workers est LUE sur le disque. Une
  // garde qui recopierait les onze noms resterait verte sur le douzième.
  const workers = readdirSync(resolve(WORKERS_DIR, 'src/scrapers')).filter((f) => f.endsWith('.worker.ts'));

  it('il y a bien des workers à inspecter', () => {
    expect(
      workers.length,
      'C18-017 : aucun `*.worker.ts` trouvé dans workers/src/scrapers — cette garde ne mesure plus rien ' +
        'tout en restant verte. Vérifier le chemin avant de la modifier.',
    ).toBeGreaterThanOrEqual(11);
  });

  it.each(workers)('%s ne relit pas le drapeau lui-même', (fichier) => {
    const src = lire(`workers/src/scrapers/${fichier}`);

    expect(
      /process\.env\[['"]MOCK_(SCRAPERS|MODE)['"]\]/.test(src),
      `C18-017 : ${fichier} relit MOCK_SCRAPERS/MOCK_MODE en direct. La règle est alors recopiée dans ` +
        'plusieurs fichiers et dérivera. Appeler `useMockScrapers()` de ../config/mocks.',
    ).toBe(false);

    expect(
      src.includes('useMockScrapers'),
      `C18-017 : ${fichier} ne décide plus des simulacres via \`useMockScrapers()\`. Si ce worker n'a ` +
        'plus de variante simulée, retirer aussi le fichier de cette garde en le disant explicitement.',
    ).toBe(true);
  });

  it('le worker mort `_stub.ts` n’est pas revenu', () => {
    // Il exportait `stubWorker`, que plus AUCUN fichier n'importait (mesure du
    // 2026-08-22). Un stub que personne n'appelle donne à croire que des
    // sources restent à implémenter alors que les onze le sont.
    expect(
      existsSync(resolve(WORKERS_DIR, 'src/scrapers/_stub.ts')),
      'C18-017 : `workers/src/scrapers/_stub.ts` est de retour. Si un stub est réellement nécessaire, ' +
        "l'importer quelque part — un fichier que personne n'appelle est une fausse piste, pas un filet.",
    ).toBe(false);
  });
});

describe('C18-017 — la documentation décrit les deux variables', () => {
  const doc = lire('MOCKS-STRATEGY.md');

  it('elle ne promet plus « en 1 ligne (MOCK_MODE=false) »', () => {
    expect(
      /basculement vers vrais services en 1 ligne/i.test(doc),
      'C18-017 : MOCKS-STRATEGY.md promet à nouveau un basculement « en 1 ligne (MOCK_MODE=false) ». Il ' +
        'y a DEUX poignées et la première masque la seconde : décrire les deux, ou la promesse est fausse.',
    ).toBe(false);
  });

  it('elle nomme MOCK_SCRAPERS et MOCK_MODE, et la priorité entre les deux', () => {
    for (const attendu of ['MOCK_SCRAPERS', 'MOCK_MODE', 'workers/src/config/mocks.ts']) {
      expect(
        doc.includes(attendu),
        `C18-017 : MOCKS-STRATEGY.md ne mentionne plus « ${attendu} ». L'exploitant ne peut pas deviner ` +
          'quelle variable il doit poser, ni où la règle est écrite.',
      ).toBe(true);
    }
  });
});
