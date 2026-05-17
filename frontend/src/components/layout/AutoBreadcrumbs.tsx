/**
 * AutoBreadcrumbs — dérive les breadcrumbs depuis la route courante (TanStack Router).
 *
 * Mapping path -> label humain pour les routes connues de routeTree.tsx.
 * Les segments inconnus (UUIDs, IDs) sont affichés tronqués.
 */
import { useRouterState } from '@tanstack/react-router';
import { Home } from 'lucide-react';
import { Breadcrumbs, type Crumb } from '@/components/ui';

const LABELS: Record<string, string> = {
  '/': 'Tableau de bord',
  '/companies': 'Entreprises',
  '/contacts': 'Contacts',
  '/coverage': 'Couverture France',
  '/scraper-runs': 'Runs de scraping',
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
  '/campaigns': 'Campagnes',
  '/cold-email': 'E-mails à froid',
  '/linkedin': 'Prospection LinkedIn',
  '/crm': 'Pipeline CRM',
  '/analytics': 'Analytique',
};

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
    return <Breadcrumbs items={crumbs} />;
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

  return <Breadcrumbs items={crumbs} />;
}
