/**
 * Header sticky — Axion CRM Pro 2026
 *
 * Layout : [hamburger mobile] Breadcrumbs | [Search desktop / IconButton mobile] [Bell] [DarkMode] [UserMenu]
 *
 * Important :
 *  - `data-tour="global-search"` et `data-tour="dark-mode"` préservés (onboarding Joyride).
 *  - GlobalSearch + DarkModeToggle réutilisés tels quels.
 */
import { Menu, Bell, Search as SearchIcon } from 'lucide-react';
import { DarkModeToggle, GlobalSearch, IconButton } from '@/components/ui';
import { AutoBreadcrumbs } from './AutoBreadcrumbs';
import { UserMenu } from './UserMenu';

export interface HeaderProps {
  onOpenMobileSidebar: () => void;
  onOpenMobileSearch: () => void;
}

export function Header({ onOpenMobileSidebar, onOpenMobileSearch }: HeaderProps) {
  return (
    <header
      className="sticky top-0 z-20 flex items-center gap-2 border-b border-slate-200 bg-white/90 px-4 py-2.5 backdrop-blur md:px-6 dark:border-slate-800 dark:bg-slate-900/90"
      role="banner"
    >
      {/* Mobile : hamburger */}
      <IconButton
        label="Ouvrir le menu"
        onClick={onOpenMobileSidebar}
        variant="ghost"
        size="sm"
        className="lg:hidden"
      >
        <Menu className="h-4 w-4" />
      </IconButton>

      {/* Breadcrumbs auto */}
      <div className="min-w-0 flex-1 truncate">
        <AutoBreadcrumbs />
      </div>

      {/* Recherche desktop */}
      <div data-tour="global-search" className="hidden flex-1 max-w-md md:block">
        <GlobalSearch />
      </div>

      {/* Recherche mobile (icône) — réutilise quand même le data-tour pour le tour */}
      <div className="md:hidden" data-tour="global-search-mobile">
        <IconButton
          label="Rechercher"
          onClick={onOpenMobileSearch}
          variant="ghost"
          size="sm"
        >
          <SearchIcon className="h-4 w-4" />
        </IconButton>
      </div>

      {/* Notifications */}
      <IconButton label="Notifications" variant="ghost" size="sm">
        <Bell className="h-4 w-4" />
      </IconButton>

      {/* Dark mode */}
      <div data-tour="dark-mode">
        <DarkModeToggle />
      </div>

      {/* User menu */}
      <UserMenu />
    </header>
  );
}
