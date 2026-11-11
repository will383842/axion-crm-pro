/**
 * GARDE — aucun contrat d'API d'Envoi ne mesure l'ouverture des e-mails.
 *
 * La prospection n'utilise ni pixel ni suivi d'ouverture : les contrats
 * `backend/openapi/envoi/**` ne portent donc aucune clé, schéma, valeur,
 * exemple ou description qui en parle. L'indicateur reste les réponses.
 *
 * Règle simple et stricte : le mot « ouverture » (au singulier comme au
 * pluriel, entre guillemets ou non, en clé, en valeur, en énumération coupée
 * sur plusieurs lignes) est interdit PARTOUT. Seules deux tournures de
 * description sont tolérées : « suivi d'ouverture » et « ni d'ouverture »
 * (ex. « aucun suivi des clics, ni d'ouverture »), car elles ne font que
 * dire qu'il n'y en a pas.
 */
import { describe, it, expect } from 'vitest';
import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ICI = path.dirname(fileURLToPath(import.meta.url));
const SOURCES = path.resolve(ICI, '..', '..', '..', 'backend', 'openapi', 'envoi');

/** Seules tournures autorisées (négations dans une description). */
const TOURNURES_PERMISES = /(?:suivi|\bni)\s+d['’]ouverture/gi;

const INTERDITS: ReadonlyArray<[string, RegExp]> = [
  ['mot « ouverture » (toutes formes)', /ouvertures?/i],
  ['pixel', /pixel/i],
  ['automatique_probable', /automatique_probable/i],
  ['raison_automatique', /raison_automatique/i],
  ['opened_at / opened / open_', /\bopened(?:_at)?\b|\bopen_(?:count|rate|at)\b/i],
  ['suivre_ouvertures', /suivre_ouvertures/i],
];

/** Lignes fautives d'un texte de contrat (numéro + ligne), tournures permises retirées. */
export function lignesFautives(texte: string): string[] {
  const resultat: string[] = [];
  // Les tournures permises peuvent être coupées par un retour à la ligne : on
  // les retire sur tout le texte, en gardant les retours pour les numéros.
  const sansTournures = texte.replace(TOURNURES_PERMISES, (m) => m.replace(/[^\n]/g, ''));
  const originales = texte.split('\n');
  sansTournures.split('\n').forEach((nettoyee, i) => {
    const ligne = originales[i] ?? nettoyee;
    for (const [nom, motif] of INTERDITS) {
      if (motif.test(nettoyee)) {
        resultat.push(`${String(i + 1)}: [${nom}] ${ligne.trim()}`);
        break;
      }
    }
  });
  return resultat;
}

const fichiers = readdirSync(SOURCES).filter((f) => f.endsWith('.yaml'));

describe('contrats d\'envoi : aucune mesure des ouvertures', () => {
  it('trouve les contrats à contrôler', () => {
    expect(fichiers.length).toBeGreaterThan(0);
  });

  for (const fichier of fichiers) {
    it(`${fichier} ne parle d'aucune ouverture`, () => {
      const lignes = lignesFautives(readFileSync(path.join(SOURCES, fichier), 'utf8'));
      expect(lignes.map((l) => `${fichier}:${l}`)).toEqual([]);
    });
  }
});

describe('la garde sait rougir (chaque réintroduction est vue)', () => {
  const REINTRODUCTIONS: ReadonlyArray<[string, string]> = [
    ['ouverture_estimee: (clé)', '      ouverture_estimee:\n        type: boolean'],
    ['premiere_ouverture_le: (clé)', '      premiere_ouverture_le:\n        type: string'],
    ['\'ouverture\' entre guillemets simples', '      enum: [remis, \'ouverture\', reponse]'],
    ['"ouverture": entre guillemets doubles', '      "ouverture":\n        type: object'],
    ['ouverture: (clé nue)', '      ouverture:\n        $ref: x'],
    ['const: ouverture', '      type:\n        const: ouverture'],
    ['énumération entre crochets sur plusieurs lignes', '      enum: [\n        remis,\n        ouverture,\n        reponse,\n      ]'],
    ['liste YAML', '      enum:\n        - remis\n        - ouverture'],
    ['raison_automatique', '      raison_automatique:\n        type: string'],
    ['automatique_probable', '      automatique_probable:\n        type: boolean'],
    ['opened_at', '      opened_at:\n        type: string'],
    ['suivre_ouvertures', '      suivre_ouvertures:\n        type: boolean'],
    ['taux_ouverture', '      taux_ouverture:\n        type: number'],
    ['ResumeOuvertures', '    ResumeOuvertures:\n      type: object'],
    ['pixel', '    description: Un pixel invisible'],
    ['description positive', '    description: Mesure les ouvertures des messages.'],
  ];

  it.each(REINTRODUCTIONS)('rougit sur : %s', (_nom, extrait) => {
    expect(lignesFautives(extrait).length).toBeGreaterThan(0);
  });

  it.each([
    "    Aucun suivi des clics, ni d'ouverture.",
    '      alertes_ouvertes: { type: integer, minimum: 0 }',
    "    aucun suivi des clics, ni\n    d'ouverture. L'indicateur",
    '    aucun suivi d’ouverture',
    '    Aucun suivi des clics ni d\'ouverture. Lot : DM2-PIL.',
    '    description: Les réponses sont l\'indicateur principal.',
  ])('reste muette sur la tournure permise : %s', (ligne) => {
    expect(lignesFautives(ligne)).toEqual([]);
  });
});
