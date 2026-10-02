/**
 * D23-006 — LE FIL D'ARIANE NE DOIT PLUS PARLER ANGLAIS.
 *
 * Mesure du 2026-08-22 : la table `LABELS` d'`AutoBreadcrumbs` couvrait 18
 * chemins ; DIX routes déclarées dans `routeTree.tsx` n'y figuraient pas et
 * retombaient sur `humanize()`, qui rend le segment d'URL brut — « Media »,
 * « Journalists », « Tags », « Admin › Observability », « Console › … ».
 *
 * ⚠️ CETTE GARDE N'ÉNUMÈRE RIEN À LA MAIN. Une liste de chemins recopiée ici
 * serait vraie le jour où on l'écrit et fausse à la route suivante — c'est
 * précisément le mécanisme qui a produit le défaut. Elle LIT
 * `src/app/routeTree.tsx` et exige un libellé pour chaque enfant de
 * `layoutRoute` : une route ajoutée demain sans libellé fera rougir cette garde
 * le jour même.
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { LIBELLES_DE_CHEMIN, SEGMENTS_OMIS, libelleDeChemin } from '@/components/layout/AutoBreadcrumbs';

const racine = path.dirname(fileURLToPath(import.meta.url));
const sourceArbre = readFileSync(path.resolve(racine, '../../src/app/routeTree.tsx'), 'utf8');

/**
 * Les chemins des routes rendues DANS la coquille — les seules où le fil
 * d'Ariane s'affiche. Les écrans d'authentification et le 404 sont enfants de
 * `rootRoute` : ils n'ont pas de fil, et n'ont donc pas de libellé à porter.
 */
function cheminsDeLaCoquille(): string[] {
  const motif = /getParentRoute:\s*\(\)\s*=>\s*layoutRoute,\s*path:\s*'([^']+)'/g;
  const chemins: string[] = [];
  for (const trouve of sourceArbre.matchAll(motif)) {
    if (trouve[1] !== undefined) chemins.push(trouve[1]);
  }
  return chemins;
}

/**
 * Un segment `$param` porte une valeur (un identifiant), pas un nom d'écran :
 * `humanize()` le rend « #a1b2c3d4 », ce qui est le comportement voulu. On
 * n'exige donc de libellé que pour le préfixe STATIQUE du chemin.
 */
function prefixeStatique(chemin: string): string {
  const segments = chemin.split('/').filter(Boolean);
  const gardes: string[] = [];
  for (const segment of segments) {
    if (segment.startsWith('$')) break;
    gardes.push(segment);
  }
  return `/${gardes.join('/')}`;
}

describe('D23-006 — table des libellés du fil d’Ariane', () => {
  it('trouve bien les routes de la coquille dans routeTree.tsx', () => {
    // Sans ce contrôle, une évolution de l'écriture de `routeTree.tsx` rendrait
    // la liste vide et le test suivant passerait au vert sans rien vérifier.
    expect(
      cheminsDeLaCoquille().length,
      'D23-006 : l’extraction des routes de `src/app/routeTree.tsx` ne rend plus ' +
        'rien — le motif de lecture ne correspond plus à l’écriture du fichier. ' +
        'Sans correction, la garde ci-dessous serait verte sans inspecter quoi ' +
        'que ce soit. GESTE : ajuster le motif dans ' +
        '`tests/components/fil-d-ariane.test.tsx`.',
    ).toBeGreaterThan(20);
  });

  it('donne un libellé français à CHAQUE route de la coquille', () => {
    const sansLibelle = [...new Set(cheminsDeLaCoquille().map(prefixeStatique))]
      // Un préfixe déclaré SANS ÉCRAN (`/console/personnes` devant l'identifiant
      // d'une fiche) est omis du fil : il n'a pas de libellé à porter.
      .filter((chemin) => LIBELLES_DE_CHEMIN[chemin] === undefined && !SEGMENTS_OMIS.has(chemin));

    expect(
      sansLibelle.length,
      `D23-006 : ${sansLibelle.length} route(s) de la coquille n’ont aucun ` +
        `libellé de fil d’Ariane : ${sansLibelle.join(', ')}. Le fil retombe ` +
        'alors sur `humanize()` et affiche le segment d’URL brut — de l’anglais ' +
        'dans un produit français. GESTE : ajouter chacune à la table `LABELS` ' +
        'de `src/components/layout/AutoBreadcrumbs.tsx`, avec le MÊME mot que ' +
        'celui de la barre latérale (`Sidebar.tsx`).',
    ).toBe(0);
  });

  it('nomme aussi les segments intermédiaires qui apparaissent dans le fil', () => {
    // `/admin/observability` fait apparaître « Administration » avant l'écran :
    // un segment intermédiaire sans libellé rendait « Admin ».
    const intermediaires = new Set<string>();
    for (const chemin of cheminsDeLaCoquille().map(prefixeStatique)) {
      const segments = chemin.split('/').filter(Boolean);
      let acc = '';
      for (let i = 0; i < segments.length - 1; i += 1) {
        acc += `/${segments[i]}`;
        intermediaires.add(acc);
      }
    }
    // Lot 2 UX : un segment intermédiaire SANS écran (`/llm`, `/console`…)
    // n'a plus de libellé — il est omis du fil (un libellé le rendait
    // cliquable vers une page introuvable). Il doit alors être DÉCLARÉ omis.
    const orphelins = [...intermediaires].filter(
      (c) => LIBELLES_DE_CHEMIN[c] === undefined && !SEGMENTS_OMIS.has(c),
    );

    expect(
      orphelins.length,
      `D23-006 : ${orphelins.length} segment(s) intermédiaire(s) sans libellé : ` +
        `${orphelins.join(', ')}. Ils s’affichent pourtant dans le fil, avant ` +
        'l’écran. GESTE : les ajouter à `LABELS` dans ' +
        '`src/components/layout/AutoBreadcrumbs.tsx`.',
    ).toBe(0);
  });

  it('libelleDeChemin rend le libellé de la table, pas le segment brut', () => {
    expect(libelleDeChemin('/admin/observability')).toBe('Santé du système');
    expect(libelleDeChemin('/console/vivier')).toBe('Candidats');
    // Un identifiant ne nomme rien : le fil dit « Fiche », plus « #42 ».
    expect(libelleDeChemin('/companies/42')).toBe('Fiche');
  });

  it('aucun segment omis n’est une vraie route (sinon il manquerait au fil)', () => {
    // Chemins déclarés EXACTEMENT comme route — pas les préfixes statiques :
    // `/console/personnes` n'existe que devant `$personKey`, sans écran à lui.
    const exactes = new Set(cheminsDeLaCoquille());
    const fautifs = [...SEGMENTS_OMIS].filter((c) => exactes.has(c) || LIBELLES_DE_CHEMIN[c] !== undefined);
    expect(
      fautifs,
      'Lot 2 UX : un segment déclaré « sans écran » est en fait une route (ou a un ' +
        'libellé). GESTE : le retirer de `SEGMENTS_SANS_ECRAN` dans ' +
        '`src/components/layout/AutoBreadcrumbs.tsx`.',
    ).toEqual([]);
  });

  it('aucun libellé ne vise un chemin qui n’est pas une route (pas de lien mort)', () => {
    // Un chemin de la table devient un LIEN quand il est intermédiaire. S'il
    // n'est pas une route, le lien mène à la page introuvable — le défaut
    // P1-3 de l'audit (« Console CRM », « Relations presse », « LLM »).
    const routes = new Set(cheminsDeLaCoquille());
    // Redirections conservées par signet (voir le commentaire de la table).
    const redirections = new Set(['/cold-email', '/linkedin', '/pas-encore-livre']);
    const morts = Object.keys(LIBELLES_DE_CHEMIN).filter((c) => !routes.has(c) && !redirections.has(c));
    expect(morts, 'Libellé de fil d’Ariane sans route : lien mort garanti.').toEqual([]);
  });
});
