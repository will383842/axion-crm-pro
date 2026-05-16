/**
 * POC #2 — Synthèse 5 jours → RESULTS.md
 */
import { readFileSync, writeFileSync, existsSync } from 'node:fs'
import { join } from 'node:path'

interface QueryResult {
  target_type: string
  target: any
  engine_used: string
  status: string
  results_count: number
  best_url?: string
  best_confidence?: number
  duration_ms: number
  timestamp: string
}

function main() {
  const days: Array<{ day: number; results: QueryResult[]; completed_at: string }> = []
  for (let d = 1; d <= 5; d++) {
    const f = join(process.cwd(), `results/day_${d}.json`)
    if (existsSync(f)) days.push(JSON.parse(readFileSync(f, 'utf8')))
  }
  if (days.length === 0) {
    console.error('Aucun results/day_N.json trouvé.')
    process.exit(1)
  }

  let md = `# POC #2 — Google Search Wrapper — RÉSULTATS\n\n`
  md += `> **Période :** ${days[0]!.completed_at.slice(0, 10)} → ${days[days.length - 1]!.completed_at.slice(0, 10)}\n`
  md += `> **Jours testés :** ${days.length} / 5\n\n`

  md += `## Évolution jour par jour\n\n`
  md += `| Jour | Total | OK direct | Captcha solved | Captcha blocked | No result | Succès % | Captcha rate % |\n|---|---|---|---|---|---|---|---|\n`

  const allResults: QueryResult[] = []
  for (const d of days) {
    allResults.push(...d.results)
    const r = d.results
    const ok = r.filter(x => x.status === 'ok').length
    const cs = r.filter(x => x.status === 'captcha_solved').length
    const cb = r.filter(x => x.status === 'captcha_unsolved' || x.status === 'captcha_v3_blocked' || x.status === 'unusual_traffic').length
    const nr = r.filter(x => x.status === 'no_results').length
    const successPct = ((ok + cs) / r.length) * 100
    const captchaPct = ((cs + cb) / r.length) * 100
    md += `| ${d.day} | ${r.length} | ${ok} | ${cs} | ${cb} | ${nr} | ${successPct.toFixed(1)} % | ${captchaPct.toFixed(1)} % |\n`
  }

  // === Verdict ===
  const totalOk = allResults.filter(r => r.status === 'ok' || r.status === 'captcha_solved').length
  const totalCaptchaBlocked = allResults.filter(r => r.status === 'captcha_unsolved' || r.status === 'captcha_v3_blocked' || r.status === 'unusual_traffic').length
  const captchaResidualRate = (totalCaptchaBlocked / allResults.length) * 100

  const company = allResults.filter(r => r.target_type === 'company')
  const person = allResults.filter(r => r.target_type === 'person')
  const companyFound = company.filter(r => (r.results_count ?? 0) > 0 && (r.best_confidence ?? 0) >= 70).length
  const personFound = person.filter(r => (r.results_count ?? 0) > 0 && (r.best_confidence ?? 0) >= 70).length
  const companyRate = company.length ? (companyFound / company.length) * 100 : 0
  const personRate = person.length ? (personFound / person.length) * 100 : 0

  const verdict = captchaResidualRate < 15 && companyRate >= 70 && personRate >= 50 ? '🟢 GO'
    : captchaResidualRate < 30 ? '🟡 GO conditionnel' : '🔴 NO-GO'

  md += `\n## KPIs globaux\n\n`
  md += `| Métrique | Mesure | Cible | Statut |\n|---|---|---|---|\n`
  md += `| **Captcha rate résiduel post-2captcha** | **${captchaResidualRate.toFixed(1)} %** | < 15 % | ${captchaResidualRate < 15 ? '🟢' : captchaResidualRate < 30 ? '🟡' : '🔴'} |\n`
  md += `| URLs LinkedIn entreprises trouvées | ${companyRate.toFixed(1)} % | ≥ 70 % | ${companyRate >= 70 ? '🟢' : '🟡'} |\n`
  md += `| URLs LinkedIn personnes trouvées | ${personRate.toFixed(1)} % | ≥ 50 % | ${personRate >= 50 ? '🟢' : '🟡'} |\n`
  md += `\n## Répartition par moteur\n\n`
  md += `| Moteur | Queries | OK | Captcha |\n|---|---|---|---|\n`
  for (const eng of ['google', 'bing', 'duckduckgo']) {
    const r = allResults.filter(x => x.engine_used === eng)
    const ok = r.filter(x => x.status === 'ok' || x.status === 'captcha_solved').length
    const capt = r.filter(x => x.status.startsWith('captcha') || x.status === 'unusual_traffic').length
    md += `| ${eng} | ${r.length} | ${ok} | ${capt} |\n`
  }

  md += `\n## Verdict : ${verdict}\n`

  if (verdict === '🔴 NO-GO') {
    md += `\n## Recommandations en cas de NO-GO\n\n`
    md += `- Bascule sur PhantomBuster Phase 2 plus tôt que prévu (~70-200 $/mo)\n`
    md += `- Ajouter Brave Search + Startpage en rotation supplémentaire\n`
    md += `- Augmenter cooldown moteur après captcha (60 min → 2h)\n`
    md += `- Tester proxies mobiles premium (BrightData) plus difficiles à détecter\n`
  }

  md += `\n---\n**POC #2 — synthèse générée ${new Date().toISOString().slice(0, 19)}**\n`
  writeFileSync(join(process.cwd(), 'RESULTS.md'), md)
  console.log(`\nVerdict : ${verdict}`)
  console.log(`Captcha residual rate : ${captchaResidualRate.toFixed(1)} %`)
  console.log(`Company find rate     : ${companyRate.toFixed(1)} %`)
  console.log(`Person find rate      : ${personRate.toFixed(1)} %`)
  console.log(`Results written to RESULTS.md`)
}

main()
