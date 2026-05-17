/**
 * Sidebar 2026 — Axion CRM Pro
 *
 * Sidebar groupée style Linear/Notion. 260 px expanded / 64 px collapsed.
 * Sections groupées avec headers uppercase. NavLink avec icon lucide-react.
 *
 * Important : préserve les `data-tour` attributes pour l'onboarding Joyride
 * (data-tour="sidebar", "nav-dashboard", "nav-companies", "nav-settings").
 */
import type { ReactNode } from 'react';
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
  Mail,
  Linkedin,
  KanbanSquare,
  BarChart3,
  ChevronsLeft,
  ChevronsRight,
  Lock,
  Hash,
  Users2,
  Send,
} from 'lucide-react';
import { cn, Tooltip } from '@/components/ui';
import { WorkspaceSelector } from './WorkspaceSelector';

interface NavItem {
  to: string;
  label: string;
  icon: ReactNode;
  dataTour?: string;
  locked?: boolean;
}

interface NavSection {
  id: string;
  title: string;
  items: NavItem[];
}

const SECTIONS: NavSection[] = [
  {
    id: 'pilotage',
    title: 'Pilotage',
    items: [
      { to: '/', label: 'Tableau de bord', icon: <LayoutDashboard className="h-4 w-4" />, dataTour: 'nav-dashboard' },
      { to: '/coverage', label: 'Couverture France', icon: <MapIcon className="h-4 w-4" /> },
      { to: '/campaigns', label: 'Campagnes', icon: <Megaphone className="h-4 w-4" />, dataTour: 'nav-campaigns' },
      { to: '/scraper-runs', label: 'Runs de scraping', icon: <Activity className="h-4 w-4" /> },
    ],
  },
  {
    id: 'data',
    title: 'Data',
    items: [
      { to: '/companies', label: 'Entreprises', icon: <Building2 className="h-4 w-4" />, dataTour: 'nav-companies' },
      { to: '/contacts', label: 'Contacts', icon: <UsersIcon className="h-4 w-4" /> },
      { to: '/tags', label: 'Tags', icon: <Hash className="h-4 w-4" /> },
    ],
  },
  {
    id: 'communication',
    title: 'Communication',
    items: [
      { to: '/audiences', label: 'Audiences', icon: <Users2 className="h-4 w-4" /> },
      { to: '/email-templates', label: 'Templates email', icon: <Mail className="h-4 w-4" />, locked: true },
      { to: '/email-sends', label: 'Envois email', icon: <Send className="h-4 w-4" />, locked: true },
    ],
  },
  {
    id: 'ia',
    title: 'IA',
    items: [
      { to: '/llm/router', label: 'LLM Router', icon: <Bot className="h-4 w-4" /> },
      { to: '/llm/proxy-providers', label: 'Proxies', icon: <Network className="h-4 w-4" /> },
      { to: '/llm/rotations', label: 'Rotations', icon: <RotateCw className="h-4 w-4" /> },
    ],
  },
  {
    id: 'conformite',
    title: 'Conformité',
    items: [
      { to: '/rgpd/requests', label: 'Requêtes RGPD', icon: <ShieldCheck className="h-4 w-4" /> },
      { to: '/rgpd/ai-act', label: 'Registre AI Act', icon: <FileText className="h-4 w-4" /> },
      { to: '/audit-logs', label: 'Journaux d’audit', icon: <ScrollText className="h-4 w-4" /> },
    ],
  },
  {
    id: 'admin',
    title: 'Admin',
    items: [
      { to: '/users', label: 'Utilisateurs', icon: <UserCog className="h-4 w-4" /> },
      { to: '/settings', label: 'Paramètres', icon: <SettingsIcon className="h-4 w-4" />, dataTour: 'nav-settings' },
    ],
  },
  {
    id: 'phase2',
    title: 'Phase 2',
    items: [
      { to: '/cold-email', label: 'E-mails à froid', icon: <Mail className="h-4 w-4" />, locked: true },
      { to: '/linkedin', label: 'Prospection LinkedIn', icon: <Linkedin className="h-4 w-4" />, locked: true },
      { to: '/crm', label: 'Pipeline CRM', icon: <KanbanSquare className="h-4 w-4" />, locked: true },
      { to: '/analytics', label: 'Analytique', icon: <BarChart3 className="h-4 w-4" />, locked: true },
    ],
  },
];

export interface SidebarProps {
  collapsed: boolean;
  onToggleCollapse: () => void;
}

export function Sidebar({ collapsed, onToggleCollapse }: SidebarProps) {
  const router = useRouterState({ select: (s) => s.location.pathname });

  return (
    <aside
      data-tour="sidebar"
      className={cn(
        'flex h-screen shrink-0 flex-col border-r border-slate-200 bg-white transition-[width] duration-200 ease-out',
        'dark:border-slate-800 dark:bg-slate-900',
        collapsed ? 'w-16' : 'w-[260px]',
      )}
      aria-label="Navigation latérale"
    >
      {/* Logo + workspace */}
      <div className={cn('flex flex-col gap-2 border-b border-slate-100 px-3 py-4 dark:border-slate-800', collapsed && 'items-center px-2')}>
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
              <span className="text-sm font-bold tracking-tight text-slate-900 dark:text-white">Axion CRM Pro</span>
            </Link>
            <WorkspaceSelector />
          </>
        )}
      </div>

      {/* Navigation groups */}
      <nav className="flex-1 overflow-y-auto px-2 py-3" aria-label="Navigation principale">
        {SECTIONS.map((section) => (
          <NavSectionBlock key={section.id} section={section} collapsed={collapsed} currentPath={router} />
        ))}
      </nav>

      {/* Collapse toggle */}
      <div className="border-t border-slate-100 p-2 dark:border-slate-800">
        <button
          type="button"
          onClick={onToggleCollapse}
          className={cn(
            'inline-flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-xs font-medium text-slate-500 transition',
            'hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white',
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
}: {
  section: NavSection;
  collapsed: boolean;
  currentPath: string;
}) {
  return (
    <div className="mb-3 last:mb-0">
      {!collapsed && (
        <h3 className="mb-1 px-2 text-[10px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">
          {section.title}
        </h3>
      )}
      <ul className="flex flex-col gap-0.5">
        {section.items.map((item) => (
          <li key={item.to}>
            <SidebarNavLink item={item} collapsed={collapsed} currentPath={currentPath} />
          </li>
        ))}
      </ul>
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
          ? 'bg-slate-100 text-slate-900 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-white dark:ring-slate-700'
          : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800/60 dark:hover:text-white',
        item.locked && 'opacity-60',
        collapsed && 'justify-center px-2',
      )}
      aria-current={active ? 'page' : undefined}
    >
      <span className={cn('shrink-0', active ? 'text-brand-600 dark:text-brand-400' : 'text-slate-400 dark:text-slate-500')}>
        {item.icon}
      </span>
      {!collapsed && (
        <>
          <span className="flex-1 truncate">{item.label}</span>
          {item.locked && (
            <Lock className="h-3 w-3 shrink-0 text-slate-400 dark:text-slate-500" aria-label="Bientôt disponible" />
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
