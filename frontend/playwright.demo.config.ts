/**
 * Playwright — e2e de la DÉMO (DM0-DEMO).
 *
 * Joue `tests/e2e/demo/` sur le build de démo (`pnpm build:demo` → `dist-demo`),
 * servi par `vite preview`. Aucune requête ne sort du navigateur : le faux
 * serveur MSW répond à tout. Chromium seulement (machines gratuites, plan 30).
 *
 * Distinct de `playwright.config.ts`, qui vise l'application réelle : les
 * suites de démo n'y sont jamais jouées, et inversement.
 */
import { defineConfig, devices } from '@playwright/test';

export const PORT_DEMO = 4174;
export const ORIGINE_DEMO = `http://127.0.0.1:${PORT_DEMO}`;

export default defineConfig({
  testDir: './tests/e2e/demo',
  timeout: 30_000,
  expect: { timeout: 7_000 },
  fullyParallel: true,
  forbidOnly: !!process.env['CI'],
  retries: process.env['CI'] ? 1 : 0,
  ...(process.env['CI'] ? { workers: 2 } : {}),
  reporter: [['list'], ['html', { outputFolder: 'playwright-report-demo', open: 'never' }]],
  use: {
    baseURL: ORIGINE_DEMO,
    locale: 'fr-FR',
    timezoneId: 'Europe/Paris',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: {
    command: `pnpm exec vite preview --mode demo --outDir dist-demo --host 127.0.0.1 --port ${PORT_DEMO} --strictPort`,
    url: ORIGINE_DEMO,
    timeout: 120_000,
    reuseExistingServer: !process.env['CI'],
  },
});
