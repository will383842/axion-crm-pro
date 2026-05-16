/**
 * POC #5 — Benchmark dedup
 *
 * Hypothèse spec v1.2 (12_coverage_matrix_deduplication.md § Niveau 3) :
 *   La query `shouldScrape(target_id, source)` doit rester < 50 ms p95 à 10M rows.
 *
 * Implémentation :
 *   - 10 000 queries dedup en parallèle (concurrence 10) sur companies random
 *   - Mesure latence individuelle p50/p95/p99
 *   - EXPLAIN ANALYZE sur 1 sample → vérifie l'usage de idx_runs_dedup
 *
 * Produit RESULTS.md avec verdict GO/NO-GO.
 */
import 'dotenv/config'
import { Pool } from 'pg'
import { performance } from 'node:perf_hooks'
import { writeFileSync } from 'node:fs'
import { join } from 'node:path'

const QUERIES = parseInt(process.env.BENCHMARK_QUERIES ?? '10000')
const CONCURRENCY = parseInt(process.env.BENCHMARK_CONCURRENCY ?? '10')

const SOURCES = ['insee', 'annuaire_entreprises', 'google_maps', 'pages_jaunes', 'site_web', 'google_search', 'bodacc', 'france_travail', 'ban']

const DEDUP_QUERY = `
  SELECT id, completed_at
    FROM scraper_runs
   WHERE target_id = $1
     AND source = $2
     AND status = 'ok'
     AND completed_at > now() - ($3 || ' days')::interval
   ORDER BY completed_at DESC
   LIMIT 1
`

function percentile(sorted: number[], p: number): number {
  const idx = Math.ceil((p / 100) * sorted.length) - 1
  return sorted[Math.max(0, idx)]!
}

async function pickRandomTargetIds(pool: Pool, n: number): Promise<string[]> {
  console.log(`Picking ${n} random company IDs from base...`)
  const { rows } = await pool.query(`SELECT id FROM companies ORDER BY random() LIMIT $1`, [n])
  return rows.map(r => r.id)
}

async function runOneQuery(pool: Pool, targetId: string, source: string): Promise<number> {
  const t = performance.now()
  await pool.query(DEDUP_QUERY, [targetId, source, 90])
  return performance.now() - t
}

async function explainOne(pool: Pool, targetId: string, source: string): Promise<string[]> {
  const { rows } = await pool.query(`EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT) ${DEDUP_QUERY}`, [targetId, source, 90])
  return rows.map(r => r['QUERY PLAN'] as string)
}

async function main() {
  const pool = new Pool({ max: CONCURRENCY + 2 })

  console.log(`\n=== POC #5 — Benchmark dedup 10M rows ===`)
  console.log(`Queries: ${QUERIES}, Concurrency: ${CONCURRENCY}\n`)

  // 1. Récupère un pool de target IDs random
  const targetIds = await pickRandomTargetIds(pool, QUERIES)

  // 2. EXPLAIN sur 1 sample
  console.log('\n--- EXPLAIN ANALYZE sample query ---')
  const explainOutput = await explainOne(pool, targetIds[0]!, SOURCES[0]!)
  explainOutput.forEach(l => console.log(l))
  const usesIndex = explainOutput.some(l => l.includes('idx_runs_dedup'))
  const hasSeqScan = explainOutput.some(l => l.includes('Seq Scan on scraper_runs'))
  console.log(`\nUses idx_runs_dedup: ${usesIndex ? '✅ YES' : '❌ NO'}`)
  console.log(`Has seq scan       : ${hasSeqScan ? '❌ YES (BAD)' : '✅ NO (GOOD)'}`)

  // 3. Benchmark parallèle
  console.log(`\n--- Running ${QUERIES} queries (concurrency ${CONCURRENCY})... ---`)
  const latencies: number[] = []
  const t0 = performance.now()

  // Worker pool simple
  let idx = 0
  async function worker() {
    while (idx < QUERIES) {
      const myIdx = idx++
      if (myIdx >= QUERIES) break
      const targetId = targetIds[myIdx]!
      const source = SOURCES[myIdx % SOURCES.length]!
      const lat = await runOneQuery(pool, targetId, source)
      latencies.push(lat)
      if (myIdx % 1000 === 0 && myIdx > 0) {
        process.stdout.write(`\r  ${myIdx}/${QUERIES} (${((myIdx / QUERIES) * 100).toFixed(0)}%)`)
      }
    }
  }
  await Promise.all(Array.from({ length: CONCURRENCY }, () => worker()))
  const totalElapsed = (performance.now() - t0) / 1000
  console.log(`\n  Done in ${totalElapsed.toFixed(1)}s (${(QUERIES / totalElapsed).toFixed(0)} qps)\n`)

  // 4. Stats
  const sorted = [...latencies].sort((a, b) => a - b)
  const p50 = percentile(sorted, 50)
  const p95 = percentile(sorted, 95)
  const p99 = percentile(sorted, 99)
  const max = sorted[sorted.length - 1]!
  const avg = latencies.reduce((s, x) => s + x, 0) / latencies.length

  console.log('=== RESULTS ===')
  console.log(`  p50  : ${p50.toFixed(2)} ms`)
  console.log(`  p95  : ${p95.toFixed(2)} ms  (cible: < 50 ms)`)
  console.log(`  p99  : ${p99.toFixed(2)} ms`)
  console.log(`  max  : ${max.toFixed(2)} ms`)
  console.log(`  avg  : ${avg.toFixed(2)} ms`)

  // 5. Vérification compte rows
  const { rows: cntRows } = await pool.query('SELECT COUNT(*) AS c FROM scraper_runs')
  const totalRows = parseInt(cntRows[0].c)

  // 6. Verdict
  const verdictP95 = p95 < 50 ? '🟢 GO' : p95 < 100 ? '🟡 GO conditionnel' : '🔴 NO-GO'
  const verdictIndex = usesIndex && !hasSeqScan ? '🟢 OK' : '🔴 PROBLEM'

  // 7. Write RESULTS.md
  const resultsPath = join(process.cwd(), 'RESULTS.md')
  const now = new Date().toISOString().slice(0, 19)
  const resultsMd = `# POC #5 — Anti-doublon perf — RÉSULTATS

> **Date exécution :** ${now}
> **Machine :** ${process.platform} ${process.arch} Node ${process.version}

## Setup

- Volume DB testé : **${totalRows.toLocaleString()} rows scraper_runs** partitionnées
- Partitions : 12 mensuelles
- Index dedup : \`idx_runs_dedup (target_id, source, completed_at DESC) WHERE status='ok'\`
- Queries benchmark : ${QUERIES} (concurrence ${CONCURRENCY})

## Latences mesurées (ms)

| Percentile | Mesuré | Cible spec | Statut |
|------------|--------|------------|--------|
| p50 | ${p50.toFixed(2)} | < 10 | ${p50 < 10 ? '🟢' : p50 < 20 ? '🟡' : '🔴'} |
| **p95** | **${p95.toFixed(2)}** | **< 50** | **${verdictP95}** |
| p99 | ${p99.toFixed(2)} | < 200 | ${p99 < 200 ? '🟢' : '🟡'} |
| max | ${max.toFixed(2)} | — | — |
| moyenne | ${avg.toFixed(2)} | — | — |

Throughput global : **${(QUERIES / totalElapsed).toFixed(0)} qps** sur ${totalElapsed.toFixed(1)}s.

## Vérification plan d'exécution

- Utilise \`idx_runs_dedup\` : ${usesIndex ? '✅ YES' : '❌ NO'}
- Présence Seq Scan sur \`scraper_runs\` : ${hasSeqScan ? '❌ YES (BAD)' : '✅ NO (GOOD)'}
- **Statut index** : ${verdictIndex}

### Plan d'exécution sample

\`\`\`
${explainOutput.join('\n')}
\`\`\`

## Verdict global

| Critère | Statut |
|---------|--------|
| p95 < 50 ms | ${verdictP95} |
| Index utilisé | ${verdictIndex} |

**Verdict : ${verdictP95 === '🟢 GO' && verdictIndex === '🟢 OK' ? '🟢 GO — hypothèse spec validée' : verdictP95.startsWith('🟡') ? '🟡 GO conditionnel — ajuster spec (cf. recommandations)' : '🔴 NO-GO — optimisation indispensable avant Sprint 1'}**

${verdictP95 === '🔴 NO-GO' ? `
## Recommandations en cas de NO-GO

1. Ajouter index covering : \`CREATE INDEX idx_runs_dedup_covering ON scraper_runs (target_id, source) INCLUDE (completed_at, status) WHERE status='ok'\`
2. Tester partitionnement hebdomadaire au lieu de mensuel (réduit la taille des partitions chaudes)
3. Tester \`pg_partman\` retention=90j retention_keep_table=false pour purger partitions anciennes
4. Considérer dénormaliser : ajouter \`companies.last_scraped_at_per_source JSONB\` mis à jour par trigger → check O(1) sur companies au lieu de scraper_runs
` : ''}

---

**POC produit par Axion CRM Pro — POC #5 — ${now}**
`
  writeFileSync(resultsPath, resultsMd)
  console.log(`\n  Results written to ${resultsPath}`)

  await pool.end()
}

main().catch(e => { console.error(e); process.exit(1) })
