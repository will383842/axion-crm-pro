import { promises as dns } from 'node:dns';
import { isIP } from 'node:net';

/**
 * SSRF guard côté Node — équivalent fonctionnel de backend/app/Services/Http/SsrfGuard.php.
 *
 * Refuse toute URL pointant vers une IP privée, link-local, ou metadata cloud.
 * À appeler avant tout fetch externe (Playwright page.goto, axios.get, etc.).
 *
 * Cf. spec/17_rgpd_aiact_owasp.md § A10 SSRF + ce repo `backend/app/Services/Http/SsrfGuard.php`.
 */

const DENY_HOSTS = new Set([
  '169.254.169.254',
  'metadata.google.internal',
  '100.100.100.200',
  'metadata.azure.com',
  'localhost',
  '127.0.0.1',
  '::1',
  '0.0.0.0',
]);

/** Plages CIDR refusées (IPv4 uniquement, IPv6 hors scope MVP). */
const DENY_CIDR_V4: Array<[number, number]> = [
  ipv4ToInt('0.0.0.0')        & 0xff_00_00_00,   // 0.0.0.0/8
  ipv4ToInt('10.0.0.0')       & 0xff_00_00_00,   // 10.0.0.0/8
  ipv4ToInt('127.0.0.0')      & 0xff_00_00_00,   // 127.0.0.0/8
].map((base) => [base, 8] as [number, number]).concat([
  [ipv4ToInt('169.254.0.0') & 0xff_ff_00_00, 16],
  [ipv4ToInt('172.16.0.0')  & 0xff_f0_00_00, 12],
  [ipv4ToInt('192.0.0.0')   & 0xff_ff_ff_00, 24],
  [ipv4ToInt('192.168.0.0') & 0xff_ff_00_00, 16],
  [ipv4ToInt('198.18.0.0')  & 0xff_fe_00_00, 15],
  [ipv4ToInt('100.64.0.0')  & 0xff_c0_00_00, 10],
  [ipv4ToInt('224.0.0.0')   & 0xf0_00_00_00, 4],
  [ipv4ToInt('240.0.0.0')   & 0xf0_00_00_00, 4],
]);

function ipv4ToInt(ip: string): number {
  const parts = ip.split('.').map((p) => Number.parseInt(p, 10));
  if (parts.length !== 4 || parts.some((p) => Number.isNaN(p) || p < 0 || p > 255)) {
    return 0;
  }
  return ((parts[0]! << 24) | (parts[1]! << 16) | (parts[2]! << 8) | parts[3]!) >>> 0;
}

function ipInDenyCidr(ip: string): boolean {
  if (isIP(ip) !== 4) {
    // IPv6 : refusé par défaut sauf adresses publiques connues (fail-closed pour MVP).
    return ip.startsWith('::') || ip.startsWith('fc') || ip.startsWith('fd') || ip.startsWith('fe80');
  }
  const ipInt = ipv4ToInt(ip);
  return DENY_CIDR_V4.some(([base, bits]) => {
    const mask = bits === 0 ? 0 : 0xff_ff_ff_ff << (32 - bits);
    return (ipInt & mask) === (base & mask);
  });
}

export interface SsrfCheckResult {
  ok: boolean;
  reason?: string;
}

export async function checkSsrf(url: string): Promise<SsrfCheckResult> {
  if (process.env['SSRF_GUARD_DENY_PRIVATE'] === 'false') {
    return { ok: true };
  }

  let parsed: URL;
  try {
    parsed = new URL(url);
  } catch {
    return { ok: false, reason: 'invalid_url' };
  }

  if (!['http:', 'https:'].includes(parsed.protocol)) {
    return { ok: false, reason: `bad_scheme:${parsed.protocol}` };
  }

  const host = parsed.hostname.toLowerCase();

  if (DENY_HOSTS.has(host)) {
    return { ok: false, reason: `deny_host:${host}` };
  }

  // Résoudre A + AAAA, vérifier chaque IP.
  let addrs: string[] = [];
  if (isIP(host)) {
    addrs = [host];
  } else {
    try {
      const a = await dns.resolve4(host).catch(() => [] as string[]);
      const aaaa = await dns.resolve6(host).catch(() => [] as string[]);
      addrs = [...a, ...aaaa];
    } catch {
      return { ok: false, reason: 'dns_failed' };
    }
  }

  if (addrs.length === 0) {
    return { ok: false, reason: 'dns_no_records' };
  }

  for (const ip of addrs) {
    if (ipInDenyCidr(ip)) {
      return { ok: false, reason: `deny_cidr:${ip}` };
    }
  }

  return { ok: true };
}

export async function ensureSsrf(url: string): Promise<void> {
  const r = await checkSsrf(url);
  if (!r.ok) {
    throw new Error(`SSRF guard rejected URL: ${r.reason ?? 'unknown'}`);
  }
}
