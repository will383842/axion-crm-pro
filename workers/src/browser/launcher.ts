import { chromium, type Browser, type BrowserContext } from 'playwright';
import pino from 'pino';

const log = pino({ name: 'browser-launcher' });

const STEALTH_INIT = `
  Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
  Object.defineProperty(navigator, 'plugins', { get: () => [1, 2, 3, 4, 5] });
  Object.defineProperty(navigator, 'languages', { get: () => ['fr-FR', 'fr', 'en-US'] });
  window.chrome = { runtime: {} };
`;

export interface LaunchOpts {
  proxyUrl?: string;
  userAgent?: string;
  locale?: string;
  timezone?: string;
  blockResources?: ('image' | 'media' | 'font' | 'stylesheet')[];
}

let _browser: Browser | null = null;

export async function getBrowser(): Promise<Browser> {
  if (_browser && _browser.isConnected()) return _browser;
  _browser = await chromium.launch({
    headless: true,
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-blink-features=AutomationControlled',
      '--disable-features=IsolateOrigins,site-per-process',
    ],
  });
  return _browser;
}

export async function createContext(opts: LaunchOpts = {}): Promise<BrowserContext> {
  const browser = await getBrowser();
  const ctx = await browser.newContext({
    proxy: opts.proxyUrl ? { server: opts.proxyUrl } : undefined,
    userAgent:
      opts.userAgent ??
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
    locale: opts.locale ?? 'fr-FR',
    timezoneId: opts.timezone ?? 'Europe/Paris',
    viewport: { width: 1366, height: 768 },
    ignoreHTTPSErrors: true,
  });

  await ctx.addInitScript(STEALTH_INIT);

  const blockTypes = new Set(opts.blockResources ?? ['image', 'media', 'font']);
  await ctx.route('**/*', (route) => {
    const t = route.request().resourceType();
    if (blockTypes.has(t as never)) return route.abort();
    return route.continue();
  });

  return ctx;
}

export async function closeBrowser(): Promise<void> {
  if (_browser && _browser.isConnected()) {
    await _browser.close();
    _browser = null;
    log.info('Browser closed');
  }
}

process.on('SIGTERM', () => void closeBrowser());
process.on('SIGINT', () => void closeBrowser());
