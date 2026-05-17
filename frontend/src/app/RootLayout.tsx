/**
 * RootLayout 2026 — Axion CRM Pro
 *
 * Coquille de TOUTES les pages admin. Style Linear/Notion 2026.
 *
 *  - Sidebar 260 px (collapse 64 px) avec sections groupées + workspace selector.
 *  - Header sticky : breadcrumbs auto + search + notifications + dark mode + user menu.
 *  - Mobile : sidebar passe en Drawer, search devient IconButton + Modal.
 *  - OnboardingTour préservé (data-tour="sidebar", "nav-dashboard", "nav-companies",
 *    "nav-settings", "global-search", "dark-mode").
 *
 * Sous-composants dans `@/components/layout/`.
 */
import { useEffect, useState } from 'react';
import { Outlet } from '@tanstack/react-router';
import { useQuery } from '@tanstack/react-query';
import { Drawer, GlobalSearch, Modal } from '@/components/ui';
import { OnboardingTour } from '@/components/OnboardingTour';
import { Sidebar } from '@/components/layout/Sidebar';
import { Header } from '@/components/layout/Header';
import { api } from '@/lib/api';
import { subscribeWorkspaceNotifications } from '@/lib/echo';

interface MeResponse {
  user: { id: string; current_workspace_id: string | null };
}

const SIDEBAR_COLLAPSED_KEY = 'axion-crm:sidebar:collapsed';

export function RootLayout() {
  const { data: me } = useQuery<MeResponse>({
    queryKey: ['auth', 'me'],
    queryFn: async () => (await api.get<MeResponse>('/auth/me')).data,
    retry: false,
    staleTime: 5 * 60 * 1000,
  });

  // Realtime notifications channel
  useEffect(() => {
    if (!me?.user?.current_workspace_id) return;
    if (import.meta.env['VITE_ECHO_DISABLED'] === 'true') return;
    const cleanup = subscribeWorkspaceNotifications(me.user.current_workspace_id);
    return cleanup;
  }, [me?.user?.current_workspace_id]);

  // Sidebar collapsed state — persisté en localStorage
  const [collapsed, setCollapsed] = useState<boolean>(() => {
    if (typeof window === 'undefined') return false;
    try {
      return window.localStorage.getItem(SIDEBAR_COLLAPSED_KEY) === '1';
    } catch {
      return false;
    }
  });

  const toggleCollapsed = () => {
    setCollapsed((prev) => {
      const next = !prev;
      try {
        window.localStorage.setItem(SIDEBAR_COLLAPSED_KEY, next ? '1' : '0');
      } catch {
        /* ignore */
      }
      return next;
    });
  };

  // Mobile drawer state
  const [mobileSidebarOpen, setMobileSidebarOpen] = useState(false);
  const [mobileSearchOpen, setMobileSearchOpen] = useState(false);

  return (
    <div className="flex min-h-screen bg-gradient-to-br from-slate-50 via-white to-slate-50 dark:from-slate-950 dark:via-slate-950 dark:to-slate-900">
      <a href="#main" className="skip-link">Aller au contenu</a>

      {/* Desktop sidebar */}
      <div className="sticky top-0 hidden h-screen lg:flex">
        <Sidebar collapsed={collapsed} onToggleCollapse={toggleCollapsed} />
      </div>

      {/* Mobile sidebar Drawer */}
      <Drawer
        open={mobileSidebarOpen}
        onClose={() => setMobileSidebarOpen(false)}
        title="Navigation"
        width="sm"
      >
        <div className="-mx-6 -my-4">
          <Sidebar collapsed={false} onToggleCollapse={() => setMobileSidebarOpen(false)} />
        </div>
      </Drawer>

      {/* Main column */}
      <div className="flex min-w-0 flex-1 flex-col">
        <Header
          onOpenMobileSidebar={() => setMobileSidebarOpen(true)}
          onOpenMobileSearch={() => setMobileSearchOpen(true)}
        />

        <main id="main" className="flex-1 overflow-x-hidden px-4 py-5 md:px-6 md:py-6 lg:px-10">
          <Outlet />
        </main>
      </div>

      {/* Mobile search modal */}
      <Modal
        open={mobileSearchOpen}
        onClose={() => setMobileSearchOpen(false)}
        title="Recherche"
        size="md"
      >
        <GlobalSearch />
      </Modal>

      <OnboardingTour />
    </div>
  );
}
