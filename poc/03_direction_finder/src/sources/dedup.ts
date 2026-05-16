export interface CLevel {
  firstName?: string
  lastName?: string
  fullName?: string
  position?: string
  positionNormalized?: 'drh' | 'daf' | 'dsi' | 'cmo' | 'cco' | 'ceo' | 'coo' | 'other'
  discoverySource?: 'corporate_pages' | 'press_releases' | 'annual_report' | 'google_search_extended'
  discoveryUrl?: string
  linkedinUrl?: string
  confidence?: number
}

function normalize(s?: string): string {
  if (!s) return ''
  return s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^\w\s]/g, '').replace(/\s+/g, ' ').trim()
}

export function dedupCLevel(found: CLevel[]): CLevel[] {
  const seen = new Map<string, CLevel>()
  for (const m of found) {
    const fn = normalize(m.firstName)
    const ln = normalize(m.lastName) || normalize(m.fullName?.split(' ').slice(-1)[0])
    const key = `${fn}|${ln}`
    if (!seen.has(key) || ((seen.get(key)?.confidence ?? 0) < (m.confidence ?? 0))) {
      seen.set(key, m)
    }
  }
  return [...seen.values()]
}

export function normalizePosition(label?: string): CLevel['positionNormalized'] {
  if (!label) return 'other'
  const s = label.toLowerCase()
  if (/(drh|chro|ressources humaines|human resources|chief human)/i.test(s)) return 'drh'
  if (/(daf|cfo|financier|finance|chief financial)/i.test(s)) return 'daf'
  if (/(dsi|cio|systèmes? d.?information|information systems?|chief information)/i.test(s)) return 'dsi'
  if (/(cmo|marketing|chief marketing)/i.test(s)) return 'cmo'
  if (/(cco|commercial|chief commercial|chief sales|chief revenue|cro)/i.test(s)) return 'cco'
  if (/(ceo|directeur général|président|chief executive|managing director)/i.test(s)) return 'ceo'
  if (/(coo|chief operating|directeur des opérations)/i.test(s)) return 'coo'
  return 'other'
}
