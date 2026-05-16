/**
 * Engines wrapper Google/Bing/DuckDuckGo avec détection anti-bot + 2captcha integration.
 */
import type { Browser, Page } from 'playwright'

export type Engine = 'google' | 'bing' | 'duckduckgo'

export interface SerpResult {
  title: string
  url: string
  snippet: string
  confidence: number
}

export interface SearchOutput {
  status: 'ok' | 'captcha_solved' | 'captcha_unsolved' | 'captcha_v3_blocked' | 'unusual_traffic' | 'no_results' | 'error'
  results: SerpResult[]
  best?: SerpResult
}

const TIMEOUT_MS = parseInt(process.env.TIMEOUT_PAGE_MS ?? '20000')

export async function searchEngine(
  browser: Browser,
  engine: Engine,
  targetType: 'company' | 'person',
  target: any,
  proxy: string,
  userAgent: string,
): Promise<SearchOutput> {
  const query = buildQuery(targetType, target)
  const url = buildSearchUrl(engine, query)

  const ctx = await browser.newContext({
    userAgent,
    locale: 'fr-FR',
    timezoneId: 'Europe/Paris',
    viewport: { width: 1920, height: 1080 },
    proxy: { server: proxy },
    extraHTTPHeaders: {
      'Accept-Language': 'fr-FR,fr;q=0.9,en;q=0.8',
      'Sec-Fetch-Site': 'none',
      'Sec-Fetch-Mode': 'navigate',
      'Upgrade-Insecure-Requests': '1',
    },
  })
  const page = await ctx.newPage()

  try {
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: TIMEOUT_MS })
    await dismissCookieBanner(page, engine).catch(() => { /* ignore */ })

    const antiBot = await detectAntiBot(page)
    if (antiBot === 'captcha_v3' || antiBot === 'unusual_traffic') {
      await ctx.close()
      return { status: antiBot === 'captcha_v3' ? 'captcha_v3_blocked' : 'unusual_traffic', results: [] }
    }

    if (antiBot === 'captcha_v2') {
      // Tentative résolution 2captcha via recaptcha plugin
      try {
        const { solved } = await (page as any).solveRecaptchas()
        if (solved && solved.length > 0) {
          // Re-essai parsing
          await page.waitForTimeout(2000)
          const results = await parseSerpResults(page, engine)
          await ctx.close()
          return { status: 'captcha_solved', results, best: bestMatch(results, targetType, target) }
        }
        await ctx.close()
        return { status: 'captcha_unsolved', results: [] }
      } catch {
        await ctx.close()
        return { status: 'captcha_unsolved', results: [] }
      }
    }

    if (antiBot === 'cf_challenge') {
      await ctx.close()
      return { status: 'error', results: [] }
    }

    const results = await parseSerpResults(page, engine)
    await ctx.close()
    if (results.length === 0) return { status: 'no_results', results: [] }
    return { status: 'ok', results, best: bestMatch(results, targetType, target) }
  } catch (e) {
    try { await ctx.close() } catch { /* ignore */ }
    return { status: 'error', results: [] }
  }
}

function buildQuery(type: 'company' | 'person', target: any): string {
  if (type === 'company') return `"${target.company}" site:linkedin.com/company/`
  return `"${target.firstName} ${target.lastName}" "${target.company}" site:linkedin.com/in/`
}

function buildSearchUrl(engine: Engine, query: string): string {
  const q = encodeURIComponent(query)
  switch (engine) {
    case 'google':     return `https://www.google.com/search?q=${q}&hl=fr&num=10`
    case 'bing':       return `https://www.bing.com/search?q=${q}&setlang=fr`
    case 'duckduckgo': return `https://duckduckgo.com/?q=${q}&kl=fr-fr`
  }
}

async function dismissCookieBanner(page: Page, engine: Engine): Promise<void> {
  const selectors = engine === 'google'
    ? ['button[aria-label*="Tout refuser"]', 'button[aria-label*="Reject all"]']
    : engine === 'bing'
      ? ['#bnp_btn_reject', '#bnp_btn_accept']
      : ['button[data-testid="reject-cookies"]']
  for (const sel of selectors) {
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
  if (await page.locator('iframe[src*="recaptcha"]').count() > 0 && await page.locator('.g-recaptcha').isVisible({ timeout: 500 }).catch(() => false)) return 'captcha_v2'
  if (await page.locator('text=Our systems have detected unusual traffic').isVisible({ timeout: 500 }).catch(() => false)) return 'unusual_traffic'
  if (await page.locator('text=Trafic inhabituel détecté').isVisible({ timeout: 500 }).catch(() => false)) return 'unusual_traffic'
  if (await page.locator('.cf-browser-verification, #cf-challenge').isVisible({ timeout: 500 }).catch(() => false)) return 'cf_challenge'
  return 'ok'
}

async function parseSerpResults(page: Page, engine: Engine): Promise<SerpResult[]> {
  const selector = engine === 'google' ? 'div.g' : engine === 'bing' ? 'li.b_algo' : 'article[data-testid="result"]'
  return await page.locator(selector).evaluateAll((els, eng) => {
    const e = eng as string
    return els.map(el => {
      const titleEl = el.querySelector(e === 'google' ? 'h3' : e === 'bing' ? 'h2' : 'h2 a')
      const linkEl = el.querySelector('a')
      const snipEl = el.querySelector(e === 'google' ? '.VwiC3b' : e === 'bing' ? '.b_caption p' : 'div[data-result="snippet"]')
      return {
        title: titleEl?.textContent?.trim() ?? '',
        url: linkEl?.getAttribute('href') ?? '',
        snippet: snipEl?.textContent?.trim() ?? '',
        confidence: 0,
      }
    }).filter(r => r.url.includes('linkedin.com'))
  }, engine)
}

function bestMatch(results: SerpResult[], targetType: 'company' | 'person', target: any): SerpResult | undefined {
  if (results.length === 0) return undefined
  const scored = results.map(r => ({ ...r, confidence: scoreDeterministic(r, targetType, target) }))
  scored.sort((a, b) => b.confidence - a.confidence)
  return scored[0]
}

function scoreDeterministic(r: SerpResult, type: 'company' | 'person', target: any): number {
  const url = r.url.toLowerCase()
  const snippet = r.snippet.toLowerCase()
  const company = (target.company || '').toLowerCase()
  let s = 0
  if (snippet.includes(company)) s += 40
  if (url.includes(company.replace(/\s+/g, '-'))) s += 20
  if (type === 'person') {
    const first = (target.firstName || '').toLowerCase()
    const last = (target.lastName || '').toLowerCase()
    if (first && url.includes(first)) s += 15
    if (last && url.includes(last)) s += 25
    if (last && snippet.includes(last)) s += 10
  }
  return Math.min(100, s)
}
