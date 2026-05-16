/**
 * Source 2 Direction Finder — communiqués de presse, détection nominations C-level.
 */
import type { Browser } from 'playwright'
import type Anthropic from '@anthropic-ai/sdk'
import { callClaude, parseLlmJson } from './llm-helper.js'
import { pickUserAgent } from './proxies.js'
import { normalizePosition, type CLevel } from './dedup.js'

const PRESS_PATHS = ['/presse', '/newsroom', '/communiques', '/communiques-de-presse', '/press-releases', '/actualites', '/news', '/media-room']
const MAX_ARTICLES = 10
const TIMEOUT_MS = 15000

interface Ctx {
  eti: { name: string; website: string }
  browser: Browser
  anthropic: Anthropic
  proxy?: string
}

export async function findPressNominations(ctx: Ctx) {
  const out = { cLevel: [] as CLevel[], pagesCrawled: 0, llmTokens: 0, llmCostEur: 0 }
  const browserCtx = await ctx.browser.newContext({
    userAgent: pickUserAgent(),
    locale: 'fr-FR',
    ...(ctx.proxy ? { proxy: { server: ctx.proxy } } : {}),
  })

  try {
    let articleLinks: string[] = []
    for (const path of PRESS_PATHS) {
      const url = new URL(path, ctx.eti.website).toString()
      const page = await browserCtx.newPage()
      try {
        const resp = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: TIMEOUT_MS })
        out.pagesCrawled++
        if (!resp || resp.status() >= 400) { await page.close(); continue }

        const links = await page.locator('a').evaluateAll((as) =>
          as.map(a => (a as HTMLAnchorElement).href).filter(h => /\/(communique|press|actualit|news|article)/i.test(h))
        )
        articleLinks = [...new Set(links)].slice(0, MAX_ARTICLES)
        await page.close()
        if (articleLinks.length > 0) break
      } catch {
        try { await page.close() } catch { /* ignore */ }
      }
    }

    if (articleLinks.length === 0) return out

    for (const link of articleLinks) {
      const page = await browserCtx.newPage()
      try {
        const resp = await page.goto(link, { waitUntil: 'domcontentloaded', timeout: TIMEOUT_MS })
        out.pagesCrawled++
        if (!resp || resp.status() >= 400) { await page.close(); continue }

        // Extrait le texte de l'article (cible <main>, <article>, ou body si rien d'autre)
        const text = await page.evaluate(() => {
          const article = document.querySelector('article, main, [role="main"]')
          return (article ?? document.body)?.textContent?.replace(/\s+/g, ' ').trim() ?? ''
        })

        if (text.length < 500) { await page.close(); continue }

        const llm = await callClaude(
          ctx.anthropic,
          'Tu détectes les nominations de cadres dirigeants dans des communiqués de presse. Tu réponds en JSON strict.',
          `URL : ${link}

Détecte si ce communiqué annonce une NOMINATION ou ARRIVÉE d'un C-level (DRH/CHRO, DAF/CFO, DSI/CIO, Directeur Marketing/CMO, Directeur Commercial/CCO, CEO).

Réponds UNIQUEMENT en JSON :
{
  "is_nomination": true|false,
  "nominations": [
    { "firstName": "...", "lastName": "...", "position": "...", "effective_date": "YYYY-MM-DD" }
  ]
}

Si ce n'est pas une nomination C-level : {"is_nomination": false, "nominations": []}`,
          text.slice(0, 6000),
          400,
        )
        out.llmTokens += llm.tokensIn + llm.tokensOut
        out.llmCostEur += llm.costEur

        const parsed = parseLlmJson<{ is_nomination: boolean; nominations: Array<{ firstName: string; lastName: string; position: string; effective_date?: string }> }>(llm.text)
        if (parsed?.is_nomination) {
          for (const n of parsed.nominations) {
            out.cLevel.push({
              firstName: n.firstName,
              lastName: n.lastName,
              fullName: `${n.firstName} ${n.lastName}`.trim(),
              position: n.position,
              positionNormalized: normalizePosition(n.position),
              discoverySource: 'press_releases',
              discoveryUrl: link,
              confidence: 85,
            })
          }
        }
        await page.close()
      } catch {
        try { await page.close() } catch { /* ignore */ }
      }
    }
  } finally {
    await browserCtx.close()
  }

  return out
}
