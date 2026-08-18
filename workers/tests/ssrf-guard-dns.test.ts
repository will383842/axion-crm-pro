import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

/**
 * SSRF — la moitié du garde-fou que les 9 tests préexistants ne touchent PAS.
 *
 * `ssrf-guard.test.ts` n'envoie que des IP littérales, `localhost` et
 * `metadata.google.internal` : les deux derniers sortent par `DENY_HOSTS`
 * AVANT toute résolution, les autres par `isIP()`. Autrement dit, la branche
 * de résolution DNS (`ssrf-guard.ts:87-99`) — celle qui protège du DNS
 * rebinding, c'est-à-dire d'un nom d'hôte PUBLIC qui résout vers une IP
 * PRIVÉE — n'est jamais exécutée. C'est aussi ce qui explique qu'aucun de ces
 * 9 tests ne dépende du réseau : constat vérifié, pas supposé (cf. le premier
 * test ci-dessous, qui échouerait si `resolve4` était appelée pour une IP).
 *
 * Ici `node:dns` est SIMULÉ : aucune requête réseau, résultats déterministes.
 */

const dnsBouchon = vi.hoisted(() => ({
  resolve4: vi.fn<(h: string) => Promise<string[]>>(),
  resolve6: vi.fn<(h: string) => Promise<string[]>>(),
}));

vi.mock('node:dns', () => ({
  promises: dnsBouchon,
  default: { promises: dnsBouchon },
}));

import { checkSsrf, ensureSsrf } from '../src/utils/ssrf-guard';

beforeEach(() => {
  vi.unstubAllEnvs();
  dnsBouchon.resolve4.mockReset();
  dnsBouchon.resolve6.mockReset();
  dnsBouchon.resolve4.mockResolvedValue([]);
  dnsBouchon.resolve6.mockResolvedValue([]);
});
afterEach(() => {
  vi.unstubAllEnvs();
});

describe('SSRF — aucune résolution DNS pour les cas déjà couverts', () => {
  it('une IP littérale ne déclenche AUCUNE résolution (pas de dépendance réseau en CI)', async () => {
    await checkSsrf('http://192.168.1.1/');
    await checkSsrf('http://93.184.216.34/');
    expect(dnsBouchon.resolve4).not.toHaveBeenCalled();
    expect(dnsBouchon.resolve6).not.toHaveBeenCalled();
  });

  it('un hôte de DENY_HOSTS est refusé AVANT toute résolution', async () => {
    const r = await checkSsrf('http://metadata.google.internal/');
    expect(r.ok).toBe(false);
    expect(r.reason).toBe('deny_host:metadata.google.internal');
    expect(dnsBouchon.resolve4).not.toHaveBeenCalled();
  });
});

describe('SSRF — DNS rebinding (nom public → IP privée)', () => {
  it('refuse un nom d\'hôte public qui résout vers 10.0.0.5', async () => {
    dnsBouchon.resolve4.mockResolvedValue(['10.0.0.5']);
    const r = await checkSsrf('https://interne.exemple.fr/admin');
    expect(r.ok).toBe(false);
    expect(r.reason).toBe('deny_cidr:10.0.0.5');
    expect(dnsBouchon.resolve4).toHaveBeenCalledWith('interne.exemple.fr');
  });

  it('refuse une résolution vers les métadonnées cloud 169.254.169.254', async () => {
    dnsBouchon.resolve4.mockResolvedValue(['169.254.169.254']);
    const r = await checkSsrf('http://innocent.exemple.com/');
    expect(r.ok).toBe(false);
    expect(r.reason).toBe('deny_cidr:169.254.169.254');
  });

  it('refuse 172.16.0.0/12 (RFC 1918 « du milieu », le plus souvent oublié)', async () => {
    dnsBouchon.resolve4.mockResolvedValue(['172.20.10.7']);
    const r = await checkSsrf('http://vpn.exemple.com/');
    expect(r.ok).toBe(false);
    expect(r.reason).toBe('deny_cidr:172.20.10.7');
  });

  it('refuse 100.64.0.0/10 (CGNAT — routable chez un hébergeur)', async () => {
    dnsBouchon.resolve4.mockResolvedValue(['100.100.50.1']);
    const r = await checkSsrf('http://cgnat.exemple.com/');
    expect(r.ok).toBe(false);
    expect(r.reason).toBe('deny_cidr:100.100.50.1');
  });

  it('refuse dès qu\'UNE SEULE des IP renvoyées est privée (round-robin empoisonné)', async () => {
    // Le piège classique : le nom résout vers une IP publique ET une IP
    // privée ; accepter parce que la première est publique laisserait passer.
    dnsBouchon.resolve4.mockResolvedValue(['93.184.216.34', '127.0.0.1']);
    const r = await checkSsrf('http://mixte.exemple.com/');
    expect(r.ok).toBe(false);
    expect(r.reason).toBe('deny_cidr:127.0.0.1');
  });

  it('refuse une résolution AAAA vers ::1 (le blocage IPv6 est fail-closed)', async () => {
    dnsBouchon.resolve6.mockResolvedValue(['::1']);
    const r = await checkSsrf('http://v6.exemple.com/');
    expect(r.ok).toBe(false);
    expect(r.reason).toBe('deny_cidr:::1');
  });

  it('refuse une résolution AAAA vers une adresse locale unique fd00::/8', async () => {
    dnsBouchon.resolve6.mockResolvedValue(['fd00::1']);
    const r = await checkSsrf('http://ula.exemple.com/');
    expect(r.ok).toBe(false);
  });
});

describe('SSRF — cas nominaux et bords de la résolution', () => {
  it('accepte un nom d\'hôte qui résout vers une IP publique', async () => {
    dnsBouchon.resolve4.mockResolvedValue(['93.184.216.34']);
    const r = await checkSsrf('https://exemple.com/page');
    expect(r).toEqual({ ok: true });
  });

  it('refuse un nom sans aucun enregistrement (fail-closed, pas fail-open)', async () => {
    const r = await checkSsrf('https://inexistant.exemple/');
    expect(r.ok).toBe(false);
    expect(r.reason).toBe('dns_no_records');
  });

  it('refuse quand la résolution ÉCHOUE (NXDOMAIN, SERVFAIL) au lieu de laisser passer', async () => {
    dnsBouchon.resolve4.mockRejectedValue(new Error('ENOTFOUND'));
    dnsBouchon.resolve6.mockRejectedValue(new Error('ENOTFOUND'));
    const r = await checkSsrf('https://casse.exemple/');
    expect(r.ok).toBe(false);
    // Les deux appels ont leur propre `.catch(() => [])` : l'échec dégénère en
    // « aucun enregistrement », JAMAIS en autorisation. Le motif `dns_failed`
    // du code est de ce fait INATTEIGNABLE — le comportement, lui, est correct.
    expect(r.reason).toBe('dns_no_records');
  });

  it('interroge A ET AAAA (un nom AAAA-seul ne doit pas passer inaperçu)', async () => {
    dnsBouchon.resolve6.mockResolvedValue(['2606:2800:220:1:248:1893:25c8:1946']);
    const r = await checkSsrf('https://v6only.exemple.com/');
    expect(dnsBouchon.resolve4).toHaveBeenCalledOnce();
    expect(dnsBouchon.resolve6).toHaveBeenCalledOnce();
    expect(r.ok).toBe(true);
  });

  it('SSRF_GUARD_DENY_PRIVATE=false court-circuite AVANT la résolution', async () => {
    vi.stubEnv('SSRF_GUARD_DENY_PRIVATE', 'false');
    dnsBouchon.resolve4.mockResolvedValue(['10.0.0.1']);
    const r = await checkSsrf('http://interne.exemple.fr/');
    expect(r.ok).toBe(true);
    expect(dnsBouchon.resolve4).not.toHaveBeenCalled();
  });
});

describe('ensureSsrf — la forme que les scrapers utilisent réellement', () => {
  it('lève une erreur portant le motif du refus', async () => {
    dnsBouchon.resolve4.mockResolvedValue(['10.1.2.3']);
    await expect(ensureSsrf('http://interne.exemple.fr/')).rejects.toThrow(
      'SSRF guard rejected URL: deny_cidr:10.1.2.3',
    );
  });

  it('ne lève pas pour une URL publique', async () => {
    dnsBouchon.resolve4.mockResolvedValue(['93.184.216.34']);
    await expect(ensureSsrf('https://exemple.com/')).resolves.toBeUndefined();
  });
});
