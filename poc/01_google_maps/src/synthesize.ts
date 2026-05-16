/**
 * POC #1 — Synthèse 7 jours → RESULTS.md avec verdict GO/NO-GO
 */
import { readFileSync, writeFileSync, existsSync } from 'node:fs'
import { join } from 'node:path'

interface ScrapeResult {
  legal_name: string
  city: string
  status: string
  duration_ms: number
  proxy_session: string
  user_agent: string
  timestamp: string
  data?: any
  error?: string
}

interface DayData { day: number; results: ScrapeResult[]; completed_at: string }

function percentile(sorted: number[], p: number): number {
  if (sorted.length === 0) return 0
  return sorted[Math.ceil((p / 100) * sorted.length) - 1]!
}

function main() {
  const days: DayData[] = []
  for (let d = 1; d <= 7; d++) {
    const f = join(process.cwd(), `results/day_${d}.json`)
    if (existsSync(f)) {
      days.push(JSON.parse(readFileSync(f, 'utf8')))
    }
  }
  if (days.length === 0) {
    console.error('Aucun fichier results/day_N.json trouvé. Lance d\'abord `pnpm run run -- --day N`.')
    process.exit(1)
  }

  let md = `# POC #1 — Google Maps scraping anti-ban — RÉSULTATS\n\n`
  md += `> **Période :** ${days[0]!.completed_at.slice(0, 10)} → ${days[days.length - 1]!.completed_at.slice(0, 10)}\n`
  md += `> **Jours testés :** ${days.length} / 7\n\n`

  md += `## Évolution success rate jour par jour\n\n`
  md += `| Jour | Total | OK | Captcha | No result | Erreur | Success % | Latence p95 ms |\n|---|---|---|---|---|---|---|---|\n`

  const allResults: ScrapeResult[] = []
  for (const d of days) {
    const r = d.results
    allResults.push(...r)
    const ok = r.filter(x => x.status === 'ok').length
    const capt = r.filter(x => x.status === 'captcha').length
    const noRes = r.filter(x => x.status === 'no_result').length
    const err = r.filter(x => x.status === 'error' || x.status === 'timeout').length
    const sr = (ok / r.length) * 100
    const lats = r.filter(x => x.status === 'ok').map(x => x.duration_ms).sort((a, b) => a - b)
    const p95 = percentile(lats, 95)
    md += `| ${d.day} | ${r.length} | ${ok} | ${capt} | ${noRes} | ${err} | ${sr.toFixed(1)} % | ${p95} |\n`
  }

  // === Critères GO/NO-GO ===
  const lastDay = days[days.length - 1]!
  const lastDayOk = lastDay.results.filter(x => x.status === 'ok').length
  const lastDaySr = (lastDayOk / lastDay.results.length) * 100
  const totalCaptchas = allResults.filter(x => x.status === 'captcha').length
  const captchaRate = (totalCaptchas / allResults.length) * 100
  const uniqueSessions = new Set(allResults.map(x => x.proxy_session)).size

  const verdict = lastDaySr >= 75 ? '🟢 GO' : lastDaySr >= 50 ? '🟡 GO conditionnel' : '🔴 NO-GO'

  md += `\n## KPIs globaux\n\n`
  md += `| Métrique | Mesure | Cible | Statut |\n|---|---|---|---|\n`
  md += `| **Success rate jour ${lastDay.day}** | **${lastDaySr.toFixed(1)} %** | ≥ 75 % | ${lastDaySr >= 75 ? '🟢' : lastDaySr >= 50 ? '🟡' : '🔴'} |\n`
  md += `| Captcha rate global | ${captchaRate.toFixed(1)} % | < 15 % | ${captchaRate < 15 ? '🟢' : '🟡'} |\n`
  md += `| Sessions IPRoyal distinctes utilisées | ${uniqueSessions} | — | — |\n`
  md += `\n## Verdict : ${verdict}\n`

  if (verdict === '🔴 NO-GO') {
    md += `\n## Recommandations\n\n`
    md += `- Bascule sur Smartproxy résidentiel premium (75-150 $/mo)\n`
    md += `- Augmenter cooldown entre scrapings (30s au lieu de 15s)\n`
    md += `- Réduire concurrence à 1 worker\n`
    md += `- Activer 2captcha pour résoudre captchas v2\n`
    md += `- Considérer alternative : import direct via PagesJaunes (source 7) si Google Maps trop hostile\n`
  }

  md += `\n## Top 10 erreurs détaillées\n\n`
  const errors = allResults.filter(x => x.status === 'error' || x.status === 'captcha').slice(0, 10)
  if (errors.length > 0) {
    md += `| Date | Entreprise | Ville | Status | Erreur |\n|---|---|---|---|---|\n`
    for (const e of errors) {
      md += `| ${e.timestamp.slice(0, 16)} | ${e.legal_name} | ${e.city} | ${e.status} | ${e.error ?? ''} |\n`
    }
  }

  md += `\n---\n**POC #1 — synthèse générée ${new Date().toISOString().slice(0, 19)}**\n`
  writeFileSync(join(process.cwd(), 'RESULTS.md'), md)
  console.log(`\nVerdict : ${verdict}`)
  console.log(`Last day success rate : ${lastDaySr.toFixed(1)} %`)
  console.log(`Results written to RESULTS.md`)
}

main()
