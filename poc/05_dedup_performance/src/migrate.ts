/**
 * POC #5 — Migration tables minimales pour benchmark dedup
 *
 * Reproduit la structure spec v1.2 `03_db_schema_phase1.md` :
 * - companies (avec hash dedup)
 * - scraper_runs PARTITIONNÉE par mois (12 partitions)
 * - Index dedup `idx_runs_dedup (target_id, source, completed_at DESC) WHERE status='ok'`
 */
import 'dotenv/config'
import { Client } from 'pg'

const SEED_MONTHS = parseInt(process.env.SEED_MONTHS ?? '12')

async function main() {
  const c = new Client()
  await c.connect()
  console.log('Connected to Postgres', await c.query('SELECT version()').then(r => r.rows[0].version.split(',')[0]))

  console.log('Dropping existing tables (idempotent)...')
  await c.query(`DROP TABLE IF EXISTS scraper_runs CASCADE`)
  await c.query(`DROP TABLE IF EXISTS companies CASCADE`)

  console.log('Creating companies (simplified)...')
  await c.query(`
    CREATE TABLE companies (
      id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
      workspace_id    UUID NOT NULL,
      siren           CHAR(9),
      legal_name      TEXT NOT NULL,
      city_insee      TEXT,
      created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
    )
  `)
  await c.query(`CREATE INDEX idx_companies_workspace ON companies (workspace_id)`)
  await c.query(`CREATE UNIQUE INDEX idx_companies_siren ON companies (workspace_id, siren) WHERE siren IS NOT NULL`)

  console.log('Creating scraper_runs PARTITIONED BY RANGE (started_at)...')
  await c.query(`
    CREATE TABLE scraper_runs (
      id            BIGSERIAL,
      workspace_id  UUID NOT NULL,
      source        TEXT NOT NULL,
      target_id     UUID,
      target_type   TEXT,
      started_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
      completed_at  TIMESTAMPTZ,
      status        TEXT NOT NULL DEFAULT 'ok',
      duration_ms   INT,
      PRIMARY KEY (id, started_at)
    ) PARTITION BY RANGE (started_at)
  `)

  console.log(`Creating ${SEED_MONTHS} monthly partitions...`)
  const now = new Date()
  for (let i = 0; i < SEED_MONTHS; i++) {
    const start = new Date(now.getFullYear(), now.getMonth() - i, 1)
    const end = new Date(now.getFullYear(), now.getMonth() - i + 1, 1)
    const partName = `scraper_runs_${start.getFullYear()}_${String(start.getMonth() + 1).padStart(2, '0')}`
    const startIso = start.toISOString().slice(0, 10)
    const endIso = end.toISOString().slice(0, 10)
    await c.query(`
      CREATE TABLE ${partName} PARTITION OF scraper_runs
        FOR VALUES FROM ('${startIso}') TO ('${endIso}')
    `)
  }

  console.log('Creating indexes on partitioned parent (cascade to partitions)...')
  await c.query(`CREATE INDEX idx_runs_workspace_started ON scraper_runs (workspace_id, started_at DESC)`)
  await c.query(`CREATE INDEX idx_runs_source_status ON scraper_runs (source, status, started_at DESC)`)
  await c.query(`CREATE INDEX idx_runs_target ON scraper_runs (target_id, target_type) WHERE target_id IS NOT NULL`)
  // L'INDEX CRITIQUE pour le POC : c'est lui qui doit faire passer p95 < 50 ms
  await c.query(`CREATE INDEX idx_runs_dedup ON scraper_runs (target_id, source, completed_at DESC) WHERE status = 'ok'`)

  console.log('Migration done ✅')
  await c.end()
}

main().catch(e => { console.error(e); process.exit(1) })
