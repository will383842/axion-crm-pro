/**
 * POC #4 — Validation cascade SMTP N1→N5
 *
 * Implémentation conforme spec v1.2 `06_email_finder_validation.md` § 3.
 * - N1 : syntaxe RFC 5322
 * - N2 : DNS MX lookup
 * - N3 : SMTP handshake EHLO + MAIL FROM + RCPT TO
 * - N4 : catch-all detection (probe random local-part)
 * - N5 : scoring 0-100
 */
import 'dotenv/config'
import { promises as dnsp } from 'node:dns'
import { createConnection, Socket } from 'node:net'
import { readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { randomBytes } from 'node:crypto'

type Label = 'valid' | 'invalid' | 'catch_all' | 'role_based' | 'disposable' | 'no_reply'

interface GoldEntry { email: string; expected: Label; note: string }
interface GoldDataset { emails: GoldEntry[] }

interface ProbeResult {
  email: string
  syntax_ok: boolean
  mx_records: string[]
  smtp_response_code: number | null
  smtp_response_message: string
  status: Label | 'unknown' | 'unreachable'
  score: number
  is_catch_all: boolean
  is_disposable: boolean
  is_role_based: boolean
  duration_ms: number
  error?: string
}

const FROM_EMAIL = process.env.VALIDATOR_FROM_EMAIL ?? 'validator@example.com'
const HELO_DOMAIN = process.env.VALIDATOR_HELO_DOMAIN ?? 'example.com'
const TIMEOUT_CONNECT_MS = parseInt(process.env.TIMEOUT_CONNECT_MS ?? '10000')
const TIMEOUT_COMMAND_MS = parseInt(process.env.TIMEOUT_COMMAND_MS ?? '5000')
const CONCURRENCY = parseInt(process.env.CONCURRENCY ?? '5')

const DISPOSABLE_DOMAINS = new Set([
  'mailinator.com','guerrillamail.com','tempmail.com','10minutemail.com',
  'throwaway.email','yopmail.com','dispostable.com','sharklasers.com',
  'maildrop.cc','getairmail.com','trbvm.com','tempinbox.com',
])

const ROLE_BASED = new Set([
  'contact','info','infos','hello','bonjour','sales','vente','rh','hr',
  'support','admin','webmaster','direction','recrutement','careers','jobs',
  'presse','press','marketing','commercial','dpo','legal','accounting','compta','help',
])

const NO_REPLY = new Set(['no-reply','noreply','donotreply','do-not-reply','postmaster','mailer-daemon','bounce'])

// === N1 — Syntax ===
function validateSyntax(email: string): boolean {
  if (email.length > 254) return false
  if (email.includes('..')) return false
  // Regex RFC 5322 simplifié mais strict
  return /^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/.test(email)
}

// === N2 — DNS MX ===
async function lookupMx(domain: string): Promise<string[]> {
  try {
    const records = await dnsp.resolveMx(domain)
    records.sort((a, b) => a.priority - b.priority)
    return records.map(r => r.exchange)
  } catch {
    return []
  }
}

// === N3 — SMTP handshake ===
async function smtpProbe(email: string, mxHost: string): Promise<{ code: number | null; message: string; error?: string }> {
  return new Promise((resolve) => {
    const sock = createConnection({ host: mxHost, port: 25 })
    let buffer = ''
    let state: 'connect' | 'ehlo' | 'mailfrom' | 'rcptto' | 'quit' | 'done' = 'connect'
    let lastCode: number | null = null
    let lastMessage = ''
    let resolved = false

    const cleanup = () => {
      try { sock.destroy() } catch {/* ignore */ }
    }
    const done = (code: number | null, msg: string, error?: string) => {
      if (resolved) return
      resolved = true
      cleanup()
      resolve({ code, message: msg, error })
    }

    sock.setTimeout(TIMEOUT_CONNECT_MS)
    sock.on('timeout', () => done(null, '', 'timeout'))
    sock.on('error', (err) => done(null, '', err.message))
    sock.on('close', () => { if (!resolved) done(lastCode, lastMessage) })

    sock.on('data', (data) => {
      buffer += data.toString('utf8')
      const lines = buffer.split('\r\n')
      buffer = lines.pop() ?? ''
      for (const line of lines) {
        if (line.length < 4) continue
        const code = parseInt(line.slice(0, 3))
        const cont = line.charAt(3) === '-' // multiline continue
        lastCode = code
        lastMessage = line.slice(4)
        if (cont) continue
        try {
          if (state === 'connect' && code === 220) {
            sock.write(`EHLO ${HELO_DOMAIN}\r\n`)
            state = 'ehlo'
          } else if (state === 'ehlo' && code === 250) {
            sock.write(`MAIL FROM:<${FROM_EMAIL}>\r\n`)
            state = 'mailfrom'
            sock.setTimeout(TIMEOUT_COMMAND_MS)
          } else if (state === 'mailfrom' && code === 250) {
            sock.write(`RCPT TO:<${email}>\r\n`)
            state = 'rcptto'
            sock.setTimeout(TIMEOUT_COMMAND_MS)
          } else if (state === 'rcptto') {
            // Réponse au RCPT — c'est ce qui nous intéresse
            sock.write('QUIT\r\n')
            state = 'quit'
            done(code, lastMessage)
          } else if (state === 'quit') {
            done(lastCode, lastMessage)
          } else {
            // Code d'erreur reçu trop tôt
            done(code, lastMessage)
          }
        } catch (err: any) {
          done(null, '', err?.message ?? 'unknown')
        }
      }
    })
  })
}

// === N4 — Catch-all detection ===
async function detectCatchAll(domain: string, mxHosts: string[]): Promise<boolean> {
  const randomLocal = randomBytes(8).toString('hex')
  const probeEmail = `${randomLocal}@${domain}`
  for (const mx of mxHosts) {
    const r = await smtpProbe(probeEmail, mx)
    if (r.code && r.code >= 200 && r.code < 300) return true   // accepte un email random → catch-all
    if (r.code === 252) return true                              // "cannot verify" suspect
    if (r.code && r.code >= 500) return false                    // refuse clean → pas catch-all
  }
  return false
}

// === N5 — Scoring ===
function scoreEmail(probe: { code: number | null; isCatchAll: boolean; isRoleBased: boolean; isDisposable: boolean }): { status: Label | 'unknown' | 'unreachable'; score: number } {
  if (probe.code === null) return { status: 'unreachable', score: 20 }
  if (probe.code >= 500) return { status: 'invalid', score: 0 }   // 550, 551 = mailbox doesn't exist
  if (probe.isDisposable) return { status: 'disposable', score: 5 }
  if (probe.isCatchAll) {
    return { status: 'catch_all', score: probe.isRoleBased ? 40 : 60 }
  }
  if (probe.code >= 200 && probe.code < 300) {
    let s = 80
    if (probe.isRoleBased) s -= 10
    return { status: 'valid', score: s }
  }
  if (probe.code === 252) return { status: 'unknown', score: 50 }
  if (probe.code >= 400 && probe.code < 500) return { status: 'unknown', score: 30 }
  return { status: 'unknown', score: 25 }
}

// === Validate one email ===
async function validate(email: string): Promise<ProbeResult> {
  const t0 = Date.now()
  const result: ProbeResult = {
    email,
    syntax_ok: false,
    mx_records: [],
    smtp_response_code: null,
    smtp_response_message: '',
    status: 'unknown',
    score: 0,
    is_catch_all: false,
    is_disposable: false,
    is_role_based: false,
    duration_ms: 0,
  }

  // N1
  if (!validateSyntax(email)) {
    result.status = 'invalid'
    result.score = 0
    result.duration_ms = Date.now() - t0
    return result
  }
  result.syntax_ok = true

  const [localPart, domain] = email.toLowerCase().split('@') as [string, string]
  result.is_disposable = DISPOSABLE_DOMAINS.has(domain)
  if (NO_REPLY.has(localPart) || [...NO_REPLY].some(n => localPart.startsWith(`${n}.`))) {
    result.status = 'no_reply'
    result.score = 0
    result.duration_ms = Date.now() - t0
    return result
  }
  result.is_role_based = ROLE_BASED.has(localPart) || [...ROLE_BASED].some(r => localPart.startsWith(`${r}.`) || localPart.startsWith(`${r}-`))

  // N2
  result.mx_records = await lookupMx(domain)
  if (result.mx_records.length === 0) {
    result.status = 'invalid'
    result.score = 5
    result.duration_ms = Date.now() - t0
    return result
  }

  // N3
  let smtpResp: { code: number | null; message: string; error?: string } | null = null
  for (const mx of result.mx_records) {
    const r = await smtpProbe(email, mx)
    if (r.code !== null) { smtpResp = r; break }
    if (r.error && r.error !== 'timeout') { smtpResp = r; break }
    smtpResp = r
  }
  if (smtpResp) {
    result.smtp_response_code = smtpResp.code
    result.smtp_response_message = smtpResp.message
    if (smtpResp.error) result.error = smtpResp.error
  }

  // N4
  if (smtpResp && smtpResp.code && smtpResp.code >= 200 && smtpResp.code < 300) {
    result.is_catch_all = await detectCatchAll(domain, result.mx_records)
  }

  // N5
  const verdict = scoreEmail({
    code: smtpResp?.code ?? null,
    isCatchAll: result.is_catch_all,
    isRoleBased: result.is_role_based,
    isDisposable: result.is_disposable,
  })
  result.status = verdict.status
  result.score = verdict.score

  result.duration_ms = Date.now() - t0
  return result
}

// === Run benchmark ===
async function main() {
  const datasetPath = join(process.cwd(), 'datasets', 'emails_gold.json')
  const dataset: GoldDataset = JSON.parse(readFileSync(datasetPath, 'utf8'))

  // Filtre les emails fictifs (REPLACE_WITH_*) — ne testera que les vrais
  const realEmails = dataset.emails.filter(e => !e.email.startsWith('REPLACE_WITH'))
  const skipped = dataset.emails.length - realEmails.length
  if (skipped > 0) {
    console.log(`⚠️  ${skipped} emails fictifs ignorés (REPLACE_WITH_*). Voir README §3.\n`)
  }

  console.log(`\n=== POC #4 — Validation SMTP cascade ===`)
  console.log(`Dataset gold : ${realEmails.length} emails réels`)
  console.log(`Concurrency  : ${CONCURRENCY}`)
  console.log(`From         : ${FROM_EMAIL}`)
  console.log(`HELO         : ${HELO_DOMAIN}\n`)

  const results: Array<ProbeResult & { expected: Label }> = []
  let idx = 0
  async function worker() {
    while (idx < realEmails.length) {
      const myIdx = idx++
      if (myIdx >= realEmails.length) break
      const entry = realEmails[myIdx]!
      try {
        const r = await validate(entry.email)
        results.push({ ...r, expected: entry.expected })
        const ok = r.status === entry.expected || (r.status === 'valid' && entry.expected === 'catch_all')
        console.log(`  [${myIdx + 1}/${realEmails.length}] ${entry.email.padEnd(50)} expected=${entry.expected.padEnd(11)} got=${r.status.padEnd(11)} score=${r.score} ${ok ? '✅' : '❌'}`)
      } catch (e: any) {
        console.log(`  [${myIdx + 1}/${realEmails.length}] ${entry.email.padEnd(50)} ERROR: ${e?.message ?? e}`)
      }
    }
  }
  await Promise.all(Array.from({ length: CONCURRENCY }, () => worker()))

  // === Confusion matrix ===
  const labels: Array<Label | 'unknown' | 'unreachable'> = ['valid', 'invalid', 'catch_all', 'role_based', 'disposable', 'no_reply', 'unknown', 'unreachable']
  const matrix: Record<string, Record<string, number>> = {}
  for (const e of labels) {
    matrix[e] = {}
    for (const a of labels) matrix[e]![a] = 0
  }
  let correct = 0
  for (const r of results) {
    const expectedKey = r.expected
    const actualKey = r.status
    matrix[expectedKey]![actualKey] = (matrix[expectedKey]![actualKey] ?? 0) + 1
    // valid vs catch_all : on accepte les 2 comme "actionables"
    if (r.status === r.expected) correct++
    else if (r.expected === 'catch_all' && r.status === 'valid') correct++
    else if (r.expected === 'valid' && r.status === 'catch_all' && r.score >= 50) correct++ // tolérance
  }
  const accuracy = (correct / results.length) * 100

  // False positive rate (invalid classés valid)
  const invalidExpected = results.filter(r => r.expected === 'invalid').length
  const invalidClassifiedValid = results.filter(r => r.expected === 'invalid' && r.status === 'valid').length
  const fpr = invalidExpected > 0 ? (invalidClassifiedValid / invalidExpected) * 100 : 0

  const validRecall = (results.filter(r => r.expected === 'valid' && (r.status === 'valid' || r.status === 'catch_all')).length /
                       Math.max(1, results.filter(r => r.expected === 'valid').length)) * 100

  // === Write RESULTS.md ===
  const now = new Date().toISOString().slice(0, 19)
  const verdict = accuracy >= 90 && fpr < 5 ? '🟢 GO' : accuracy >= 80 ? '🟡 GO conditionnel' : '🔴 NO-GO'

  let md = `# POC #4 — Validation SMTP cascade — RÉSULTATS\n\n`
  md += `> **Date :** ${now}\n`
  md += `> **Dataset :** ${results.length}/${dataset.emails.length} emails testés (les autres = templates REPLACE_WITH)\n\n`
  md += `## KPIs principaux\n\n`
  md += `| Métrique | Mesure | Cible | Statut |\n|---|---|---|---|\n`
  md += `| **Accuracy globale** | **${accuracy.toFixed(1)} %** | ≥ 90 % | ${accuracy >= 90 ? '🟢' : accuracy >= 80 ? '🟡' : '🔴'} |\n`
  md += `| Recall valid | ${validRecall.toFixed(1)} % | ≥ 85 % | ${validRecall >= 85 ? '🟢' : '🟡'} |\n`
  md += `| **False positive rate** | **${fpr.toFixed(1)} %** | < 5 % | ${fpr < 5 ? '🟢' : '🔴'} |\n\n`
  md += `## Confusion matrix\n\n`
  md += `Lignes = expected (gold), colonnes = actual (cascade SMTP)\n\n`
  md += `| Expected ↓ / Actual → | ${labels.join(' | ')} |\n`
  md += `|${'---|'.repeat(labels.length + 1)}\n`
  for (const e of labels) {
    md += `| **${e}** | ${labels.map(a => matrix[e]![a] ?? 0).join(' | ')} |\n`
  }
  md += `\n## Erreurs détaillées (échantillon)\n\n`
  const errors = results.filter(r => r.status !== r.expected && !(r.expected === 'catch_all' && r.status === 'valid')).slice(0, 20)
  if (errors.length > 0) {
    md += `| Email | Expected | Got | SMTP code | Note |\n|---|---|---|---|---|\n`
    for (const e of errors) {
      md += `| ${e.email} | ${e.expected} | ${e.status} | ${e.smtp_response_code ?? '—'} | ${e.error ?? ''} |\n`
    }
  } else {
    md += `_Aucune erreur._\n`
  }
  md += `\n## Verdict\n\n**${verdict}**\n\n`
  if (verdict === '🔴 NO-GO') {
    md += `\n## Recommandations en cas de NO-GO\n\n`
    md += `- Activer fallback API tierce (Hunter.io, MillionVerifier, Kickbox) pour score < 60\n`
    md += `- Réviser pondération scoring (cf. \`06_email_finder_validation.md\` § 3 N5)\n`
    md += `- Vérifier que FROM_EMAIL est un domaine légitime non blacklisté\n`
    md += `- Vérifier que IP émettrice est dans une plage propre (Hetzner OK généralement)\n`
  }
  md += `\n---\n**POC #4 — ${now}**\n`

  writeFileSync(join(process.cwd(), 'RESULTS.md'), md)
  console.log(`\n=== SUMMARY ===`)
  console.log(`Accuracy : ${accuracy.toFixed(1)} %`)
  console.log(`FPR      : ${fpr.toFixed(1)} %`)
  console.log(`Verdict  : ${verdict}`)
  console.log(`Results written to RESULTS.md`)
}

main().catch(e => { console.error(e); process.exit(1) })
