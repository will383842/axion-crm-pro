import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react-swc';
import type { PluginOption } from 'vite';
import path from 'node:path';

export default defineConfig({
  plugins: [react() as PluginOption],
  resolve: {
    alias: { '@': path.resolve(__dirname, './src') },
  },
  test: {
    globals: true,
    environment: 'jsdom',
    setupFiles: ['./tests/setup.ts'],
    coverage: {
      provider: 'v8',
      reporter: ['text', 'html', 'lcov'],
      thresholds: { lines: 60, statements: 60, functions: 60, branches: 50 },
      exclude: ['node_modules/', 'dist/', '**/*.config.{ts,js}'],
    },
  },
});
