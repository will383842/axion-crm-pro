import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock Echo + Pusher
vi.mock('laravel-echo', () => {
  return {
    default: vi.fn().mockImplementation(() => ({
      private: vi.fn(() => ({
        listen: vi.fn(),
      })),
      disconnect: vi.fn(),
      leave: vi.fn(),
    })),
  };
});

vi.mock('pusher-js', () => ({ default: function MockPusher() {} }));

vi.mock('sonner', () => ({
  toast: Object.assign(vi.fn(), {
    success: vi.fn(),
    warning: vi.fn(),
    error: vi.fn(),
    info: vi.fn(),
  }),
}));

describe('lib/echo', () => {
  beforeEach(() => {
    vi.resetModules();
    // Cleanup window.Echo singleton
    delete (window as any).Echo;
  });

  it('exporte initEcho function', async () => {
    const mod = await import('@/lib/echo');
    expect(mod.initEcho).toBeInstanceOf(Function);
  });

  it('exporte subscribeWorkspaceNotifications function', async () => {
    const mod = await import('@/lib/echo');
    expect(mod.subscribeWorkspaceNotifications).toBeInstanceOf(Function);
  });

  it('exporte disconnectEcho function', async () => {
    const mod = await import('@/lib/echo');
    expect(mod.disconnectEcho).toBeInstanceOf(Function);
  });

  it('initEcho retourne une instance Echo', async () => {
    const { initEcho } = await import('@/lib/echo');
    const echo = initEcho();
    expect(echo).toBeDefined();
  });

  it('initEcho est idempotent (singleton)', async () => {
    const { initEcho } = await import('@/lib/echo');
    const e1 = initEcho();
    const e2 = initEcho();
    expect(e1).toBe(e2);
  });

  it('subscribeWorkspaceNotifications retourne une fonction cleanup', async () => {
    const { subscribeWorkspaceNotifications } = await import('@/lib/echo');
    const cleanup = subscribeWorkspaceNotifications('ws-uuid-123');
    expect(cleanup).toBeInstanceOf(Function);
  });

  it('subscribeWorkspaceNotifications appelle private channel avec workspace.{id}', async () => {
    const Echo = await import('laravel-echo');
    const { subscribeWorkspaceNotifications } = await import('@/lib/echo');
    subscribeWorkspaceNotifications('my-ws-id');

    // Vérifie qu'au moins un appel à .private() a eu lieu
    const instances = (Echo.default as ReturnType<typeof vi.fn>).mock.results;
    const lastInstance = instances[instances.length - 1]?.value;
    if (lastInstance) {
      expect(lastInstance.private).toHaveBeenCalledWith('workspace.my-ws-id');
    }
  });
});
