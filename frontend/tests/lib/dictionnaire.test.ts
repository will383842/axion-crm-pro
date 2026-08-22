/**
 * D29-003 — LE DICTIONNAIRE NE PROMET PLUS CE QU'IL NE TIENT PAS.
 *
 * Mesure du 2026-08-22 : `src/locales/fr.json` portait 27 clés ; 15 n'étaient
 * appelées NULLE PART — dont les SEPT libellés de navigation (`nav.dashboard`,
 * `nav.companies`, `nav.contacts`, `nav.coverage`, `nav.scraperRuns`,
 * `nav.users`, `nav.settings`), alors que la barre latérale écrit ses 29
 * libellés en dur (`Sidebar.tsx`). Un dictionnaire qui contient une traduction
 * de la navigation fait croire que la navigation est traduisible : elle ne
 * l'était pas.
 *
 * DIRECTION TRANCHÉE, et écrite : les 15 clés mortes sont supprimées. Le
 * dictionnaire ne décrit plus que ce qui passe RÉELLEMENT par `t()` — les quatre
 * écrans d'authentification et les bouchons Phase 2. Si l'internationalisation
 * de la console revient au programme, ce sera l'autre direction (router les 29
 * libellés de `Sidebar.tsx` par `t('nav.*')` et compléter les deux
 * dictionnaires) — mais pas la moitié des deux, qui est l'état qu'on ferme ici.
 *
 * ⚠️ CETTE GARDE N'ÉNUMÈRE AUCUNE CLÉ À LA MAIN. Une liste recopiée serait vraie
 * le jour où on l'écrit et fausse à la clé suivante — c'est exactement le
 * mécanisme qui a produit le défaut. Elle aplatit les deux fichiers et cherche
 * chaque clé dans `src/`.
 */
import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const racine = path.dirname(fileURLToPath(import.meta.url));
const dossierSrc = path.resolve(racine, '../../src');

/**
 * Parcours récursif écrit à la main.
 *
 * ⚠️ Pas d'itérateur « tout fait » : ce dépôt a déjà payé un parcours qui
 * TRONQUAIT silencieusement (14 fichiers vus sur 56). Un parcours qui s'arrête
 * tôt rend une garde verte qui n'a rien inspecté.
 */
function fichiersSous(dossier: string, suffixes: string[]): string[] {
  const trouves: string[] = [];
  for (const entree of readdirSync(dossier)) {
    const complet = path.join(dossier, entree);
    if (statSync(complet).isDirectory()) {
      trouves.push(...fichiersSous(complet, suffixes));
    } else if (suffixes.some((s) => entree.endsWith(s))) {
      trouves.push(complet);
    }
  }
  return trouves;
}

function aplatir(objet: unknown, prefixe = ''): string[] {
  if (typeof objet !== 'object' || objet === null) return [prefixe];
  return Object.entries(objet as Record<string, unknown>).flatMap(([cle, valeur]) =>
    aplatir(valeur, prefixe === '' ? cle : `${prefixe}.${cle}`),
  );
}

function lireDictionnaire(langue: string): Record<string, unknown> {
  return JSON.parse(readFileSync(path.join(dossierSrc, 'locales', `${langue}.json`), 'utf8')) as Record<string, unknown>;
}

// Tout `src/` SAUF les dictionnaires eux-mêmes : ils se citent forcément.
const sourcesApplicatives = fichiersSous(dossierSrc, ['.ts', '.tsx'])
  .filter((f) => !f.includes(`${path.sep}locales${path.sep}`))
  .map((f) => readFileSync(f, 'utf8'))
  .join('\n');

function cleAppelee(cle: string): boolean {
  return (
    sourcesApplicatives.includes(`'${cle}'`) ||
    sourcesApplicatives.includes(`"${cle}"`) ||
    sourcesApplicatives.includes(`\`${cle}\``)
  );
}

describe('D29-003 — dictionnaire de traduction', () => {
  it('le parcours voit bien les fichiers du produit', () => {
    // Sans ce contrôle, un parcours qui rendrait zéro fichier ferait passer la
    // garde suivante au vert sans rien inspecter. Le dépôt a déjà payé ce piège.
    const nombre = fichiersSous(dossierSrc, ['.ts', '.tsx']).length;
    expect(
      nombre,
      `D29-003 : le parcours de \`src/\` ne rend que ${nombre} fichier(s) — il ` +
        'tronque. La garde ci-dessous serait verte sans avoir cherché. GESTE : ' +
        'corriger `fichiersSous` dans `tests/lib/dictionnaire.test.ts`.',
    ).toBeGreaterThan(50);
  });

  it('ne contient plus une seule clé morte', () => {
    const mortes = aplatir(lireDictionnaire('fr')).filter((cle) => !cleAppelee(cle));

    expect(
      mortes.length,
      `D29-003 : ${mortes.length} clé(s) du dictionnaire ne sont appelées nulle ` +
        `part : ${mortes.join(', ')}. Une clé morte fait croire qu’un pan du ` +
        'produit est traduit alors qu’il écrit ses libellés en dur — c’était le ' +
        'cas des sept libellés de navigation, face à une `Sidebar.tsx` entièrement ' +
        'en dur. GESTE : soit brancher réellement le libellé sur `t()`, soit ' +
        'retirer la clé de `src/locales/fr.json` ET `en.json`. Pas la moitié des ' +
        'deux.',
    ).toBe(0);
  });

  it('garde la parité exacte entre fr et en', () => {
    const fr = aplatir(lireDictionnaire('fr')).sort();
    const en = aplatir(lireDictionnaire('en')).sort();

    expect(
      en,
      'D29-003 : les deux dictionnaires ont divergé. Une clé présente d’un seul ' +
        'côté se rend comme la clé BRUTE dans l’autre langue (« common.error » à ' +
        'l’écran). GESTE : ajouter ou retirer la clé des DEUX fichiers de ' +
        '`src/locales/`.',
    ).toEqual(fr);
  });
});
