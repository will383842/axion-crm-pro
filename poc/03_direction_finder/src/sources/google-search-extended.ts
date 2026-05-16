/**
 * Source 4 Direction Finder — Google Search étendu pour C-level.
 * Recherche : site:linkedin.com/in/ "DRH" "<entreprise>"
 *             pour 5 postes types × 2 variantes FR/EN.
 */
import type { Browser } from 'playwright'
import { pickUserAgent } from './proxies.js'
import type { CLevel } from './dedup.js'

const C_LEVEL_QUERIES = [
  { position: 'Directeur Ressources Humaines (DRH/CHRO)', positionNorm: 'drh', terms: ['"DRH"', '"CHRO"', '"Directrice des Ressources Humaines"', '"Directeur des Ressources Humaines"'] },
  { position: 'Directeur Administratif et Financier (DAF/CFO)', positionNorm: 'daf', terms: ['"DAF"', '"CFO"', '"Directeur Financier"', '"Directrice Financière"'] },
  { position: 'Directeur Systèmes d\'Information (DSI/CIO)', positionNorm: 'dsi', terms: ['"DSI"', '"CIO"', '"Directeur des Systèmes d\'Information"'] },
  { position: 'Directeur Marketing (CMO)', positionNorm: 'cmo', terms: ['"CMO"', '"Directeur Marketing"', '"Directrice Marketing"'] },
  { position: 'Directeur Commercial (CCO/CRO)', positionNorm: 'cco', terms: ['"CCO"', '"Directeur Commercial"', '"Directrice Commerciale"'] },
] as const

const TIMEOUT_MS = 15000

interface Ctx {
  eti: { name: string }
  browser: Browser
  proxy?: string
}

export async function findCLevelViaGoogleSearch(ctx: Ctx) {
  const out = { cLevel: [] as CLevel[] }
  const browserCtx = await ctx.browser.newContext({
    userAgent: pickUserAgent(),
    locale: 'fr-FR',
    ...(ctx.proxy ? { proxy: { server: ctx.proxy } } : {}),
  })

  try {
    for (const q of C_LEVEL_QUERIES) {
      const terms = q.terms.slice(0, 2).join(' OR ')
      const query = `site:linkedin.com/in/ (${terms}) "${ctx.eti.name}"`
      const url = `https://www.google.com/search?q=${encodeURIComponent(query)}&hl=fr&num=5`

      const page = await browserCtx.newPage()
      try {
        const resp = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: TIMEOUT_MS })
        if (!resp || resp.status() >= 400) { await page.close(); continue }

        const captcha = await page.locator('form[action*="captcha"], .g-recaptcha').isVisible({ timeout: 500 }).catch(() => false)
        if (captcha) { await page.close(); console.log('    ⚠️ Google captcha détecté sur source 4, skip'); break }

        // Parse results
        const results = await page.locator('div.g').evaluateAll(els =>
          els.map(el => {
            const link = el.querySelector('a')
            const title = el.querySelector('h3')
            const snippet = el.querySelector('.VwiC3b')
            return {
              url: link?.getAttribute('href') ?? '',
              title: title?.textContent ?? '',
              snippet: snippet?.textContent ?? '',
            }
          }).filter(r => r.url.includes('linkedin.com/in/'))
        )

        for (const r of results.slice(0, 3)) {
          // Extract first/last name from URL ou title
          const urlMatch = r.url.match(/linkedin\.com\/in\/([a-z0-9-]+)/i)
          const slug = urlMatch?.[1] ?? ''
          const nameParts = slug.split('-').filter(p => !/^[0-9a-f]{6,}$/.test(p))
          const fromTitle = r.title.split(' | ')[0]?.split(' - ')[0]?.trim() ?? ''
          const titleParts = fromTitle.split(/\s+/)

          out.cLevel.push({
            firstName: titleParts[0] ?? nameParts[0],
            lastName: titleParts.slice(1).join(' ') || nameParts.slice(1).join(' '),
            fullName: fromTitle || nameParts.join(' '),
            position: q.position,
            positionNormalized: q.positionNorm as any,
            discoverySource: 'google_search_extended',
            discoveryUrl: r.url,
            linkedinUrl: r.url,
            confidence: scoreMatch(r, ctx.eti.name),
          })
        }

        await page.close()
        // Pacing entre queries pour éviter ban Google
        await new Promise(r => setTimeout(r, 3000 + Math.random() * 2000))
      } catch {
        try { await page.close() } catch { /* ignore */ }
      }
    }
  } finally {
    await browserCtx.close()
  }

  return out
}

/** Scoring déterministe simple (sans LLM) : compagnie dans snippet/URL + nom dans URL */
function scoreMatch(r: { url: string; title: string; snippet: string }, companyName: string): number {
  let score = 0
  const co = companyName.toLowerCase()
  if (r.snippet.toLowerCase().includes(co)) score += 50
  if (r.title.toLowerCase().includes(co)) score += 30
  if (r.url.toLowerCase().includes(co.replace(/\s+/g, '-'))) score += 15
  return Math.min(100, score)
}
