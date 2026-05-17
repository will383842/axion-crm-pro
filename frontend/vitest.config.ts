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
    exclude: ['node_modules', 'dist', 'tests/e2e/**'],
    coverage: {
      provider: 'v8',
      reporter: ['text', 'html', 'lcov'],
      thresholds: { lines: 60, statements: 60, functions: 60, branches: 50 },
      exclude: ['node_modules/', 'dist/', '**/*.config.{ts,js}'],
    },
  },
});
