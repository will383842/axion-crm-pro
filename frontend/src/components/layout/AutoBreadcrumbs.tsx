/**
 * AutoBreadcrumbs — dérive les breadcrumbs depuis la route courante (TanStack Router).
 *
 * Mapping path -> label humain pour les routes connues de routeTree.tsx.
 * Les segments inconnus (UUIDs, IDs) sont affichés tronqués.
 */
import { useRouterState } from '@tanstack/react-router';
import { Home } from 'lucide-react';
import { Breadcrumbs, type Crumb } from '@/components/ui';

/**
 * D23-006 — cette table ne couvrait que 18 chemins pour un routeur qui en
 * déclare bien plus. Tout ce qui n'y figure pas retombe sur `humanize()`, qui
 * rend le segment d'URL brut : mesure du 2026-08-22, DIX routes s'affichaient
 * « Media / Journalists / Tags / Audiences / Admin › Observability / Console ›
 * … » — de l'anglais et des mots d'URL, dans un produit français, sur des écrans
 * qui portent partout ailleurs des libellés français.
 *
 * Les libellés sont ceux de la barre latérale (`Sidebar.tsx`), à dessein :
 * l'utilisateur doit relire dans le fil d'Ariane le mot qu'il a cliqué dans le
 * menu. La désynchronisation est le VRAI risque de cette table — c'est
 * exactement ce qui s'était produit — d'où la garde
 * `tests/components/fil-d-ariane.test.tsx`, qui énumère mécaniquement les
 * routes de `routeTree.tsx` et rougit dès qu'une nouvelle arrive sans libellé.
 *
 * ⚠️ `/cold-email` et `/linkedin` sont CONSERVÉS. Le constat proposait de les
 * retirer comme « morts » ; ils ne le sont pas : les deux routes existent
 * toujours dans `routeTree.tsx` et restent joignables par URL — elles ont
 * seulement quitté la barre (`Sidebar.tsx:76`). Les retirer d'ici ferait
 * afficher « Cold email » et « Linkedin » à qui y arrive par un signet : on
 * remplacerait une entrée jugée inutile par le défaut même qu'on répare. Leur
 * sort se décide avec celui des routes (A-005), pas dans cette table.
 */
const LABELS: Record<string, string> = {
  '/': 'Tableau de bord',
  '/companies': 'Entreprises',
  '/contacts': 'Contacts',
  '/coverage': 'Couverture France',
  '/scraper-runs': 'Journaux de collecte',
  '/llm': 'LLM',
  '/llm/router': 'Router',
  '/llm/proxy-providers': 'Proxies',
  '/llm/rotations': 'Rotations',
  '/rgpd': 'RGPD',
  '/rgpd/requests': 'Requêtes',
  '/rgpd/ai-act': 'Registre AI Act',
  '/audit-logs': 'Journaux d’audit',
  '/users': 'Utilisateurs',
  '/settings': 'Paramètres',
  '/campaigns': 'Collectes',
  '/campaigns/new': 'Nouvelle collecte',
  // 2026-08-23 — §8.2 de `10_NAVIGATION-CIBLE.md` : ces deux adresses ne
  // montent plus d'écran, elles redirigent vers `/pas-encore-livre?lot=L7`.
  // Leur libellé RESTE : le fil d'Ariane peut être rendu pendant le temps très
  // court où la route est résolue mais la redirection pas encore appliquée, et
  // un libellé absent y ferait apparaître « cold-email » en anglais brut.
  '/cold-email': 'E-mails à froid',
  '/linkedin': 'Prospection LinkedIn',
  '/pas-encore-livre': 'Pas encore livré',
  '/crm': 'Contacts',
  '/analytics': 'Tableau de bord',
  // D23-006 — les dix routes qui parlaient anglais, plus les segments
  // intermédiaires (`/admin`, `/international`, `/console`) : ils n'ont pas
  // d'écran à eux mais apparaissent quand même dans le fil.
  '/media': 'Médias (presse)',
  '/journalists': 'Journalistes',
  '/tags': 'Tags',
  '/audiences': 'Audiences (segments)',
  '/audiences/new': 'Nouvelle audience',
  '/admin': 'Administration',
  '/admin/observability': 'Observabilité',
  '/international': 'International',
  '/international/roumanie': 'Roumanie',
  '/console': 'Console CRM',
  '/console/contacts': 'Contacts',
  '/console/vivier': 'Vivier candidats',
  '/console/arbitrage': 'À arbitrer',
  '/console/personnes': 'Personnes',
  // F7 — `/crm` et `/analytics` retirés du routeur : plus de libellé à mapper.
};

/** Table exposée pour la garde D23-006. Lecture seule : jamais mutée. */
export const LIBELLES_DE_CHEMIN: Readonly<Record<string, string>> = LABELS;

/**
 * Libellé humain d'un chemin complet, pour qui doit NOMMER l'écran courant
 * ailleurs que dans le fil d'Ariane — la région d'annonce de `RootLayout`
 * (D28-014) en particulier. Retombe sur le dernier segment humanisé, comme le
 * fil lui-même : deux façons de nommer le même écran finiraient par diverger.
 */
export function libelleDeChemin(pathname: string): string {
  if (pathname === '/' || pathname === '') return LABELS['/'] as string;
  const direct = LABELS[pathname];
  if (direct !== undefined) return direct;
  const segments = pathname.split('/').filter(Boolean);
  return humanize(segments[segments.length - 1] ?? '');
}

function humanize(segment: string): string {
  // UUID-like → ID tronqué
  if (/^[0-9a-f]{8}-[0-9a-f]{4}/i.test(segment)) return `#${segment.slice(0, 8)}`;
  // numeric ID
  if (/^\d+$/.test(segment)) return `#${segment}`;
  return segment.charAt(0).toUpperCase() + segment.slice(1).replace(/-/g, ' ');
}

export function AutoBreadcrumbs() {
  const pathname = useRouterState({ select: (s) => s.location.pathname });

  const crumbs: Crumb[] = [{ label: 'Accueil', to: '/', icon: <Home className="h-3 w-3" /> }];

  if (pathname === '/' || pathname === '') {
    return <Breadcrumbs items={crumbs} tone="inverse" />;
  }

  const segments = pathname.split('/').filter(Boolean);
  let acc = '';
  segments.forEach((seg, idx) => {
    acc += `/${seg}`;
    const label = LABELS[acc] ?? humanize(seg);
    const isLast = idx === segments.length - 1;
    // Intermediate route may not match a route (e.g. /llm alone) → no link
    const hasRoute = Boolean(LABELS[acc]);
    crumbs.push({
      label,
      ...(hasRoute && !isLast ? { to: acc } : {}),
    });
  });

  return <Breadcrumbs items={crumbs} tone="inverse" />;
}
