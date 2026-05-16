/**
 * Source 1 Direction Finder — crawl pages corporate /direction, /equipe, /leadership, etc.
 * 25 paths FR + EN testés. Fallback LLM si parsing structuré échoue.
 */
import type { Browser } from 'playwright'
import type Anthropic from '@anthropic-ai/sdk'
import { callClaude, parseLlmJson } from './llm-helper.js'
import { pickUserAgent } from './proxies.js'
import { normalizePosition, type CLevel } from './dedup.js'

const DIRECTION_PATHS = [
  '/direction', '/equipe-de-direction', '/notre-equipe', '/notre-direction',
  '/leadership', '/management', '/governance', '/gouvernance',
  '/dirigeants', '/comite-executif', '/comex', '/conseil-administration',
  '/about/leadership', '/about/team', '/about-us/leadership', '/about-us/team',
  '/our-team', '/team', '/board', '/board-of-directors',
  '/executive-team', '/executives', '/staff', '/people', '/membres',
]

const TIMEOUT_MS = parseInt(process.env.TIMEOUT_PAGE_MS ?? '20000')

interface Ctx {
  eti: { name: string; website: string }
  browser: Browser
  anthropic: Anthropic
  proxy?: string
}

export async function findDirectionPages(ctx: Ctx) {
  const out = { cLevel: [] as CLevel[], pagesCrawled: 0, llmTokens: 0, llmCostEur: 0 }
  const browserCtx = await ctx.browser.newContext({
    userAgent: pickUserAgent(),
    locale: 'fr-FR',
    timezoneId: 'Europe/Paris',
    viewport: { width: 1920, height: 1080 },
    ...(ctx.proxy ? { proxy: { server: ctx.proxy } } : {}),
  })

  try {
    for (const path of DIRECTION_PATHS) {
      const url = new URL(path, ctx.eti.website).toString()
      const page = await browserCtx.newPage()
      try {
        const resp = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: TIMEOUT_MS })
        out.pagesCrawled++
        if (!resp || resp.status() >= 400) {
          await page.close()
          continue
        }

        // 1. Tentative parsing structuré (cards .team-member etc.)
        const structured = await page.evaluate(() => {
          const selectors = [
            '.team-member', '.member-card', '.executive-card', '.board-member',
            '[class*="team"] [class*="member"]', '[class*="leadership"] [class*="person"]',
            '.profil', '.profile-card', '.directeur', '.executive',
          ]
          const results: Array<{ name: string; title: string }> = []
          for (const sel of selectors) {
            const els = document.querySelectorAll(sel)
            for (const el of Array.from(els)) {
              const nameEl = el.querySelector('h2, h3, h4, .name, .member-name, .person-name, [class*="name"]')
              const titleEl = el.querySelector('.title, .position, .role, .member-position, .job-title, [class*="title"], [class*="position"]')
              const name = nameEl?.textContent?.trim() ?? ''
              const title = titleEl?.textContent?.trim() ?? ''
              if (name && title) results.push({ name, title })
            }
            if (results.length >= 3) break
          }
          return results
        })

        if (structured.length >= 2) {
          for (const m of structured) {
            const parts = m.name.split(/\s+/)
            const cLevel: CLevel = {
              firstName: parts[0],
              lastName: parts.slice(1).join(' '),
              fullName: m.name,
              position: m.title,
              positionNormalized: normalizePosition(m.title),
              discoverySource: 'corporate_pages',
              discoveryUrl: url,
              confidence: 90,
            }
            if (cLevel.positionNormalized && cLevel.positionNormalized !== 'other') {
              out.cLevel.push(cLevel)
            }
          }
          await page.close()
          if (out.cLevel.length >= 3) break
          continue
        }

        // 2. Fallback LLM si page non structurée
        const html = await page.content()
        const excerpt = stripScriptsAndStyles(html).slice(0, 12000)
        if (excerpt.length < 500) { await page.close(); continue }   // page trop courte = pas une vraie page équipe

        const llm = await callClaude(
          ctx.anthropic,
          `Tu extrais des informations structurées depuis du HTML. Tu retournes EXCLUSIVEMENT un JSON valide.`,
          `Voici un extrait HTML de la page "${url}". Extrais la liste des membres de direction/comité exécutif avec leur fonction.

Réponds UNIQUEMENT avec un JSON de cette forme (rien d'autre, pas de markdown) :
[
  { "firstName": "Marie", "lastName": "Dupont", "position": "Directrice des Ressources Humaines" },
  ...
]

Règles :
- N'invente aucun nom
- Garde uniquement les C-level (DRH, DAF, DSI, Directeur Marketing, Directeur Commercial, CEO, COO)
- Si aucun C-level trouvé, retourne []`,
          excerpt,
          600,
        )
        out.llmTokens += llm.tokensIn + llm.tokensOut
        out.llmCostEur += llm.costEur

        const parsed = parseLlmJson<Array<{ firstName: string; lastName: string; position: string }>>(llm.text) ?? []
        for (const m of parsed) {
          const cLevel: CLevel = {
            firstName: m.firstName,
            lastName: m.lastName,
            fullName: `${m.firstName} ${m.lastName}`.trim(),
            position: m.position,
            positionNormalized: normalizePosition(m.position),
            discoverySource: 'corporate_pages',
            discoveryUrl: url,
            confidence: 70,
          }
          if (cLevel.positionNormalized !== 'other') out.cLevel.push(cLevel)
        }

        await page.close()
        if (out.cLevel.length >= 3) break
      } catch (e) {
        try { await page.close() } catch { /* ignore */ }
        // continue paths
      }
    }
  } finally {
    await browserCtx.close()
  }

  return out
}

function stripScriptsAndStyles(html: string): string {
  return html
    .replace(/<script[\s\S]*?<\/script>/gi, ' ')
    .replace(/<style[\s\S]*?<\/style>/gi, ' ')
    .replace(/<noscript[\s\S]*?<\/noscript>/gi, ' ')
    .replace(/\s+/g, ' ')
    .trim()
}
