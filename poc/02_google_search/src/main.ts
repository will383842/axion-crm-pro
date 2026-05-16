/**
 * POC #2 — Google Search Wrapper runner
 *
 * Rotation 3 moteurs (Google, Bing, DuckDuckGo) + IPRoyal sticky + 2captcha integration.
 */
import 'dotenv/config'
import { chromium } from 'playwright-extra'
import StealthPlugin from 'puppeteer-extra-plugin-stealth'
import RecaptchaPlugin from 'puppeteer-extra-plugin-recaptcha'
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs'
import { join } from 'node:path'
import { searchEngine, type Engine, type SearchResult } from './engines.js'
import { makeIPRoyalSession, pickUserAgent } from './session.js'

chromium.use(StealthPlugin())
chromium.use(RecaptchaPlugin({
  provider: { id: '2captcha', token: process.env.CAPTCHA_2CAPTCHA_KEY ?? '' },
  visualFeedback: false,
}))

const IS_TEST = process.argv.includes('--test')
const DAY_FLAG = process.argv.indexOf('--day')
const DAY = DAY_FLAG > 0 ? parseInt(process.argv[DAY_FLAG + 1] ?? '1') : 1

const QUERIES_TARGET = IS_TEST ? 20 : parseInt(process.env.QUERIES_PER_DAY ?? '500')
const CONCURRENCY = parseInt(process.env.CONCURRENCY ?? '2')
const COOLDOWN_MS = parseInt(process.env.COOLDOWN_MS ?? '8000')

interface QueryResult {
  target_type: 'company' | 'person'
  target: any
  engine_used: Engine
  status: 'ok' | 'captcha_solved' | 'captcha_unsolved' | 'captcha_v3_blocked' | 'unusual_traffic' | 'no_results' | 'error'
  results_count: number
  best_url?: string
  best_confidence?: number
  duration_ms: number
  timestamp: string
  proxy_session: string
}

async function main() {
  if (!process.env.IPROYAL_USERNAME || process.env.IPROYAL_USERNAME === 'REPLACE_ME') {
    throw new Error('IPRoyal credentials manquants dans .env')
  }
  if (!process.env.CAPTCHA_2CAPTCHA_KEY || process.env.CAPTCHA_2CAPTCHA_KEY === 'REPLACE_ME') {
    throw new Error('CAPTCHA_2CAPTCHA_KEY manquant dans .env')
  }

  console.log(`\n=== POC #2 — Google Search Wrapper ${IS_TEST ? '(TEST mode 20 queries)' : `(Day ${DAY}, ${QUERIES_TARGET} queries)`} ===\n`)

  const datasetPath = join(process.cwd(), 'datasets', 'targets_1000.json')
  const dataset = JSON.parse(readFileSync(datasetPath, 'utf8'))

  // Mix queries : 50% company, 50% person
  const companies = dataset.company_targets.slice(0, Math.floor(QUERIES_TARGET / 2))
  const persons = dataset.person_targets.slice(0, Math.floor(QUERIES_TARGET / 2))
  const queries: Array<{ target_type: 'company' | 'person'; target: any }> = [
    ...companies.map((c: any) => ({ target_type: 'company' as const, target: c })),
    ...persons.map((p: any) => ({ target_type: 'person' as const, target: p })),
  ]

  // Shuffle pour ne pas grouper toutes les company puis toutes les person
  queries.sort(() => Math.random() - 0.5)

  mkdirSync(join(process.cwd(), 'results'), { recursive: true })

  const browser = await chromium.launch({ headless: true })
  const results: QueryResult[] = []
  let idx = 0

  // État moteurs (rotation 3 moteurs)
  const enginesState: Record<Engine, { active: boolean; cooldownUntil: number }> = {
    google:     { active: true, cooldownUntil: 0 },
    bing:       { active: true, cooldownUntil: 0 },
    duckduckgo: { active: true, cooldownUntil: 0 },
  }

  function pickEngine(): Engine | null {
    const now = Date.now()
    const avail = (Object.keys(enginesState) as Engine[]).filter(e => enginesState[e].active && enginesState[e].cooldownUntil < now)
    if (avail.length === 0) return null
    return avail[Math.floor(Math.random() * avail.length)]!
  }

  async function worker(workerId: number) {
    while (idx < queries.length) {
      const myIdx = idx++
      if (myIdx >= queries.length) break
      const q = queries[myIdx]!
      const engine = pickEngine()
      if (!engine) {
        // tous moteurs en cooldown — attendre 60s
        console.log(`  [W${workerId}] All engines on cooldown, waiting 60s...`)
        await new Promise(r => setTimeout(r, 60000))
        idx--   // re-tente
        continue
      }
      const sessionId = `poc2_w${workerId}_${Date.now()}`
      const proxy = makeIPRoyalSession(sessionId)
      const ua = pickUserAgent()

      const t0 = Date.now()
      try {
        const r = await searchEngine(browser, engine, q.target_type, q.target, proxy, ua)
        results.push({
          ...q,
          engine_used: engine,
          status: r.status,
          results_count: r.results.length,
          best_url: r.best?.url,
          best_confidence: r.best?.confidence,
          duration_ms: Date.now() - t0,
          timestamp: new Date().toISOString(),
          proxy_session: sessionId,
        })

        // Si captcha v3 ou unusual_traffic → cooldown 60 min sur ce moteur
        if (r.status === 'captcha_v3_blocked' || r.status === 'unusual_traffic') {
          enginesState[engine].cooldownUntil = Date.now() + 60 * 60 * 1000
          console.log(`  [W${workerId}][${myIdx + 1}/${queries.length}] 🚫 ${engine} ${r.status} → cooldown 60min`)
        } else {
          const emoji = r.status === 'ok' ? '✅' : r.status === 'captcha_solved' ? '🔓' : r.status === 'no_results' ? '🟡' : '❌'
          console.log(`  [W${workerId}][${myIdx + 1}/${queries.length}] ${emoji} [${engine.padEnd(10)}] ${q.target_type.padEnd(7)} ${(q.target.company || `${q.target.firstName} ${q.target.lastName}`).padEnd(35)} ${r.status}`)
        }
      } catch (e: any) {
        results.push({
          ...q,
          engine_used: engine,
          status: 'error',
          results_count: 0,
          duration_ms: Date.now() - t0,
          timestamp: new Date().toISOString(),
          proxy_session: sessionId,
        })
        console.log(`  [W${workerId}][${myIdx + 1}/${queries.length}] ❌ ${engine} ERROR ${e?.message}`)
      }

      await new Promise(r => setTimeout(r, COOLDOWN_MS * (0.8 + Math.random() * 0.4)))
    }
  }

  await Promise.all(Array.from({ length: CONCURRENCY }, (_, i) => worker(i + 1)))
  await browser.close()

  const dayFile = IS_TEST ? 'results/test.json' : `results/day_${DAY}.json`
  writeFileSync(join(process.cwd(), dayFile), JSON.stringify({ day: DAY, results, completed_at: new Date().toISOString() }, null, 2))

  // Summary
  const ok = results.filter(r => r.status === 'ok').length
  const captchaSolved = results.filter(r => r.status === 'captcha_solved').length
  const captchaUnsolved = results.filter(r => r.status === 'captcha_unsolved' || r.status === 'captcha_v3_blocked' || r.status === 'unusual_traffic').length
  const okWithCaptcha = ok + captchaSolved
  const successRate = (okWithCaptcha / results.length) * 100
  const captchaRate = ((captchaSolved + captchaUnsolved) / results.length) * 100

  console.log(`\n=== Day ${DAY} summary ===`)
  console.log(`  OK direct        : ${ok}`)
  console.log(`  OK via 2captcha  : ${captchaSolved}`)
  console.log(`  Captcha blocked  : ${captchaUnsolved}`)
  console.log(`  Success rate     : ${successRate.toFixed(1)} %`)
  console.log(`  Captcha rate     : ${captchaRate.toFixed(1)} %`)
  console.log(`\nSaved to ${dayFile}`)
}

main().catch(e => { console.error(e); process.exit(1) })
