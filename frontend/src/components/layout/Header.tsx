/**
 * Header sticky — Axion CRM Pro 2026
 *
 * Layout : [hamburger mobile] Breadcrumbs | [Search desktop / IconButton mobile] [Bell] [DarkMode] [UserMenu]
 *
 * Important :
 *  - `data-tour="global-search"` et `data-tour="dark-mode"` préservés (onboarding Joyride).
 *  - GlobalSearch + DarkModeToggle réutilisés tels quels.
 */
import { Menu, Search as SearchIcon } from 'lucide-react';
import { DarkModeToggle, GlobalSearch, IconButton } from '@/components/ui';
import { AutoBreadcrumbs } from './AutoBreadcrumbs';
import { NotificationsBell } from './NotificationsBell';
import { UserMenu } from './UserMenu';

export interface HeaderProps {
  onOpenMobileSidebar: () => void;
  onOpenMobileSearch: () => void;
}

export function Header({ onOpenMobileSidebar, onOpenMobileSearch }: HeaderProps) {
  return (
    <header
      // Bleu profond, comme la barre latérale : navigation et repères se lisent
      // d'un bloc, le contenu reste la seule zone claire de l'écran.
      className="sticky top-0 z-20 flex items-center gap-2 border-b border-sidebar-border bg-sidebar px-4 py-2.5 md:px-6"
      role="banner"
    >
      {/* Mobile : hamburger */}
      <IconButton
        label="Ouvrir le menu"
        onClick={onOpenMobileSidebar}
        variant="ghost"
        size="sm"
        className="lg:hidden text-sidebar-fg hover:bg-white/10 hover:text-white"
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
          className="text-sidebar-fg hover:bg-white/10 hover:text-white"
        >
          <SearchIcon className="h-4 w-4" />
        </IconButton>
      </div>

      {/*
        Notifications — D24-002.
        Cet emplacement portait un `IconButton` SANS `onClick` : le clic
        reussissait et rien ne s'ouvrait, alors que `GET /notifications` etait
        deja reel cote serveur. Tout le comportement (panneau, compteur de
        non-lues, etats d'echec, aveu sur le marquage non implemente) vit
        desormais dans `NotificationsBell`.
      */}
      <NotificationsBell />

      {/* Dark mode */}
      <div data-tour="dark-mode">
        <DarkModeToggle />
      </div>

      {/* User menu */}
      <UserMenu />
    </header>
  );
}
