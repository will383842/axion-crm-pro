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
import { useEffect, useRef, useState } from 'react';
import { Outlet, useRouterState } from '@tanstack/react-router';
import { useQuery } from '@tanstack/react-query';
import { Drawer, GlobalSearch, Modal } from '@/components/ui';
import { OnboardingTour } from '@/components/OnboardingTour';
import { Sidebar } from '@/components/layout/Sidebar';
import { Header } from '@/components/layout/Header';
import { libelleDeChemin } from '@/components/layout/AutoBreadcrumbs';
import { RouteErrorBoundary } from './RouteErrorBoundary';
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

  // D30-005 — le tiroir ne se refermait APRÈS AUCUNE navigation : les deux
  // seuls `setMobileSidebarOpen(false)` étaient la croix du tiroir et le bouton
  // de repli. Toucher une entrée de menu changeait donc d'écran… sous un tiroir
  // resté ouvert, qu'il fallait ensuite refermer à la main : quatre appuis là où
  // le bureau demande deux clics. On referme sur le chemin, et non dans le
  // gestionnaire de clic des entrées : la navigation vient aussi de la
  // recherche globale, d'un lien interne ou du retour arrière.
  const cheminCourant = useRouterState({ select: (s) => s.location.pathname });
  useEffect(() => {
    setMobileSidebarOpen(false);
  }, [cheminCourant]);

  // D28-014 — le changement d'écran ne s'ANNONÇAIT nulle part : mesure du
  // 2026-08-22, `grep -rn aria-live src --include=*.tsx` ne rendait aucune
  // ligne. Dans une application d'une seule page, changer d'écran ne recharge
  // rien : qui ne voit pas la page n'apprend jamais qu'il a changé d'endroit.
  //
  // Trois précautions contre le bavardage, qui est le risque réel d'une telle
  // région :
  //  - `polite` et jamais `assertive` : l'annonce attend la fin de la lecture
  //    en cours au lieu de la couper ;
  //  - le message n'est écrit que quand le CHEMIN change, pas à chaque rendu ;
  //  - le premier rendu ne dit rien — l'arrivée sur la page est déjà annoncée
  //    par le navigateur, la redire ferait doublon.
  const [annonceDeRoute, setAnnonceDeRoute] = useState('');
  const premierRendu = useRef(true);
  useEffect(() => {
    if (premierRendu.current) {
      premierRendu.current = false;
      return;
    }
    setAnnonceDeRoute(`${libelleDeChemin(cheminCourant)}, page chargée`);
  }, [cheminCourant]);

  return (
    <div className="flex min-h-screen bg-gradient-to-br from-slate-50 via-white to-slate-50 dark:from-slate-950 dark:via-slate-950 dark:to-slate-900">
      <a href="#main" className="skip-link">Aller au contenu</a>

      {/* D28-014 — la seule région d'annonce du changement d'écran. Invisible à
          l'œil (`sr-only`), elle n'existe que pour les lecteurs d'écran. */}
      <div role="status" aria-live="polite" className="sr-only" data-annonce-de-route>
        {annonceDeRoute}
      </div>

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
          {/* D30-005 — `pleineLargeur` : dans le tiroir, la barre suit la
              largeur du panneau. Ses 260 px fixes laissaient 115 px de bande
              morte sur un téléphone de 375 px. */}
          <Sidebar collapsed={false} onToggleCollapse={() => setMobileSidebarOpen(false)} pleineLargeur />
        </div>
      </Drawer>

      {/* Main column */}
      <div className="flex min-w-0 flex-1 flex-col">
        <Header
          onOpenMobileSidebar={() => setMobileSidebarOpen(true)}
          onOpenMobileSearch={() => setMobileSearchOpen(true)}
        />

        {/*
          D30-001 — `overflow-x-hidden` ici COUPAIT le débordement horizontal
          sans offrir ni barre ni geste : à 375 px, tout ce qui dépassait la
          largeur visible était simplement perdu, sans aucun moyen d'y accéder.
          `overflow-x-auto` ne montre une barre que s'il y a réellement quelque
          chose à atteindre — c'est la seule différence, et elle rend le contenu
          joignable au lieu de le supprimer.

          ⚠️ Ce changement est un RÉVÉLATEUR : un débordement d'un pixel qui
          passait inaperçu fera désormais apparaître une barre. Ce n'est pas une
          régression, c'est le débordement qui était déjà là. `TableScroll`
          (`components/ui/TableScroll.tsx`) reste le remède ciblé des tableaux
          en grille ; cette ligne couvre tout le reste.
        */}
        <main id="main" className="flex-1 overflow-x-auto px-4 py-5 md:px-6 md:py-6 lg:px-10">
          {/*
            P6-UI-005 — SITE DE MONTAGE 2/3. La frontiere est posee A
            L'INTERIEUR de `#main`, et non autour de toute la coquille : c'est
            ce qui fait la difference entre « un ecran est tombe » et
            « l'application est morte ». La barre laterale et l'en-tete restent
            rendus, l'utilisateur clique ailleurs et repart.

            Mesure : sans elle, le filet global de TanStack Router
            (`Matches.js:36`) remplace TOUT l'arbre de matches — `#main`
            disparait avec le reste, plus aucune navigation n'est offerte.
          */}
          <RouteErrorBoundary level="page">
            <Outlet />
          </RouteErrorBoundary>
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
