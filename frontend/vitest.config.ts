import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react-swc';
import path from 'node:path';

export default defineConfig({
  // pnpm.overrides force vite@6 partout (cf package.json), pas de mismatch type.
  plugins: [react()],
  resolve: {
    alias: { '@': path.resolve(__dirname, './src') },
  },
  test: {
    globals: true,
    environment: 'jsdom',
    setupFiles: ['./tests/setup.ts'],
    // ⚠️ Renseigner `exclude` ÉCRASE la liste par défaut de vitest : sans les
    // doubles astérisques, un `node_modules` imbriqué (ou atteint par une
    // jonction NTFS) n'est plus écarté et la collecte part dans les dépendances.
    exclude: ['**/node_modules/**', '**/dist/**', 'tests/e2e/**'],

    /**
     * 5 000 ms (le défaut) ne suffit PAS pour les tests d'écran.
     *
     * Mesuré : `LoginPage › aiguille vers /2fa` et
     * `AudienceBuilderPage › nommer puis créer` passent seuls en ~1,8 s et
     * EXPIRENT dans la suite complète, où une vingtaine de fichiers jsdom
     * tournent en parallèle. Ce n'est pas un défaut d'écran, c'est la charge :
     * un écran réel enchaîne frappe `user-event` (une frappe = un rendu),
     * anti-rebond de 500 ms et aller-retour MSW.
     *
     * Un faux rouge intermittent coûte plus cher qu'une marge : on la prend.
     */
    testTimeout: 20_000,
    hookTimeout: 20_000,

    coverage: {
      provider: 'v8',
      reporter: ['text', 'html', 'lcov'],
      /**
       * ⚠️ SEUILS DÉCORATIFS EN L'ÉTAT. La CI
       * (`.github/workflows/ci.yml:295-297`) lance `pnpm test`, jamais
       * `pnpm test:coverage` : ces nombres ne bloquent rien et n'ont jamais
       * rien bloqué. Les rendre mordants suppose de changer le workflow (hors
       * périmètre de ce lot) — voir `tests/README.md`.
       */
      thresholds: { lines: 60, statements: 60, functions: 60, branches: 50 },
      exclude: ['node_modules/', 'dist/', '**/*.config.{ts,js}'],
    },
  },
});
