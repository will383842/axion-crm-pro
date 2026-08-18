import tseslint from 'typescript-eslint';

/**
 * Configuration ESLint du paquet `workers`.
 *
 * POURQUOI ce fichier existe (mesuré le 2026-08-18) : il n'y en avait AUCUN,
 * alors que `package.json` porte un script `lint` et que `eslint` +
 * `typescript-eslint` sont en devDependencies. ESLint 10, faute de config
 * locale, remonte l'arborescence : sur le poste de développement il tombait
 * sur `C:\Users\willi\eslint.config.mjs` (config d'un projet React sans
 * rapport) et plantait — « Error while loading rule 'react/display-name' ».
 * En CI, où aucun ancêtre ne porte de config, la commande aurait échoué
 * autrement. Autrement dit : `pnpm lint` n'a jamais pu tourner ici, et le job
 * `workers` de la CI ne l'appelait pas.
 *
 * Choix : règles NON typées (`recommended`, pas `recommendedTypeChecked`).
 * Les règles typées exigent que chaque fichier linté appartienne au projet
 * TypeScript ; `eslint.config.mjs` et `vitest.config.ts` n'y sont pas, et
 * l'exécution entière d'ESLint échoue alors (piège déjà rencontré côté
 * `frontend`, cf. le commentaire de sa propre config).
 */
export default tseslint.config(
  { ignores: ['dist/**', 'node_modules/**', 'coverage/**'] },
  ...tseslint.configs.recommended,
  {
    files: ['**/*.ts'],
    languageOptions: {
      ecmaVersion: 2023,
      sourceType: 'module',
    },
    rules: {
      '@typescript-eslint/no-unused-vars': ['error', { argsIgnorePattern: '^_' }],
      '@typescript-eslint/consistent-type-imports': ['error', { prefer: 'type-imports' }],
      '@typescript-eslint/no-explicit-any': 'error',
      eqeqeq: ['error', 'smart'],
      // `ignoreReadBeforeAssign` : un `let` lu par une fermeture AVANT sa
      // propre affectation ne peut pas devenir `const` (motif « handle qui se
      // référence lui-même », courant dans les tests d'arrêt gracieux).
      'prefer-const': ['error', { ignoreReadBeforeAssign: true }],
      'no-console': 'error', // les workers journalisent par pino, jamais par console
    },
  },
  {
    // Fichiers de configuration : hors du projet TypeScript.
    files: ['*.mjs', '*.config.ts'],
    rules: { '@typescript-eslint/no-unused-vars': 'off' },
  },
);
