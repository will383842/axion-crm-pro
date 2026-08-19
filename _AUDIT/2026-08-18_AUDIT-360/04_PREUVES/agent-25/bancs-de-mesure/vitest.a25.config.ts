import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react-swc';
import path from 'node:path';

const racine = path.resolve(__dirname, '../..');

export default defineConfig({
  root: racine,
  plugins: [react()],
  resolve: { alias: { '@': path.resolve(racine, './src') } },
  test: {
    globals: true,
    environment: 'jsdom',
    // Le socle du dépôt EN PREMIER (MSW, cleanup, i18n), puis mon complément.
    setupFiles: ['./tmp/agent25/setup-a25.ts', './tests/setup.ts'],
    include: ['tmp/agent25/**/*.test.tsx', 'tmp/agent25/**/*.test.ts'],
    exclude: ['**/node_modules/**', '**/dist/**'],
    testTimeout: 30_000,
    hookTimeout: 30_000,
    fileParallelism: false,
  },
});
