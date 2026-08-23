import { defineConfig } from 'vitest/config';

/**
 * Configuration Vitest EXPLICITE du paquet `workers`.
 *
 * POURQUOI ce fichier existe (mesuré le 2026-08-18) : il n'y en avait aucun.
 * Vitest, faute de config dans `workers/`, REMONTE l'arborescence jusqu'à en
 * trouver une — y compris HORS du dépôt. Sur le poste de développement, il
 * tombait sur `C:\Users\willi\vitest.config.ts` (config d'un tout autre
 * projet), dont les globs sont `src/**\/*.{test,spec}` + `tests/unit/**` +
 * `tests/schemas/**` : AUCUN des tests de `workers/tests/*.test.ts` n'était
 * collecté, et `--passWithNoTests` rendait la commande VERTE avec zéro test.
 *
 * Autrement dit : ce qui était testé dépendait de répertoires situés en dehors
 * du dépôt. Une config explicite supprime cette dépendance à l'environnement.
 *
 * `passWithNoTests: false` : une suite vide DOIT rougir. C'est la garde qui
 * remplace le `--passWithNoTests` retiré du script `test`.
 *
 * 🔴 CONSTAT H44-010 (S3), corrigé le 2026-08-22. La collecte était bornée à
 * `tests/` : un `*.test.ts` posé sous `workers/src/` n'aurait JAMAIS été
 * collecté, et rien ne l'aurait dit.
 *
 * C'est le petit frère du défaut ci-dessus, et il est plus sournois :
 * `passWithNoTests: false` fait rougir une suite VIDE, mais une suite
 * PARTIELLE — ceux de `tests/` jouent, ceux de `src/` sont ignorés — reste
 * verte en silence. Une suite verte qui n'a pas joué la moitié de ses tests est
 * exactement le vert menteur que ce dépôt traque partout ailleurs.
 *
 * Mesure du 2026-08-22, AVANT d'élargir : `find workers/src -name "*.test.ts"`
 * ne rend aucun fichier. Le défaut était donc LATENT, et l'élargissement ne
 * fait entrer aucun fichier nouveau dans la collecte aujourd'hui — il ne peut
 * pas faire rougir la suite pour une raison sans rapport.
 *
 * ⚠️ Le risque de l'élargissement est réel, et le voici nommé : un fichier
 * d'aide ou de fixture nommé `*.test.ts` entrerait désormais dans la collecte.
 * L'`exclude` ci-dessous est la seule barrière — s'il faut l'étendre, l'étendre
 * LÀ. Re-rétrécir l'`include` rouvrirait le constat.
 *
 * Garde : `tests/collecte-vitest-complete.test.ts`, qui rougit si un
 * `*.test.ts` du paquet échappe à ces globs.
 */
export default defineConfig({
  test: {
    // `root` non précisé : Vitest prend le répertoire de CE fichier, donc
    // `workers/`. (`__dirname` n'existe pas ici — le paquet est en ESM.)
    environment: 'node',
    include: ['**/*.test.ts'],
    exclude: ['node_modules/**', 'dist/**', 'coverage/**'],
    passWithNoTests: false,
    // Pas de `globals: true` : les tests importent explicitement depuis
    // 'vitest' (c'est déjà le cas des deux suites préexistantes).
    globals: false,
    testTimeout: 10_000,
  },
});
