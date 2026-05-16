/**
 * POC #3 — Direction Finder runner
 *
 * Pour chaque ETI du dataset, exécute les 4 sources de Direction Finder
 * conformément à la spec v1.2 `05_scrapers_14_sources.md` § Direction Finder.
 * Produit RESULTS.md avec verdict GO/NO-GO.
 */
import 'dotenv/config'
import { chromium, type Browser } from 'playwright'
import Anthropic from '@anthropic-ai/sdk'
import { readFileSync, writeFileSync, existsSync } from 'node:fs'
import { join } from 'node:path'
import { findDirectionPages } from './sources/corporate-pages.js'
import { findPressNominations } from './sources/press-releases.js'
import { findAnnualReportLeadership } from './sources/annual-report.js'
import { findCLevelViaGoogleSearch } from './sources/google-search-extended.js'
import { dedupCLevel, type CLevel } from './sources/dedup.js'
import { loadProxies, pickProxy } from './sources/proxies.js'

interface Eti {
  name: string
  siren: string
  website: string
  is_listed: boolean
  sector: string
}

interface EtiResult {
  eti: Eti
  duration_ms: number
  sources_attempted: string[]
  sources_successful: string[]
  c_level_found: CLevel[]
  llm_tokens_used: number
  llm_cost_eur: number
  pages_crawled: number
  error?: string
}

const MAX_ETIS = parseInt(process.env.MAX_ETIS ?? '20')

async function processEti(eti: Eti, browser: Browser, anthropic: Anthropic, proxies: string[]): Promise<EtiResult> {
  const t0 = Date.now()
  const proxy = pickProxy(proxies)
  const sourcesAttempted: string[] = []
  const sourcesSuccessful: string[] = []
  let cLevel: CLevel[] = []
  let llmTokens = 0
  let llmCostEur = 0
  let pagesCrawled = 0

  console.log(`\n[${eti.name}] ${eti.website}`)

  try {
    // === Source 1 : corporate pages ===
    sourcesAttempted.push('corporate_pages')
    console.log('  ↪ Source 1 : corporate pages')
    const corp = await findDirectionPages({ eti, browser, anthropic, proxy })
    pagesCrawled += corp.pagesCrawled
    llmTokens += corp.llmTokens
    llmCostEur += corp.llmCostEur
    if (corp.cLevel.length > 0) {
      sourcesSuccessful.push('corporate_pages')
      cLevel.push(...corp.cLevel)
      console.log(`    → ${corp.cLevel.length} C-level via pages corporate`)
    }

    // === Source 2 : press releases (si peu de résultats source 1) ===
    if (cLevel.length < 3) {
      sourcesAttempted.push('press_releases')
      console.log('  ↪ Source 2 : press releases')
      const press = await findPressNominations({ eti, browser, anthropic, proxy })
      pagesCrawled += press.pagesCrawled
      llmTokens += press.llmTokens
      llmCostEur += press.llmCostEur
      if (press.cLevel.length > 0) {
        sourcesSuccessful.push('press_releases')
        cLevel.push(...press.cLevel)
        console.log(`    → ${press.cLevel.length} C-level via presse`)
      }
    }

    // === Source 3 : annual report PDF (si cotée) ===
    if (eti.is_listed && cLevel.length < 5) {
      sourcesAttempted.push('annual_report')
      console.log('  ↪ Source 3 : annual report PDF')
      const ar = await findAnnualReportLeadership({ eti, browser, anthropic, proxy })
      pagesCrawled += ar.pagesCrawled
      llmTokens += ar.llmTokens
      llmCostEur += ar.llmCostEur
      if (ar.cLevel.length > 0) {
        sourcesSuccessful.push('annual_report')
        cLevel.push(...ar.cLevel)
        console.log(`    → ${ar.cLevel.length} C-level via rapport annuel`)
      }
    }

    // === Source 4 : Google Search étendu C-level ===
    sourcesAttempted.push('google_search_extended')
    console.log('  ↪ Source 4 : Google Search étendu')
    const gs = await findCLevelViaGoogleSearch({ eti, browser, proxy })
    if (gs.cLevel.length > 0) {
      sourcesSuccessful.push('google_search_extended')
      cLevel.push(...gs.cLevel)
      console.log(`    → ${gs.cLevel.length} URLs LinkedIn via Google`)
    }

    // === Dédup C-level ===
    cLevel = dedupCLevel(cLevel)
    console.log(`  → TOTAL ${cLevel.length} C-level uniques`)

    return {
      eti,
      duration_ms: Date.now() - t0,
      sources_attempted: sourcesAttempted,
      sources_successful: sourcesSuccessful,
      c_level_found: cLevel,
      llm_tokens_used: llmTokens,
      llm_cost_eur: llmCostEur,
      pages_crawled: pagesCrawled,
    }
  } catch (e: any) {
    return {
      eti,
      duration_ms: Date.now() - t0,
      sources_attempted: sourcesAttempted,
      sources_successful: sourcesSuccessful,
      c_level_found: cLevel,
      llm_tokens_used: llmTokens,
      llm_cost_eur: llmCostEur,
      pages_crawled: pagesCrawled,
      error: e?.message ?? 'unknown',
    }
  }
}

async function main() {
  const datasetPath = join(process.cwd(), 'datasets', 'etis_test.json')
  const dataset = JSON.parse(readFileSync(datasetPath, 'utf8'))
  const etis = (dataset.etis as Eti[]).slice(0, MAX_ETIS)

  if (!process.env.ANTHROPIC_API_KEY || process.env.ANTHROPIC_API_KEY === 'sk-ant-REPLACE_ME') {
    throw new Error('Variable ANTHROPIC_API_KEY non configurée dans .env')
  }

  const proxiesFile = process.env.WEBSHARE_PROXIES_FILE ?? './proxies.txt'
  if (!existsSync(proxiesFile)) {
    console.warn(`⚠️  Fichier proxies ${proxiesFile} absent. POC tourne SANS proxies (risque de ban rapide sur sources corporate ETI).`)
  }
  const proxies = existsSync(proxiesFile) ? loadProxies(proxiesFile) : []

  const browser = await chromium.launch({ headless: true })
  const anthropic = new Anthropic({ apiKey: process.env.ANTHROPIC_API_KEY })

  console.log(`\n=== POC #3 — Direction Finder ===`)
  console.log(`ETIs à tester : ${etis.length}`)
  console.log(`Proxies dispo : ${proxies.length}\n`)

  const results: EtiResult[] = []
  for (const eti of etis) {
    const r = await processEti(eti, browser, anthropic, proxies)
    results.push(r)
  }
  await browser.close()

  // === Synthèse ===
  const etisWithCLevel = results.filter(r => r.c_level_found.length >= 1)
  const successRate = (etisWithCLevel.length / results.length) * 100
  const totalLlmCost = results.reduce((s, r) => s + r.llm_cost_eur, 0)
  const totalLlmTokens = results.reduce((s, r) => s + r.llm_tokens_used, 0)
  const avgDuration = results.reduce((s, r) => s + r.duration_ms, 0) / results.length / 1000

  const verdict = successRate >= 25 ? '🟢 GO' : successRate >= 15 ? '🟡 GO conditionnel' : '🔴 NO-GO'

  // === Write RESULTS.md ===
  const now = new Date().toISOString().slice(0, 19)
  let md = `# POC #3 — Direction Finder — RÉSULTATS\n\n`
  md += `> **Date :** ${now}\n`
  md += `> **ETIs testées :** ${results.length}\n\n`
  md += `## KPIs globaux\n\n`
  md += `| Métrique | Mesure | Cible | Statut |\n|---|---|---|---|\n`
  md += `| **% ETIs avec ≥ 1 C-level** | **${successRate.toFixed(1)} %** | ≥ 25 % | ${successRate >= 25 ? '🟢' : successRate >= 15 ? '🟡' : '🔴'} |\n`
  md += `| Coût LLM total | ${totalLlmCost.toFixed(2)} € (${totalLlmTokens} tokens) | < 10 € | ${totalLlmCost < 10 ? '🟢' : '🟡'} |\n`
  md += `| Durée moyenne / ETI | ${avgDuration.toFixed(1)} s | < 90 s | ${avgDuration < 90 ? '🟢' : '🟡'} |\n`
  md += `\n## Verdict global : ${verdict}\n\n`

  md += `## Détail par ETI\n\n`
  md += `| ETI | Secteur | C-level | Sources OK | Durée (s) | Coût LLM (€) |\n|---|---|---|---|---|---|\n`
  for (const r of results) {
    md += `| ${r.eti.name} | ${r.eti.sector} | ${r.c_level_found.length} | ${r.sources_successful.join(', ') || '—'} | ${(r.duration_ms / 1000).toFixed(1)} | ${r.llm_cost_eur.toFixed(3)} |\n`
  }

  md += `\n## C-level trouvés (top 30)\n\n`
  const allCLevel = results.flatMap(r => r.c_level_found.map(c => ({ ...c, eti: r.eti.name })))
  md += `| Entreprise | Prénom Nom | Position | Source découverte | LinkedIn URL |\n|---|---|---|---|---|\n`
  for (const c of allCLevel.slice(0, 30)) {
    md += `| ${c.eti} | ${c.firstName ?? ''} ${c.lastName ?? ''} | ${c.position ?? '—'} | ${c.discoverySource ?? '—'} | ${c.linkedinUrl ?? '—'} |\n`
  }

  if (verdict === '🔴 NO-GO') {
    md += `\n## Recommandations en cas de NO-GO\n\n`
    md += `- Activer fallback PhantomBuster / Unipile en Phase 2 plus tôt que prévu\n`
    md += `- Augmenter profondeur crawl sites corporate (3 niveaux au lieu de 2)\n`
    md += `- Ajouter Bing / DuckDuckGo en parallèle pour Source 4\n`
    md += `- Élargir liste paths URL Source 1 (versions allemandes, anglaises additionnelles)\n`
    md += `- Tester source alternative : LinkedIn Premium API si budget le permet\n`
  }

  md += `\n---\n**POC #3 — ${now}**\n`
  writeFileSync(join(process.cwd(), 'RESULTS.md'), md)

  console.log(`\n=== SUMMARY ===`)
  console.log(`Success rate: ${successRate.toFixed(1)} % (${etisWithCLevel.length}/${results.length})`)
  console.log(`Total LLM cost: ${totalLlmCost.toFixed(2)} €`)
  console.log(`Avg duration / ETI: ${avgDuration.toFixed(1)} s`)
  console.log(`Verdict: ${verdict}`)
  console.log(`\nResults written to RESULTS.md`)
}

main().catch(e => { console.error(e); process.exit(1) })
