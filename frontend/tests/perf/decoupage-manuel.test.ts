/**
 * G42-005 — LE DÉCOUPAGE MANUEL NE DOIT ANNONCER QUE CE QU'IL PRODUIT.
 *
 * Mesure du 2026-08-22 sur le dernier build présent (`dist/assets/`) :
 * `vite.config.ts` déclarait un morceau `react: ['react', 'react-dom']`, et le
 * fichier produit `react-l0sNRNKZ.js` faisait 44 OCTETS — son contenu intégral
 * était la ligne `//# sourceMappingURL=…`. React et react-dom étaient restés
 * dans `index-Bi4ad1jA.js` (1 046 694 o). La forme objet de `manualChunks` ne
 * retient que le module exactement nommé, et le point d'entrée d'un paquet
 * CommonJS n'est qu'une façade : le code vit dans `react/cjs/…`.
 *
 * ⚠️ CE QUE CES GARDES NE PROUVENT PAS : elles ne construisent RIEN et ne
 * mesurent aucun poids de route. Elles lisent la configuration, et — seulement
 * si un build est présent dans `dist/` — comparent ce qui est annoncé à ce qui
 * a été produit.
 */
import { describe, it, expect } from 'vitest';
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const racine = path.dirname(fileURLToPath(import.meta.url));
const config = readFileSync(path.resolve(racine, '../../vite.config.ts'), 'utf8');
const dossierBuild = path.resolve(racine, '../../dist/assets');

/** Les noms de morceaux déclarés dans la forme OBJET de `manualChunks`. */
function morceauxDeclares(): string[] {
  const debut = config.indexOf('manualChunks: {');
  if (debut === -1) return [];
  const ouvrante = config.indexOf('{', debut);
  let profondeur = 1;
  let i = ouvrante + 1;
  while (i < config.length && profondeur > 0) {
    if (config[i] === '{') profondeur += 1;
    else if (config[i] === '}') profondeur -= 1;
    i += 1;
  }
  const corps = config.slice(ouvrante + 1, i - 1);
  return [...corps.matchAll(/^\s*([A-Za-z_$][\w$]*)\s*:/gm)].map((m) => m[1] as string);
}

describe('G42-005 — le découpage manuel du bundle', () => {
  it('G42-005 — n’annonce plus un morceau `react` que la forme objet ne sait pas produire', () => {
    expect(
      morceauxDeclares().includes('react'),
      'G42-005 : `manualChunks` redéclare une entrée `react` en forme OBJET. ' +
        'Mesuré le 2026-08-22 : cette forme produit un morceau de 44 octets ' +
        '(vide) et laisse react/react-dom dans `index`, parce qu’elle ne retient ' +
        'que la façade CommonJS du paquet. GESTE : soit retirer l’entrée, soit ' +
        'passer `manualChunks` en FONCTION (`id.includes("/node_modules/react-dom/")` ' +
        'puis `/node_modules/react/`) ET mesurer le build avant/après — un ' +
        'découpage mal posé duplique le runtime React 19 et casse les hooks.',
    ).toBe(false);
  });

  it.skipIf(!existsSync(dossierBuild))(
    'G42-005 — chaque morceau déclaré sort NON VIDE dans le build présent',
    () => {
      const fichiers = readdirSync(dossierBuild).filter((f) => f.endsWith('.js'));
      const vides = morceauxDeclares().filter((nom) => {
        const produits = fichiers.filter((f) => new RegExp(`^${nom}-[\\w-]+\\.js$`).test(f));
        // Absent OU réduit à son commentaire de source map : dans les deux cas,
        // la configuration annonce un découpage qui n'a pas eu lieu.
        return (
          produits.length === 0 ||
          produits.every((f) => statSync(path.join(dossierBuild, f)).size < 1024)
        );
      });

      expect(
        vides,
        'G42-005 : ces morceaux sont DÉCLARÉS dans `vite.config.ts` mais sortent ' +
          'absents ou vides du build de `frontend/dist/assets` — la configuration ' +
          'décrit un découpage qui n’a pas lieu, et toute revue qui s’y fie ' +
          'raisonne sur un bundle imaginaire. GESTE : retirer l’entrée, ou la ' +
          'réécrire en forme FONCTION et REMESURER le build. ' +
          '(Si `dist/` est un vieux build, le reconstruire avant de conclure.)',
      ).toEqual([]);
    },
  );
});
