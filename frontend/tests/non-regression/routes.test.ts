/**
 * NR-20 (côté frontend) — chaque appel d'API de l'écran vise une route qui existe.
 *
 * La photo des routes du serveur est rangée par le filet Pest :
 * `backend/tests/Feature/NonRegression/instantanes/nr20-routes.json`. Ce test
 * relève, dans `src/`, tous les appels `api.get|post|put|patch|delete(...)` à
 * chemin lisible et exige qu'ils tombent sur une route de la photo (méthode et
 * chemin, paramètres `{x}` et `${...}` compris).
 *
 * Les écarts déjà présents sont FIGÉS dans `ECARTS_CONNUS` : le filet constate,
 * il ne corrige pas. Ajouter un NOUVEL appel sans route fait rougir ; corriger
 * un écart connu oblige à le retirer de la liste.
 */
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const RACINE = resolve(__dirname, '../..');
const PHOTO = resolve(RACINE, '../backend/tests/Feature/NonRegression/instantanes/nr20-routes.json');

/** Appels sans route dans la photo à la date du filet (R0). À ne faire que BAISSER. */
// POST propositions/{id}/{action} : le dernier segment est choisi à l'exécution (accepter ou refuser) ;
// le relevé statique ne peut pas le résoudre, ce n'est pas une route manquante.
const ECARTS_CONNUS: string[] = ['POST /api/v1/crm/propositions/{p}/{p}'];

function fichiers(dossier: string): string[] {
  return readdirSync(dossier).flatMap((nom) => {
    const chemin = join(dossier, nom);
    if (statSync(chemin).isDirectory()) return fichiers(chemin);
    return /\.(ts|tsx)$/.test(nom) ? [chemin] : [];
  });
}

function enMotif(chemin: string): RegExp {
  const sansParametres = chemin
    .split('/')
    .map((segment) => (/^\{.+\}$/.test(segment) ? '[^/]+' : segment.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')))
    .join('/');
  return new RegExp(`^${sansParametres}$`);
}

function appels(): { cle: string; methode: string; chemin: string }[] {
  const trouves = new Map<string, { cle: string; methode: string; chemin: string }>();
  for (const fichier of fichiers(join(RACINE, 'src'))) {
    const source = readFileSync(fichier, 'utf8');
    const motif = /\bapi\.(get|post|put|patch|delete)\s*(?:<[^>(]*(?:<[^>]*>[^>(]*)*>)?\(\s*([`'"])([^`'"]*)\2/g;
    for (const m of source.matchAll(motif)) {
      const methode = (m[1] ?? '').toUpperCase();
      let chemin = (m[3] ?? '').replace(/\$\{[^}]*\}/g, '{p}').split('?')[0] ?? '';
      if (!chemin.startsWith('/')) continue;
      chemin = `/api/v1${chemin}`.replace(/\/+$/, '');
      const cle = `${methode} ${chemin}`;
      trouves.set(cle, { cle, methode, chemin });
    }
  }
  return [...trouves.values()];
}

describe('NR-20 : les appels du frontend visent des routes du serveur', () => {
  const photo = JSON.parse(readFileSync(PHOTO, 'utf8')) as string[];
  const routes = photo.map((ligne) => {
    const [methode, reste] = ligne.split(' | ')[0]!.split(' ');
    return { methode: methode!, motif: enMotif(reste!) };
  });

  it('la photo des routes du serveur est lisible et non vide', () => {
    expect(routes.length).toBeGreaterThan(150);
  });

  it('le relevé voit les appels du frontend (témoin de couverture)', () => {
    expect(appels().length).toBeGreaterThan(80);
  });

  it('chaque appel a sa route (hors écarts connus figés)', () => {
    const sansRoute = appels()
      .filter((a) => !routes.some((r) => r.methode === a.methode && r.motif.test(a.chemin)))
      .map((a) => a.cle)
      .sort();
    expect(sansRoute).toEqual([...ECARTS_CONNUS].sort());
  });
});
