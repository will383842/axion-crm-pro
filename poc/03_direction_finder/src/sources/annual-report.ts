/**
 * Source 3 Direction Finder — rapport annuel PDF (AMF + Google filetype:pdf).
 */
import type { Browser } from 'playwright'
import type Anthropic from '@anthropic-ai/sdk'
import pdfParse from 'pdf-parse'
import { fetch } from 'undici'
import { callClaude, parseLlmJson } from './llm-helper.js'
import { pickUserAgent } from './proxies.js'
import { normalizePosition, type CLevel } from './dedup.js'

const MAX_PDF_BYTES = 10 * 1024 * 1024     // 10 MB cap conforme spec v1.2
const TIMEOUT_MS = 20000

interface Ctx {
  eti: { name: string; website: string; is_listed: boolean }
  browser: Browser
  anthropic: Anthropic
  proxy?: string
}

export async function findAnnualReportLeadership(ctx: Ctx) {
  const out = { cLevel: [] as CLevel[], pagesCrawled: 0, llmTokens: 0, llmCostEur: 0 }

  if (!ctx.eti.is_listed) return out      // skip non-cotées (pas de rapport annuel obligatoire)

  // Recherche PDF via Google `"<entreprise>" rapport annuel filetype:pdf`
  const pdfUrl = await findPdfUrlViaGoogle(ctx)
  if (!pdfUrl) return out
  out.pagesCrawled++

  // Download PDF avec cap 10 MB
  const pdfBuffer = await downloadPdfCapped(pdfUrl)
  if (!pdfBuffer) return out

  // Parse PDF
  let pdfData: { text: string; numpages: number }
  try {
    pdfData = await pdfParse(pdfBuffer)
  } catch {
    return out
  }

  // Cherche les pages "gouvernance" / "direction" / "comité exécutif"
  const fullText = pdfData.text
  const leadershipSection = extractLeadershipSection(fullText)
  if (!leadershipSection) return out

  // LLM extract
  const llm = await callClaude(
    ctx.anthropic,
    `Tu extrais la composition du comité exécutif / direction depuis un extrait de rapport annuel. Tu réponds en JSON strict.`,
    `Extrait d'un rapport annuel de l'entreprise "${ctx.eti.name}". Liste tous les membres du comité exécutif / direction générale avec leur fonction.

Réponds UNIQUEMENT en JSON :
[
  { "firstName": "Marie", "lastName": "Dupont", "position": "Directrice des Ressources Humaines" },
  ...
]

Règles :
- Garde uniquement les C-level (DRH, DAF, DSI, CMO, CCO, CEO, COO, Comex membres)
- N'invente aucun nom
- Si rien trouvé : []`,
    leadershipSection,
    800,
  )
  out.llmTokens += llm.tokensIn + llm.tokensOut
  out.llmCostEur += llm.costEur

  const parsed = parseLlmJson<Array<{ firstName: string; lastName: string; position: string }>>(llm.text) ?? []
  for (const m of parsed) {
    out.cLevel.push({
      firstName: m.firstName,
      lastName: m.lastName,
      fullName: `${m.firstName} ${m.lastName}`.trim(),
      position: m.position,
      positionNormalized: normalizePosition(m.position),
      discoverySource: 'annual_report',
      discoveryUrl: pdfUrl,
      confidence: 90,
    })
  }

  return out
}

async function findPdfUrlViaGoogle(ctx: Ctx): Promise<string | null> {
  const query = encodeURIComponent(`"${ctx.eti.name}" rapport annuel filetype:pdf`)
  const url = `https://www.google.com/search?q=${query}&hl=fr&num=5`
  const browserCtx = await ctx.browser.newContext({
    userAgent: pickUserAgent(),
    locale: 'fr-FR',
    ...(ctx.proxy ? { proxy: { server: ctx.proxy } } : {}),
  })
  const page = await browserCtx.newPage()
  try {
    const resp = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: TIMEOUT_MS })
    if (!resp || resp.status() >= 400) return null
    // Captcha check
    const captcha = await page.locator('form[action*="captcha"], .g-recaptcha').isVisible({ timeout: 500 }).catch(() => false)
    if (captcha) return null

    const links = await page.locator('a').evaluateAll(as =>
      as.map(a => (a as HTMLAnchorElement).href).filter(h => h.endsWith('.pdf') && !h.includes('google.com'))
    )
    return links[0] ?? null
  } catch {
    return null
  } finally {
    await browserCtx.close()
  }
}

async function downloadPdfCapped(url: string): Promise<Buffer | null> {
  try {
    // HEAD pour vérif content-length
    const head = await fetch(url, { method: 'HEAD' }).catch(() => null)
    if (head?.headers.get('content-length')) {
      const len = parseInt(head.headers.get('content-length')!)
      if (len > MAX_PDF_BYTES) {
        console.log(`    PDF trop gros (${(len / 1024 / 1024).toFixed(1)} MB), skip`)
        return null
      }
    }
    const resp = await fetch(url)
    if (!resp.ok) return null
    const ab = await resp.arrayBuffer()
    if (ab.byteLength > MAX_PDF_BYTES) return null
    return Buffer.from(ab)
  } catch {
    return null
  }
}

function extractLeadershipSection(text: string): string | null {
  const lc = text.toLowerCase()
  const markers = [
    'comité exécutif', 'comite executif', 'comex',
    'direction générale', 'direction generale',
    'gouvernance d\'entreprise', 'organes de direction',
    'leadership', 'executive committee', 'board of directors',
  ]
  for (const m of markers) {
    const idx = lc.indexOf(m)
    if (idx >= 0) {
      // Extrait ~5000 chars autour de ce marker
      const start = Math.max(0, idx - 500)
      const end = Math.min(text.length, idx + 5000)
      return text.slice(start, end)
    }
  }
  return null
}
