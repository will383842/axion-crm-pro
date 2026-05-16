const UAS = [
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
  'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0',
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:130.0) Gecko/20100101 Firefox/130.0',
]

export function pickUserAgent(): string { return UAS[Math.floor(Math.random() * UAS.length)]! }

export function makeIPRoyalSession(sessionId: string): string {
  const user = process.env.IPROYAL_USERNAME!
  const pass = process.env.IPROYAL_PASSWORD!
  const gw = process.env.IPROYAL_GATEWAY ?? 'geo.iproyal.com:12321'
  return `http://${user}_country-fr_session-${sessionId}_lifetime-30m:${pass}@${gw}`
}
