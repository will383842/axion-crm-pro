/**
 * POC #1 — Google Maps scraping anti-ban runner
 *
 * Mode test : 10 entreprises (~5 min)
 * Mode normal : N entreprises configurable via .env (default 1000)
 *
 * Pour POC complet 7 jours, lancer 7 fois avec --day 1, --day 2, etc.
 * Chaque exécution sauve dans results/day_N.json.
 */
import 'dotenv/config'
import { chromium } from 'playwright-extra'
import StealthPlugin from 'puppeteer-extra-plugin-stealth'
import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs'
import { join } from 'node:path'
import { scrapeGoogleMaps } from './scraper.js'
import { pickUserAgent, makeIPRoyalSession } from './session.js'

chromium.use(StealthPlugin())

const IS_TEST = process.argv.includes('--test')
const DAY_FLAG = process.argv.indexOf('--day')
const DAY = DAY_FLAG > 0 ? parseInt(process.argv[DAY_FLAG + 1] ?? '1') : 1

const ENTREPRISES_TARGET = IS_TEST ? 10 : parseInt(process.env.ENTREPRISES_PER_DAY ?? '1000')
const CONCURRENCY = parseInt(process.env.CONCURRENCY ?? '2')
const COOLDOWN_MS = parseInt(process.env.COOLDOWN_BETWEEN_SCRAPES_MS ?? '15000')

interface Company { legal_name: string; city: string }
interface ScrapeResult {
  legal_name: string
  city: string
  status: 'ok' | 'no_result' | 'captcha' | 'timeout' | 'error'
  duration_ms: number
  data?: { phone?: string | null; website?: string | null; rating?: string | null; reviews?: string | null; address?: string | null }
  error?: string
  proxy_session: string
  user_agent: string
  timestamp: string
}

async function main() {
  console.log(`\n=== POC #1 — Google Maps scraping ${IS_TEST ? '(TEST mode 10 entreprises)' : `(Day ${DAY}, ${ENTREPRISES_TARGET} entreprises)`} ===\n`)

  if (!process.env.IPROYAL_USERNAME || process.env.IPROYAL_USERNAME === 'REPLACE_ME') {
    throw new Error('IPRoyal credentials manquants dans .env')
  }

  const datasetPath = join(process.cwd(), 'datasets', 'companies_1000.json')
  const dataset: { companies: Company[] } = JSON.parse(readFileSync(datasetPath, 'utf8'))
  const companies = dataset.companies.slice(0, ENTREPRISES_TARGET)

  mkdirSync(join(process.cwd(), 'results'), { recursive: true })

  const browser = await chromium.launch({ headless: true })
  const results: ScrapeResult[] = []
  let idx = 0

  async function worker(workerId: number) {
    while (idx < companies.length) {
      const myIdx = idx++
      if (myIdx >= companies.length) break
      const c = companies[myIdx]!
      const sessionId = `poc1_w${workerId}_${Date.now()}`
      const proxy = makeIPRoyalSession(sessionId)
      const ua = pickUserAgent()

      const t0 = Date.now()
      try {
        const r = await scrapeGoogleMaps(browser, { ...c, proxy, userAgent: ua })
        results.push({
          ...c,
          status: r.status,
          duration_ms: Date.now() - t0,
          data: r.data,
          proxy_session: sessionId,
          user_agent: ua,
          timestamp: new Date().toISOString(),
        })
        const emoji = r.status === 'ok' ? '✅' : r.status === 'captcha' ? '⚠️' : r.status === 'no_result' ? '🟡' : '❌'
        console.log(`  [W${workerId}][${myIdx + 1}/${companies.length}] ${emoji} ${c.legal_name.padEnd(35)} ${c.city.padEnd(20)} ${r.status} ${(Date.now() - t0)}ms`)
      } catch (e: any) {
        results.push({ ...c, status: 'error', duration_ms: Date.now() - t0, error: e?.message, proxy_session: sessionId, user_agent: ua, timestamp: new Date().toISOString() })
        console.log(`  [W${workerId}][${myIdx + 1}/${companies.length}] ❌ ${c.legal_name.padEnd(35)} ${c.city.padEnd(20)} ERROR ${e?.message}`)
      }

      // Pacing humanisé (jitter ±20%)
      const sleep = COOLDOWN_MS * (0.8 + Math.random() * 0.4)
      await new Promise(r => setTimeout(r, sleep))
    }
  }

  await Promise.all(Array.from({ length: CONCURRENCY }, (_, i) => worker(i + 1)))
  await browser.close()

  // === Save day results ===
  const dayFile = IS_TEST ? 'results/test.json' : `results/day_${DAY}.json`
  writeFileSync(join(process.cwd(), dayFile), JSON.stringify({ day: DAY, results, completed_at: new Date().toISOString() }, null, 2))

  // === Summary day ===
  const ok = results.filter(r => r.status === 'ok').length
  const captcha = results.filter(r => r.status === 'captcha').length
  const noResult = results.filter(r => r.status === 'no_result').length
  const errors = results.filter(r => r.status === 'error' || r.status === 'timeout').length
  const successRate = (ok / results.length) * 100
  const latencies = results.filter(r => r.status === 'ok').map(r => r.duration_ms).sort((a, b) => a - b)
  const p95 = latencies[Math.ceil(latencies.length * 0.95) - 1] ?? 0

  console.log(`\n=== Day ${DAY} summary ===`)
  console.log(`  OK         : ${ok} (${successRate.toFixed(1)} %)`)
  console.log(`  No result  : ${noResult}`)
  console.log(`  Captchas   : ${captcha}`)
  console.log(`  Errors     : ${errors}`)
  console.log(`  Latence p95: ${p95} ms`)
  console.log(`\nDay results saved to ${dayFile}`)
  console.log(`Run 'pnpm run synthesize' after day 7 to produce RESULTS.md`)
}

main().catch(e => { console.error(e); process.exit(1) })
