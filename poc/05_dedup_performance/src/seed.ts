/**
 * POC #5 — Seeder de 10M rows scraper_runs réparties sur 12 partitions mensuelles + 100k companies
 *
 * Stratégie : COPY FROM STDIN (binary mode) = ~10-30x plus rapide que INSERT ligne par ligne.
 * Durée estimée : 5-15 min selon machine (SSD vs HDD significatif).
 */
import 'dotenv/config'
import { Client } from 'pg'
import { from as copyFrom } from 'pg-copy-streams'
import { randomUUID } from 'node:crypto'

const TOTAL_ROWS = parseInt(process.env.SEED_ROWS_TOTAL ?? '10000000')
const TOTAL_COMPANIES = parseInt(process.env.SEED_COMPANIES ?? '100000')
const SEED_MONTHS = parseInt(process.env.SEED_MONTHS ?? '12')

const SOURCES = ['insee', 'annuaire_entreprises', 'google_maps', 'pages_jaunes', 'site_web', 'google_search', 'bodacc', 'france_travail', 'ban']
const STATUSES_WEIGHTED = ['ok', 'ok', 'ok', 'ok', 'ok', 'ok', 'ok', 'failed', 'skipped_already_fresh', 'skipped_opt_out']  // ~70% ok
const WORKSPACE_ID = '11111111-1111-1111-1111-111111111111'

function randomDateInPastMonths(months: number): Date {
  const now = Date.now()
  const offset = Math.random() * months * 30 * 24 * 3600 * 1000
  return new Date(now - offset)
}

function pick<T>(arr: T[]): T { return arr[Math.floor(Math.random() * arr.length)]! }

async function seedCompanies(c: Client, companyIds: string[]): Promise<void> {
  console.log(`Seeding ${TOTAL_COMPANIES} companies...`)
  const t0 = Date.now()
  const stream: any = c.query(copyFrom(
    `COPY companies (id, workspace_id, siren, legal_name, city_insee) FROM STDIN WITH (FORMAT csv, DELIMITER ',')`
  ))
  for (let i = 0; i < TOTAL_COMPANIES; i++) {
    const id = randomUUID()
    companyIds.push(id)
    const siren = String(100000000 + i)
    const name = `TEST Company ${i}`
    const city = String(75000 + Math.floor(Math.random() * 20000)).slice(0, 5)
    const ok = stream.write(`${id},${WORKSPACE_ID},${siren},${name},${city}\n`)
    if (!ok) await new Promise<void>(resolve => stream.once('drain', resolve))
  }
  await new Promise<void>((resolve, reject) => {
    stream.end()
    stream.once('finish', resolve)
    stream.once('error', reject)
  })
  console.log(`  → ${TOTAL_COMPANIES} companies seeded in ${((Date.now() - t0) / 1000).toFixed(1)}s`)
}

async function dropIndexesForBulkLoad(c: Client): Promise<void> {
  console.log('Dropping indexes on scraper_runs for fast COPY (will recreate after)...')
  // Drop all secondary indexes on parent (cascades to partitions). Garde uniquement la PK.
  await c.query('DROP INDEX IF EXISTS idx_runs_workspace_started CASCADE').catch(() => { /* ignore */ })
  await c.query('DROP INDEX IF EXISTS idx_runs_source_status CASCADE').catch(() => { /* ignore */ })
  await c.query('DROP INDEX IF EXISTS idx_runs_target CASCADE').catch(() => { /* ignore */ })
  await c.query('DROP INDEX IF EXISTS idx_runs_dedup CASCADE').catch(() => { /* ignore */ })
}

async function recreateIndexes(c: Client): Promise<void> {
  console.log('Recreating indexes (post-COPY)...')
  const t0 = Date.now()
  await c.query(`CREATE INDEX idx_runs_workspace_started ON scraper_runs (workspace_id, started_at DESC)`)
  console.log(`  idx_runs_workspace_started ✅ (${((Date.now() - t0) / 1000).toFixed(1)}s)`)
  const t1 = Date.now()
  await c.query(`CREATE INDEX idx_runs_source_status ON scraper_runs (source, status, started_at DESC)`)
  console.log(`  idx_runs_source_status ✅ (${((Date.now() - t1) / 1000).toFixed(1)}s)`)
  const t2 = Date.now()
  await c.query(`CREATE INDEX idx_runs_target ON scraper_runs (target_id, target_type) WHERE target_id IS NOT NULL`)
  console.log(`  idx_runs_target ✅ (${((Date.now() - t2) / 1000).toFixed(1)}s)`)
  const t3 = Date.now()
  await c.query(`CREATE INDEX idx_runs_dedup ON scraper_runs (target_id, source, completed_at DESC) WHERE status = 'ok'`)
  console.log(`  idx_runs_dedup ✅ (${((Date.now() - t3) / 1000).toFixed(1)}s)  [INDEX CRITIQUE POC]`)
}

async function seedScraperRuns(c: Client, companyIds: string[]): Promise<void> {
  console.log(`Seeding ${TOTAL_ROWS} scraper_runs (COPY FROM STDIN, indexes dropped, direct write + backpressure)...`)
  const t0 = Date.now()
  const stream: any = c.query(copyFrom(
    `COPY scraper_runs (workspace_id, source, target_id, target_type, started_at, completed_at, status, duration_ms) FROM STDIN WITH (FORMAT csv, DELIMITER ',')`
  ))

  // Écriture directe sur stream Writable avec drain handling = pas d'OOM même à 100M rows.
  for (let i = 0; i < TOTAL_ROWS; i++) {
    const targetId = companyIds[Math.floor(Math.random() * companyIds.length)]!
    const source = pick(SOURCES)
    const status = pick(STATUSES_WEIGHTED)
    const startedAt = randomDateInPastMonths(SEED_MONTHS).toISOString()
    const duration = 1000 + Math.floor(Math.random() * 30000)
    const completedAt = new Date(new Date(startedAt).getTime() + duration).toISOString()
    const ok = stream.write(`${WORKSPACE_ID},${source},${targetId},company,${startedAt},${completedAt},${status},${duration}\n`)
    if (!ok) {
      await new Promise<void>(resolve => stream.once('drain', resolve))
    }
    if (i > 0 && i % 200000 === 0) {
      const elapsed = (Date.now() - t0) / 1000
      const rate = Math.round(i / elapsed)
      console.log(`  ${(i / 1_000_000).toFixed(1)} M rows written (${elapsed.toFixed(0)}s, ${rate} rows/s)`)
    }
  }
  await new Promise<void>((resolve, reject) => {
    stream.end()
    stream.once('finish', resolve)
    stream.once('error', reject)
  })
  console.log(`  → ${TOTAL_ROWS} scraper_runs seeded in ${((Date.now() - t0) / 1000).toFixed(1)}s`)
}

async function analyze(c: Client): Promise<void> {
  console.log('Running ANALYZE on partitions...')
  await c.query('ANALYZE companies')
  await c.query('ANALYZE scraper_runs')
  console.log('  → done')
}

async function main() {
  const c = new Client()
  await c.connect()

  const companyIds: string[] = []
  await seedCompanies(c, companyIds)
  await dropIndexesForBulkLoad(c)
  await seedScraperRuns(c, companyIds)
  await recreateIndexes(c)
  await analyze(c)

  const { rows: countRows } = await c.query('SELECT COUNT(*) AS c FROM scraper_runs')
  console.log(`\nFinal count scraper_runs: ${countRows[0].c}`)

  await c.end()
  console.log('Seed done ✅')
}

main().catch(e => { console.error(e); process.exit(1) })
