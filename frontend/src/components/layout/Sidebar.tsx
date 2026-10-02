/**
 * Sidebar 2026 — Axion CRM Pro
 *
 * Sidebar groupée style Linear/Notion. 260 px expanded / 64 px collapsed.
 * Sections groupées avec headers uppercase. NavLink avec icon lucide-react.
 *
 * Important : préserve les `data-tour` attributes pour l'onboarding Joyride
 * (data-tour="sidebar", "nav-dashboard", "nav-companies", "nav-settings").
 */
import { useRef, useState, type ReactNode } from 'react';
import { Link, useRouterState } from '@tanstack/react-router';
import {
  LayoutDashboard,
  Building2,
  Users as UsersIcon,
  Map as MapIcon,
  Activity,
  Bot,
  Network,
  RotateCw,
  ShieldCheck,
  FileText,
  ScrollText,
  UserCog,
  Settings as SettingsIcon,
  Megaphone,
  ChevronsLeft,
  ChevronRight,
  ChevronsRight,
  Lock,
  Globe,
  Hash,
  Users2,
  Newspaper,
  Mic,
  Mail,
  Scale,
  Send,
  GraduationCap,
  CalendarDays,
  Landmark,
  CopyCheck,
  ListChecks,
} from 'lucide-react';
import { cn, Tooltip } from '@/components/ui';
import { WorkspaceSelector } from './WorkspaceSelector';
import { useConsoleFeatures } from '@/features/crm-console/useConsoleFeatures';
import type { ConsoleFeatures } from '@/features/crm-console/useConsoleFeatures';

export interface NavItem {
  to: string;
  label: string;
  icon: ReactNode;
  dataTour?: string;
  locked?: boolean;
}

export interface NavSection {
  id: string;
  title: string;
  items: NavItem[];
  /** Section sans en-tête, toujours dépliée (le tableau de bord seul). */
  sansTitre?: boolean;
}

/**
 * Lot 2 UX (audit du 02/10/2026, P1-1 et P1-2) — la barre RÉORGANISÉE.
 *
 * Avant : 27 entrées, dont ONZE sous « Contacts » (contacts, entreprises,
 * presse, événements, fédérations, doublons…) et six outils de développeur
 * au premier niveau (« LLM Router », « Proxies », « Rotations »,
 * « Observabilité », « Registre AI Act », « Journaux d'audit »).
 *
 * Après : le tableau de bord seul en tête, SIX sections claires dans l'ordre
 * de la journée, puis une section « Technique » en dernier :
 *  - À traiter : ce qui attend une décision (doublons, rattachements) ;
 *  - Ma base   : entreprises, contacts, abonnés, candidats ;
 *  - Presse    : médias, journalistes, communiqués ;
 *  - Réseaux   : fédérations et ordres, événements ;
 *  - Ciblage   : audiences, listes, carte, collectes ;
 *  - Réglages  : paramètres, utilisateurs, étiquettes, demandes RGPD ;
 *  - Technique : REPLIÉE par défaut (l'accordéon ne l'ouvre que si la page
 *    courante en fait partie, ou sur un clic). Tout y est renommé en
 *    français ; les routes, elles, ne changent pas.
 *
 * Un libellé = un mot partout : le menu, le titre de la page et le fil
 * d'Ariane (`AutoBreadcrumbs.tsx`) disent la même chose — garde
 * `tests/components/navigation-cible.test.tsx`.
 *
 * Les `data-tour` de la visite guidée sont préservés (nav-dashboard,
 * nav-companies, nav-settings, nav-campaigns).
 */
const icone = (I: typeof LayoutDashboard) => <I className="h-4 w-4" />;

const SECTION_ACCUEIL: NavSection = {
  id: 'accueil',
  title: 'Accueil',
  sansTitre: true,
  items: [{ to: '/', label: 'Tableau de bord', icon: icone(LayoutDashboard), dataTour: 'nav-dashboard' }],
};

/**
 * « À traiter » et « Ma base » sont construites au RUNTIME : elles dépendent
 * du drapeau `console_v2` (le hub `/console/contacts` ou l'ancienne liste
 * `/contacts` — jamais les deux) et de l'univers « vivier » : une entrée qui
 * mène à un 403 n'a pas à exister (conception §2.2).
 */
function sectionATraiter(features: ConsoleFeatures): NavSection {
  return {
    id: 'a-traiter',
    title: 'À traiter',
    items: [
      { to: '/doublons', label: 'Doublons à vérifier', icon: icone(CopyCheck) },
      ...(features.console_v2
        ? [{ to: '/console/arbitrage', label: 'Personnes à rattacher', icon: icone(Scale) }]
        : []),
    ],
  };
}

function sectionMaBase(features: ConsoleFeatures): NavSection {
  const items: NavItem[] = [
    { to: '/companies', label: 'Entreprises', icon: icone(Building2), dataTour: 'nav-companies' },
  ];
  if (features.console_v2) {
    items.push(
      { to: '/console/contacts', label: 'Contacts', icon: icone(Users2) },
      { to: '/console/lettre-et-guide', label: 'Abonnés newsletter', icon: icone(Mail) },
    );
    if (features.universes.vivier) {
      items.push({ to: '/console/vivier', label: 'Candidats', icon: icone(GraduationCap) });
    }
  } else {
    items.push({ to: '/contacts', label: 'Contacts', icon: icone(UsersIcon) });
  }
  return { id: 'ma-base', title: 'Ma base', items };
}

const SECTIONS_FIXES: NavSection[] = [
  {
    id: 'presse',
    title: 'Presse',
    items: [
      { to: '/media', label: 'Médias', icon: icone(Newspaper) },
      { to: '/journalists', label: 'Journalistes', icon: icone(Mic) },
      { to: '/presse/envois', label: 'Communiqués envoyés', icon: icone(Send) },
    ],
  },
  {
    id: 'reseaux',
    title: 'Réseaux',
    items: [
      { to: '/federations', label: 'Fédérations et ordres', icon: icone(Landmark) },
      { to: '/evenements', label: 'Événements', icon: icone(CalendarDays) },
    ],
  },
  {
    id: 'ciblage',
    title: 'Ciblage',
    items: [
      { to: '/audiences', label: 'Audiences', icon: icone(Users2) },
      { to: '/listes', label: 'Listes', icon: icone(ListChecks) },
      { to: '/coverage', label: 'Carte de France', icon: icone(MapIcon) },
      { to: '/campaigns', label: 'Collectes', icon: icone(Megaphone), dataTour: 'nav-campaigns' },
    ],
  },
  {
    id: 'reglages',
    title: 'Réglages',
    items: [
      { to: '/settings', label: 'Paramètres', icon: icone(SettingsIcon), dataTour: 'nav-settings' },
      { to: '/users', label: 'Utilisateurs', icon: icone(UserCog) },
      { to: '/tags', label: 'Étiquettes', icon: icone(Hash) },
      { to: '/rgpd/requests', label: 'Demandes RGPD', icon: icone(ShieldCheck) },
    ],
  },
  {
    id: 'technique',
    title: 'Technique',
    items: [
      { to: '/scraper-runs', label: 'Historique des collectes', icon: icone(Activity) },
      { to: '/admin/observability', label: 'Santé du système', icon: icone(Activity) },
      { to: '/llm/router', label: 'Moteurs d’IA', icon: icone(Bot) },
      { to: '/llm/proxy-providers', label: 'Serveurs relais', icon: icone(Network) },
      { to: '/llm/rotations', label: 'Rotation des accès', icon: icone(RotateCw) },
      { to: '/rgpd/ai-act', label: 'Registre de l’IA', icon: icone(FileText) },
      { to: '/audit-logs', label: 'Journal des actions', icon: icone(ScrollText) },
      { to: '/international/roumanie', label: 'Roumanie', icon: icone(Globe) },
    ],
  },
];

/** Identifiant de la section repliée par défaut — lu par la garde. */
export const SECTION_TECHNIQUE = 'technique';

/** L'arborescence complète du menu, pour l'écran et pour les gardes. */
export function sectionsDeNavigation(features: ConsoleFeatures): NavSection[] {
  return [SECTION_ACCUEIL, sectionATraiter(features), sectionMaBase(features), ...SECTIONS_FIXES];
}

export interface SidebarProps {
  collapsed: boolean;
  onToggleCollapse: () => void;
  /**
   * D30-005 — la barre prend la largeur de son conteneur au lieu de ses 260 px.
   *
   * Rendue DANS le tiroir mobile, la largeur fixe laissait une bande morte :
   * mesure du 2026-08-22, 375 px de téléphone moins 260 px de barre = 115 px de
   * vide qui n'était ni la barre ni le voile — on y tapotait sans effet. Le
   * drapeau reste optionnel : la colonne de bureau garde ses 260 px, qui sont
   * une largeur de gabarit et non un accident.
   */
  pleineLargeur?: boolean;
}

export function Sidebar({ collapsed, onToggleCollapse, pleineLargeur = false }: SidebarProps) {
  const router = useRouterState({ select: (s) => s.location.pathname });
  const features = useConsoleFeatures();
  const sections = sectionsDeNavigation(features);

  // UNE seule section ouverte à la fois : ouvrir la suivante referme la
  // précédente. Sur neuf sections (avant l'étape 0) dépliées en permanence, la navigation
  // devenait un mur de liens où plus rien ne se distinguait.
  //
  // L'état de départ n'est PAS arbitraire : on ouvre la section qui contient
  // la page courante. Arriver sur un écran dont l'entrée de menu est repliée
  // donnerait l'impression d'avoir quitté l'application.
  const sectionDeLaPage = sections.find((s) =>
    s.items.some((i) => (i.to === '/' ? router === '/' : router === i.to || router.startsWith(`${i.to}/`))),
  );
  const [sectionOuverte, setSectionOuverte] = useState<string | null>(sectionDeLaPage?.id ?? sections[0]?.id ?? null);

  // La navigation peut aussi venir d'ailleurs (recherche globale, lien
  // interne, retour arrière) : la section suit alors la page, sinon le menu
  // dirait le contraire de l'écran.
  const derniereSectionSuivie = useRef<string | null>(sectionDeLaPage?.id ?? null);
  if (sectionDeLaPage !== undefined && derniereSectionSuivie.current !== sectionDeLaPage.id) {
    derniereSectionSuivie.current = sectionDeLaPage.id;
    if (sectionOuverte !== sectionDeLaPage.id) {
      setSectionOuverte(sectionDeLaPage.id);
    }
  }

  return (
    <aside
      data-tour="sidebar"
      className={cn(
        'flex h-screen shrink-0 flex-col border-r border-sidebar-border bg-sidebar transition-[width] duration-200 ease-out',
        collapsed ? 'w-16' : pleineLargeur ? 'w-full' : 'w-[260px]',
      )}
      aria-label="Navigation latérale"
    >
      {/* Logo + workspace */}
      <div className={cn('flex flex-col gap-2 border-b border-sidebar-border px-3 py-4', collapsed && 'items-center px-2')}>
        {collapsed ? (
          <Link
            to="/"
            className="flex h-9 w-9 items-center justify-center rounded-lg bg-gradient-to-br from-brand-600 to-brand-700 text-sm font-bold text-white shadow-sm"
            aria-label="Axion CRM Pro — accueil"
          >
            A
          </Link>
        ) : (
          <>
            <Link to="/" className="flex items-center gap-2 px-1 py-0.5">
              <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-brand-600 to-brand-700 text-sm font-bold text-white shadow-sm">
                A
              </span>
              <span className="text-sm font-bold tracking-tight text-white">Axion CRM Pro</span>
            </Link>
            <WorkspaceSelector />
          </>
        )}
      </div>

      {/* Navigation groups */}
      <nav className="flex-1 overflow-y-auto px-2 py-3" aria-label="Navigation principale">
        {sections.map((section) => (
          <NavSectionBlock
            key={section.id}
            section={section}
            collapsed={collapsed}
            currentPath={router}
            ouverte={sectionOuverte === section.id}
            onBasculer={() =>
              setSectionOuverte((actuelle) => (actuelle === section.id ? null : section.id))
            }
          />
        ))}
      </nav>

      {/* Collapse toggle */}
      <div className="border-t border-sidebar-border p-2">
        <button
          type="button"
          onClick={onToggleCollapse}
          className={cn(
            'inline-flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-xs font-medium text-sidebar-fg-muted transition',
            'hover:bg-white/10 hover:text-white',
            collapsed && 'justify-center',
          )}
          aria-label={collapsed ? 'Étendre la barre latérale' : 'Réduire la barre latérale'}
          title={collapsed ? 'Étendre' : 'Réduire'}
        >
          {collapsed ? <ChevronsRight className="h-4 w-4" /> : <ChevronsLeft className="h-4 w-4" />}
          {!collapsed && <span>Réduire</span>}
        </button>
      </div>
    </aside>
  );
}

function NavSectionBlock({
  section,
  collapsed,
  currentPath,
  ouverte,
  onBasculer,
}: {
  section: NavSection;
  collapsed: boolean;
  currentPath: string;
  ouverte: boolean;
  onBasculer: () => void;
}) {
  // Barre réduite : il n'y a plus de titre sur lequel cliquer, et masquer les
  // icônes ne laisserait rien du tout. On affiche donc tout — l'accordéon n'a
  // de sens que quand les libellés sont là.
  const deplie = collapsed || ouverte || section.sansTitre === true;
  const idListe = `nav-section-${section.id}`;

  return (
    <div className="mb-3 last:mb-0">
      {/*
        D28-012 — ce titre de groupe était un `<h3>`. Six groupes, donc six
        `<h3>` émis AVANT le `<h1>` de la page (`PageHeader.tsx`) : le plan de
        titres du produit commençait par un niveau 3 et la navigation par titres
        rendait la hiérarchie inintelligible.
        On ne se contente pas de retirer les `<h3>` — cela supprimerait six
        points d'ancrage à qui s'en servait. Chaque liste devient un REPÈRE DE
        RÉGION nommé (`<nav aria-label>`) : l'ancrage change de nature, il ne
        disparaît pas.
        `aria-label` plutôt que `aria-labelledby` : barre réduite, le bouton
        porteur du titre n'est pas rendu du tout (`!collapsed` ci-dessous) et un
        `aria-labelledby` pointerait alors vers un identifiant inexistant — une
        région sans nom.
      */}
      {!collapsed && section.sansTitre !== true && (
        <div className="mb-1">
          <button
            type="button"
            onClick={onBasculer}
            aria-expanded={ouverte}
            aria-controls={idListe}
            className={cn(
              'flex w-full items-center gap-1 rounded-md px-2 py-1 text-[13px] font-semibold uppercase tracking-wide transition',
              'text-sidebar-fg-muted hover:bg-white/10 hover:text-white',
            )}
          >
            <ChevronRight
              aria-hidden="true"
              className={cn('h-3 w-3 shrink-0 transition-transform duration-150', ouverte && 'rotate-90')}
            />
            <span className="flex-1 truncate text-left">{section.title}</span>
          </button>
        </div>
      )}
      <nav aria-label={section.title}>
        <ul id={idListe} className={cn('flex flex-col gap-0.5', !deplie && 'hidden')}>
          {section.items.map((item) => (
            <li key={item.to}>
              <SidebarNavLink item={item} collapsed={collapsed} currentPath={currentPath} />
            </li>
          ))}
        </ul>
      </nav>
    </div>
  );
}

function SidebarNavLink({
  item,
  collapsed,
  currentPath,
}: {
  item: NavItem;
  collapsed: boolean;
  currentPath: string;
}) {
  // Active = exact match for '/', startsWith for others
  const active = item.to === '/' ? currentPath === '/' : currentPath === item.to || currentPath.startsWith(`${item.to}/`);

  const link = (
    <Link
      to={item.to}
      {...(item.dataTour ? { 'data-tour': item.dataTour } : {})}
      className={cn(
        'group flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm font-medium transition',
        active
          ? 'bg-sidebar-active text-white ring-1 ring-white/15'
          : 'text-sidebar-fg hover:bg-white/10 hover:text-white',
        item.locked && 'opacity-60',
        collapsed && 'justify-center px-2',
      )}
      aria-current={active ? 'page' : undefined}
    >
      <span className={cn('shrink-0', active ? 'text-brand-300' : 'text-sidebar-fg-muted')}>
        {item.icon}
      </span>
      {!collapsed && (
        <>
          <span className="flex-1 truncate">{item.label}</span>
          {item.locked && (
            <Lock className="h-3 w-3 shrink-0 text-sidebar-fg-muted" aria-label="Bientôt disponible" />
          )}
        </>
      )}
    </Link>
  );

  if (collapsed) {
    return (
      <Tooltip content={item.locked ? `${item.label} (bientôt)` : item.label} side="right">
        {link}
      </Tooltip>
    );
  }
  if (item.locked) {
    return (
      <Tooltip content="Bientôt disponible" side="right">
        {link}
      </Tooltip>
    );
  }
  return link;
}
