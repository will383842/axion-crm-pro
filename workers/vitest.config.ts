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
 */
export default defineConfig({
  test: {
    // `root` non précisé : Vitest prend le répertoire de CE fichier, donc
    // `workers/`. (`__dirname` n'existe pas ici — le paquet est en ESM.)
    environment: 'node',
    include: ['tests/**/*.test.ts'],
    exclude: ['node_modules/**', 'dist/**'],
    passWithNoTests: false,
    // Pas de `globals: true` : les tests importent explicitement depuis
    // 'vitest' (c'est déjà le cas des deux suites préexistantes).
    globals: false,
    testTimeout: 10_000,
  },
});
