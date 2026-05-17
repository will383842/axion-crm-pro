import { describe, it, expect, beforeEach, vi } from 'vitest';
import { checkSsrf } from '../src/utils/ssrf-guard';

describe('SSRF Guard Node', () => {
  beforeEach(() => {
    vi.unstubAllEnvs();
  });

  it('blocks AWS metadata IP 169.254.169.254', async () => {
    const r = await checkSsrf('http://169.254.169.254/latest/meta-data/');
    expect(r.ok).toBe(false);
    expect(r.reason).toMatch(/deny_cidr|deny_host/);
  });

  it('blocks GCP metadata hostname', async () => {
    const r = await checkSsrf('http://metadata.google.internal/');
    expect(r.ok).toBe(false);
  });

  it('blocks localhost', async () => {
    const r = await checkSsrf('http://localhost/');
    expect(r.ok).toBe(false);
  });

  it('blocks 127.0.0.1', async () => {
    const r = await checkSsrf('http://127.0.0.1/');
    expect(r.ok).toBe(false);
  });

  it('blocks RFC 1918 192.168.x', async () => {
    const r = await checkSsrf('http://192.168.1.1/');
    expect(r.ok).toBe(false);
  });

  it('blocks 10.x', async () => {
    const r = await checkSsrf('http://10.0.0.1/');
    expect(r.ok).toBe(false);
  });

  it('rejects non-http/https schemes', async () => {
    const r = await checkSsrf('file:///etc/passwd');
    expect(r.ok).toBe(false);
    expect(r.reason).toMatch(/bad_scheme/);
  });

  it('rejects invalid URLs', async () => {
    const r = await checkSsrf('not-a-url');
    expect(r.ok).toBe(false);
    expect(r.reason).toBe('invalid_url');
  });

  it('respects SSRF_GUARD_DENY_PRIVATE=false override', async () => {
    vi.stubEnv('SSRF_GUARD_DENY_PRIVATE', 'false');
    const r = await checkSsrf('http://127.0.0.1/');
    expect(r.ok).toBe(true);
  });
});
