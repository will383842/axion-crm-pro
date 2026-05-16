/**
 * Scraper Google Maps avec stealth + cookie warehouse + mouse mouvements simulés.
 * Conforme spec v1.2 `05_scrapers_14_sources.md` § 6.
 */
import type { Browser, Page } from 'playwright'
import { mkdirSync, existsSync, readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'

const TIMEOUT_MS = parseInt(process.env.TIMEOUT_PAGE_MS ?? '25000')
const COOKIE_WAREHOUSE_DIR = join(process.cwd(), '.cookie-warehouse')
mkdirSync(COOKIE_WAREHOUSE_DIR, { recursive: true })

interface ScrapeArgs { legal_name: string; city: string; proxy: string; userAgent: string }
interface ScrapeOutput {
  status: 'ok' | 'no_result' | 'captcha' | 'timeout' | 'error'
  data?: { phone?: string | null; website?: string | null; rating?: string | null; reviews?: string | null; address?: string | null }
  error?: string
}

export async function scrapeGoogleMaps(browser: Browser, args: ScrapeArgs): Promise<ScrapeOutput> {
  const sessionFile = join(COOKIE_WAREHOUSE_DIR, `${hashStr(args.proxy)}.json`)
  const storageState = existsSync(sessionFile) ? JSON.parse(readFileSync(sessionFile, 'utf8')) : undefined

  const ctx = await browser.newContext({
    userAgent: args.userAgent,
    locale: 'fr-FR',
    timezoneId: 'Europe/Paris',
    viewport: { width: 1920, height: 1080 },
    proxy: { server: args.proxy },
    storageState,
    extraHTTPHeaders: {
      'Accept-Language': 'fr-FR,fr;q=0.9,en;q=0.8',
      'Sec-Ch-Ua-Platform': args.userAgent.includes('Mac') ? '"macOS"' : args.userAgent.includes('Linux') ? '"Linux"' : '"Windows"',
      'Sec-Fetch-Site': 'none',
      'Sec-Fetch-Mode': 'navigate',
      'Sec-Fetch-User': '?1',
      'Sec-Fetch-Dest': 'document',
      'Upgrade-Insecure-Requests': '1',
    },
  })

  const page = await ctx.newPage()
  try {
    const q = encodeURIComponent(`${args.legal_name} ${args.city}`)
    const url = `https://www.google.com/maps/search/${q}`
    await page.goto(url, { waitUntil: 'networkidle', timeout: TIMEOUT_MS })

    // Cookie consent EU
    await dismissCookieBanner(page).catch(() => { /* ignore */ })

    // Captcha / anti-bot detection
    const antiBot = await detectAntiBot(page)
    if (antiBot !== 'ok') {
      await ctx.close()
      return { status: 'captcha', error: antiBot }
    }

    // Movement humanisé puis click sur 1er résultat
    await humanMove(page, 800 + Math.random() * 400, 500 + Math.random() * 300)
    await page.waitForTimeout(500 + Math.random() * 800)

    // Tente de cliquer le 1er card de résultat (liste latérale)
    const firstCard = page.locator('a.hfpxzc').first()
    const hasResult = await firstCard.isVisible({ timeout: 5000 }).catch(() => false)
    if (!hasResult) {
      await ctx.close()
      return { status: 'no_result' }
    }
    await firstCard.click({ timeout: 5000 }).catch(() => { /* parfois pas cliquable, ok */ })
    await page.waitForTimeout(2000 + Math.random() * 1500)

    // Extraction
    const data = await extractData(page)

    // Save cookies pour cette session (cookie warehouse)
    const state = await ctx.storageState()
    writeFileSync(sessionFile, JSON.stringify(state))

    await ctx.close()
    return { status: 'ok', data }
  } catch (e: any) {
    try { await ctx.close() } catch { /* ignore */ }
    if (e?.name === 'TimeoutError') return { status: 'timeout', error: 'page timeout' }
    return { status: 'error', error: e?.message ?? 'unknown' }
  }
}

async function dismissCookieBanner(page: Page): Promise<void> {
  // Google EU cookie consent — bouton "Tout refuser" ou "Tout accepter"
  const buttons = [
    'button[aria-label*="Tout refuser"]', 'button[aria-label*="Reject all"]',
    'button[aria-label*="Tout accepter"]', 'button[aria-label*="Accept all"]',
    '[role="button"]:has-text("Tout refuser")', '[role="button"]:has-text("Reject all")',
  ]
  for (const sel of buttons) {
    const btn = page.locator(sel).first()
    if (await btn.isVisible({ timeout: 1500 }).catch(() => false)) {
      await btn.click({ timeout: 2000 }).catch(() => { /* ignore */ })
      await page.waitForTimeout(500)
      return
    }
  }
}

async function detectAntiBot(page: Page): Promise<'ok' | 'captcha_v2' | 'captcha_v3' | 'unusual_traffic' | 'cf_challenge'> {
  if (await page.locator('form[action*="captcha"]').isVisible({ timeout: 500 }).catch(() => false)) return 'captcha_v2'
  if (await page.locator('.g-recaptcha[data-size="invisible"]').count() > 0) return 'captcha_v3'
  if (await page.locator('text=Our systems have detected unusual traffic').isVisible({ timeout: 500 }).catch(() => false)) return 'unusual_traffic'
  if (await page.locator('text=Trafic inhabituel détecté').isVisible({ timeout: 500 }).catch(() => false)) return 'unusual_traffic'
  if (await page.locator('.cf-browser-verification, #cf-challenge').isVisible({ timeout: 500 }).catch(() => false)) return 'cf_challenge'
  return 'ok'
}

async function humanMove(page: Page, x: number, y: number): Promise<void> {
  const steps = 15 + Math.floor(Math.random() * 15)
  await page.mouse.move(x, y, { steps })
}

async function extractData(page: Page) {
  const phone = await page.locator('button[data-item-id^="phone:"]').first().getAttribute('aria-label').catch(() => null)
  const website = await page.locator('a[data-item-id="authority"]').first().getAttribute('href').catch(() => null)
  const address = await page.locator('button[data-item-id="address"]').first().getAttribute('aria-label').catch(() => null)
  const rating = await page.locator('div.F7nice span').first().textContent().catch(() => null)
  const reviewsTxt = await page.locator('div.F7nice span').nth(1).textContent().catch(() => null)
  return {
    phone: phone?.replace(/^Téléphone:\s*/, '').replace(/^Phone:\s*/, '').trim() ?? null,
    website,
    address: address?.replace(/^Adresse:\s*/, '').trim() ?? null,
    rating: rating?.trim() ?? null,
    reviews: reviewsTxt?.replace(/[()]/g, '').trim() ?? null,
  }
}

function hashStr(s: string): string {
  let h = 0
  for (let i = 0; i < s.length; i++) {
    h = ((h << 5) - h) + s.charCodeAt(i)
    h |= 0
  }
  return Math.abs(h).toString(16)
}
