import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  timeout: 30_000,
  expect: { timeout: 5_000 },
  fullyParallel: true,
  forbidOnly: !!process.env['CI'],
  retries: process.env['CI'] ? 2 : 0,
  workers: process.env['CI'] ? 2 : undefined,
  reporter: [
    ['list'],
    ['html', { outputFolder: 'playwright-report', open: 'never' }],
    ['json', { outputFile: 'playwright-report/results.json' }],
  ],
  use: {
    baseURL: process.env['E2E_BASE_URL'] ?? 'https://app.localhost',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    ignoreHTTPSErrors: true,
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'firefox',  use: { ...devices['Desktop Firefox'] } },
    { name: 'mobile-safari', use: { ...devices['iPhone 14'] } },
  ],
  // Trois cas, dans cet ordre :
  //  1. E2E_PREVIEW=true (CI a11y) → on sert le BUILD via `vite preview`.
  //     Sans lui, `CI=true` ne démarrait aucun serveur et la suite visait une
  //     cible inexistante : chaque page échouait sur ERR_NAME_NOT_RESOLVED,
  //     donc aucune règle axe n'était jamais évaluée.
  //  2. CI sans E2E_PREVIEW → cible externe fournie par l'appelant, on ne
  //     démarre rien (comportement historique, préservé).
  //  3. local → `pnpm dev` comme avant.
  webServer: process.env['E2E_PREVIEW'] === 'true'
    ? {
        command: 'pnpm exec vite preview --host 127.0.0.1 --port 4173 --strictPort',
        url: 'http://127.0.0.1:4173',
        timeout: 120_000,
        reuseExistingServer: false,
      }
    : process.env['CI'] ? undefined : {
      command: 'pnpm dev',
      url: 'https://app.localhost',
      timeout: 60_000,
      reuseExistingServer: true,
      ignoreHTTPSErrors: true,
    },
});
