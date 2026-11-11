/**
 * GARDE — aucun contrat d'API d'Envoi ne mesure l'ouverture des e-mails.
 *
 * La prospection n'utilise ni pixel ni suivi d'ouverture : les contrats
 * `backend/openapi/envoi/**` ne portent donc aucune clé, schéma, exemple ou
 * description qui en parle. L'indicateur reste les réponses.
 */
import { describe, it, expect } from 'vitest';
import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ICI = path.dirname(fileURLToPath(import.meta.url));
const SOURCES = path.resolve(ICI, '..', '..', '..', 'backend', 'openapi', 'envoi');

const INTERDITS: ReadonlyArray<[string, RegExp]> = [
  ['suivre_ouvertures', /suivre_ouvertures/i],
  ['ouvertures', /ouvertures/i],
  ['ResumeOuvertures', /ResumeOuvertures/i],
  ['taux_ouverture', /taux_ouverture/i],
  ['pixel', /pixel/i],
  ['clé ouverture', /^\s*ouverture\s*:/im],
];

const fichiers = readdirSync(SOURCES).filter((f) => f.endsWith('.yaml'));

describe('contrats d\'envoi : aucune mesure des ouvertures', () => {
  it('trouve les contrats à contrôler', () => {
    expect(fichiers.length).toBeGreaterThan(0);
  });

  for (const fichier of fichiers) {
    for (const [nom, motif] of INTERDITS) {
      it(`${fichier} ne contient pas « ${nom} »`, () => {
        const lignes = readFileSync(path.join(SOURCES, fichier), 'utf8')
          .split('\n')
          .map((l, i) => ({ l, n: i + 1 }))
          .filter(({ l }) => motif.test(l))
          .map(({ l, n }) => `${fichier}:${n}: ${l.trim()}`);
        expect(lignes).toEqual([]);
      });
    }
  }
});
