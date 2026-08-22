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
  Scale,
  GraduationCap,
} from 'lucide-react';
import { cn, Tooltip } from '@/components/ui';
import { WorkspaceSelector } from './WorkspaceSelector';
import { useConsoleFeatures } from '@/features/crm-console/useConsoleFeatures';
import type { ConsoleFeatures } from '@/features/crm-console/useConsoleFeatures';

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

/**
 * Étape 0, ligne 3 bis (F17) — la barre RANGÉE, avant d'y ajouter le moindre
 * écran du chantier « CRM cible » (cahier des charges §23.3).
 *
 * Ce qui a changé et pourquoi :
 *  - neuf sections → six, dans l'ordre de la journée : Aujourd'hui · Contacts ·
 *    Collecte · Pilotage · Conformité · Réglages. « Conformité » reste un
 *    groupe à part tant que « Réglages » n'a pas de sous-groupes (cible :
 *    « Données et conformité », étape 2) ;
 *  - une SEULE entrée « Contacts » : le hub `/console/contacts` quand la
 *    console v2 est ouverte, l'ancienne liste `/contacts` sinon — jamais les
 *    deux (voir `sectionContacts`) ;
 *  - « Campagnes » → « Collectes », « Runs de scraping » → « Journaux de
 *    collecte » : le mot « campagne » est réservé aux e-mails à venir (L7),
 *    la collision aurait été garantie ;
 *  - plus AUCUNE entrée verrouillée (🔒) : « Templates email », « Envois
 *    email », « E-mails à froid », « Prospection LinkedIn » menaient à un
 *    cadenas ou à un bouchon 501. Un menu ne promet pas ce qui n'existe pas ;
 *    les routes `/cold-email` et `/linkedin` restent joignables par URL ;
 *  - l'outillage de collecte (LLM router, proxies, rotations, Roumanie) quitte
 *    le premier niveau ; « Data » devient « Contacts » ; « Audiences »
 *    (constructeur de segments) descend sous Pilotage.
 *
 * Les `data-tour` de la visite guidée sont préservés (nav-dashboard,
 * nav-companies, nav-settings, nav-campaigns) — la visite est refaite une fois
 * sur cette barre (OnboardingTour.tsx).
 */
const SECTIONS_APRES_CONTACTS: NavSection[] = [
  {
    id: 'collecte',
    title: 'Collecte',
    items: [
      { to: '/coverage', label: 'Couverture France', icon: <MapIcon className="h-4 w-4" /> },
      { to: '/campaigns', label: 'Collectes', icon: <Megaphone className="h-4 w-4" />, dataTour: 'nav-campaigns' },
      { to: '/scraper-runs', label: 'Journaux de collecte', icon: <Activity className="h-4 w-4" /> },
      { to: '/international/roumanie', label: 'Roumanie', icon: <Globe className="h-4 w-4" /> },
    ],
  },
  {
    id: 'pilotage',
    title: 'Pilotage',
    items: [
      { to: '/audiences', label: 'Audiences (segments)', icon: <Users2 className="h-4 w-4" /> },
      { to: '/admin/observability', label: 'Observabilité', icon: <Activity className="h-4 w-4" /> },
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
    id: 'reglages',
    title: 'Réglages',
    items: [
      { to: '/users', label: 'Utilisateurs', icon: <UserCog className="h-4 w-4" /> },
      { to: '/settings', label: 'Paramètres', icon: <SettingsIcon className="h-4 w-4" />, dataTour: 'nav-settings' },
      { to: '/tags', label: 'Tags', icon: <Hash className="h-4 w-4" /> },
      { to: '/llm/router', label: 'LLM Router', icon: <Bot className="h-4 w-4" /> },
      { to: '/llm/proxy-providers', label: 'Proxies', icon: <Network className="h-4 w-4" /> },
      { to: '/llm/rotations', label: 'Rotations', icon: <RotateCw className="h-4 w-4" /> },
    ],
  },
];

const SECTION_AUJOURDHUI: NavSection = {
  id: 'aujourdhui',
  title: "Aujourd'hui",
  items: [
    { to: '/', label: 'Tableau de bord', icon: <LayoutDashboard className="h-4 w-4" />, dataTour: 'nav-dashboard' },
  ],
};

/**
 * Section « Contacts » — construite au RUNTIME, comme l'ancienne « Console CRM ».
 *
 * Une seule entrée « Contacts » : le hub de la console v2 si l'API annonce le
 * drapeau ouvert, l'ancienne liste `/contacts` sinon. « Vivier candidats »
 * n'apparaît que si l'utilisateur est membre de cet univers : une entrée de
 * navigation qui mène à un 403 n'a pas à exister — l'étanchéité se LIT dans la
 * navigation, elle ne se découvre pas au clic (conception §2.2).
 */
function sectionContacts(features: ConsoleFeatures): NavSection {
  const items: NavItem[] = features.console_v2
    ? [
        { to: '/console/contacts', label: 'Contacts', icon: <Users2 className="h-4 w-4" /> },
        ...(features.universes.vivier
          ? [{ to: '/console/vivier', label: 'Vivier candidats', icon: <GraduationCap className="h-4 w-4" /> }]
          : []),
        { to: '/console/arbitrage', label: 'À arbitrer', icon: <Scale className="h-4 w-4" /> },
      ]
    : [{ to: '/contacts', label: 'Contacts', icon: <UsersIcon className="h-4 w-4" /> }];

  items.push(
    { to: '/companies', label: 'Entreprises', icon: <Building2 className="h-4 w-4" />, dataTour: 'nav-companies' },
    { to: '/journalists', label: 'Journalistes', icon: <Mic className="h-4 w-4" /> },
    { to: '/media', label: 'Médias (presse)', icon: <Newspaper className="h-4 w-4" /> },
  );

  return { id: 'contacts', title: 'Contacts', items };
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
  const sections = [SECTION_AUJOURDHUI, sectionContacts(features), ...SECTIONS_APRES_CONTACTS];

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
  const deplie = collapsed || ouverte;
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
      {!collapsed && (
        <div className="mb-1">
          <button
            type="button"
            onClick={onBasculer}
            aria-expanded={ouverte}
            aria-controls={idListe}
            className={cn(
              'flex w-full items-center gap-1 rounded-md px-2 py-1 text-[10px] font-semibold uppercase tracking-wider transition',
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
