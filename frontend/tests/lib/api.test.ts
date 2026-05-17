import { describe, it, expect, vi, beforeEach } from 'vitest';

// On mock axios pour vérifier la config sans réseau
vi.mock('axios', () => {
  const create = vi.fn(() => ({
    interceptors: {
      request: { use: vi.fn() },
      response: { use: vi.fn() },
    },
    get: vi.fn(),
    post: vi.fn(),
  }));
  return { default: { create }, create };
});

describe('lib/api', () => {
  beforeEach(() => {
    vi.resetModules();
  });

  it('exporte un objet api', async () => {
    const { api } = await import('@/lib/api');
    expect(api).toBeDefined();
  });

  it('exporte ensureCsrf', async () => {
    const mod = await import('@/lib/api');
    expect(mod.ensureCsrf).toBeInstanceOf(Function);
  });

  it('configure withCredentials = true', async () => {
    const axios = await import('axios');
    await import('@/lib/api');
    expect(axios.default.create).toHaveBeenCalledWith(
      expect.objectContaining({ withCredentials: true })
    );
  });

  it('configure baseURL avec /api/v1 prefix', async () => {
    const axios = await import('axios');
    await import('@/lib/api');
    const call = (axios.default.create as ReturnType<typeof vi.fn>).mock.calls[0]?.[0];
    expect(call.baseURL).toContain('/api/v1');
  });

  it('configure timeout 30s', async () => {
    const axios = await import('axios');
    await import('@/lib/api');
    const call = (axios.default.create as ReturnType<typeof vi.fn>).mock.calls[0]?.[0];
    expect(call.timeout).toBe(30000);
  });
});
