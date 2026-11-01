/**
 * Header sticky — Axion CRM Pro 2026
 *
 * Layout : [hamburger mobile] Breadcrumbs | [Search desktop / IconButton mobile] [Bell] [UserMenu]
 *
 * Important :
 *  - `data-tour="global-search"` préservé (onboarding Joyride).
 *  - GlobalSearch réutilisé tel quel. Plus de sélecteur de thème (lot 2 UX).
 */
import { Menu, Search as SearchIcon } from 'lucide-react';
import { GlobalSearch, IconButton } from '@/components/ui';
import { AutoBreadcrumbs } from './AutoBreadcrumbs';
import { NotificationsBell } from './NotificationsBell';
import { UserMenu } from './UserMenu';
import { useProduitCourant } from '@/features/socle/produit/useProduitCourant';
import { useProduitsVisibles } from '@/features/socle/produits/useProduits';
import { FeuDesEnvois } from '@/features/socle/EtatEnvois';
import { ToutArreter, TOUT_ARRETER_AUSSI_DANS_LE_CRM } from '@/features/socle/ToutArreter';

/**
 * DM0-COQ — « Tout arrêter » et le feu des envois : dans l'espace Envoi
 * (maquettes validées), et dans le CRM seulement si Will le demande.
 */
export function montrerToutArreter(produit: string, envoiVisible: boolean): boolean {
  if (!envoiVisible) return false;
  return produit === 'envoi' || TOUT_ARRETER_AUSSI_DANS_LE_CRM;
}

export interface HeaderProps {
  onOpenMobileSidebar: () => void;
  onOpenMobileSearch: () => void;
}

export function Header({ onOpenMobileSidebar, onOpenMobileSearch }: HeaderProps) {
  // DM0-COQ — sans Envoi (production d'Axion aujourd'hui), rien de ce qui suit
  // n'est rendu : l'en-tête est EXACTEMENT celui d'avant (empreinte HTML
  // gardée par `tests/components/routes-envoi-gardees.test.tsx`).
  const produit = useProduitCourant();
  const visibles = useProduitsVisibles();
  const arret = montrerToutArreter(produit, visibles.envoi);
  return (
    <header
      // Bleu profond, comme la barre latérale : navigation et repères se lisent
      // d'un bloc, le contenu reste la seule zone claire de l'écran.
      className="sticky top-0 z-20 flex items-center gap-2 border-b border-sidebar-border bg-sidebar px-4 py-2.5 md:px-6"
      role="banner"
    >
      {/* DM0-COQ (ADR-13 § 3) — bande de 4 px de la couleur du produit, posée
          seulement quand Envoi existe. */}
      {visibles.envoi ? (
        <span aria-hidden data-bande-produit className="pointer-events-none absolute inset-x-0 top-0 h-1 bg-produit-bandeau" />
      ) : null}
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
      {arret ? (
        <>
          <FeuDesEnvois />
          <ToutArreter />
        </>
      ) : null}

      <NotificationsBell />

      {/* Lot 2 UX — le sélecteur clair/sombre est RETIRÉ : pas de mode sombre
          sur la console (décision permanente). Voir `src/lib/theme.ts`. */}

      {/* User menu */}
      <UserMenu />
    </header>
  );
}
