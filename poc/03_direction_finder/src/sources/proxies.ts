import { readFileSync } from 'node:fs'

/** Charge un fichier proxies.txt : 1 ligne par proxy au format user:pass@host:port */
export function loadProxies(path: string): string[] {
  const content = readFileSync(path, 'utf8')
  return content
    .split('\n')
    .map(l => l.trim())
    .filter(l => l && !l.startsWith('#'))
    .map(l => l.startsWith('http://') || l.startsWith('https://') ? l : `http://${l}`)
}

export function pickProxy(proxies: string[]): string | undefined {
  if (proxies.length === 0) return undefined
  return proxies[Math.floor(Math.random() * proxies.length)]
}

const USER_AGENTS = [
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
  'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:130.0) Gecko/20100101 Firefox/130.0',
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0',
]

export function pickUserAgent(): string {
  return USER_AGENTS[Math.floor(Math.random() * USER_AGENTS.length)]!
}
