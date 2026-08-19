import fs from 'node:fs';
import path from 'node:path';

const SRC = 'C:/Users/willi/Documents/Projets/Axion-CRM-Pro/frontend/src';

// 37 screens from app/routeTree.tsx
const SCREENS = [
  ['login', 'features/auth/LoginPage.tsx'],
  ['2fa', 'features/auth/TwoFactorPage.tsx'],
  ['magic-link', 'features/auth/MagicLinkPage.tsx'],
  ['password-reset', 'features/auth/PasswordResetPage.tsx'],
  ['/ (dashboard)', 'features/dashboard/DashboardPage.tsx'],
  ['/companies', 'features/companies/CompaniesListPage.tsx'],
  ['/companies/$id', 'features/companies/CompanyDetailPage.tsx'],
  ['/contacts', 'features/contacts/ContactsListPage.tsx'],
  ['/international/roumanie', 'features/international/RoumaniePage.tsx'],
  ['/media', 'features/media/MediaListPage.tsx'],
  ['/media/$id', 'features/media/MediaDetailPage.tsx'],
  ['/journalists', 'features/media/JournalistsListPage.tsx'],
  ['/coverage', 'features/coverage/CoveragePage.tsx'],
  ['/scraper-runs', 'features/scraping/ScraperRunsPage.tsx'],
  ['/llm/router', 'features/llm/LlmRouterPage.tsx'],
  ['/llm/proxy-providers', 'features/llm/ProxyProvidersPage.tsx'],
  ['/llm/rotations', 'features/llm/RotationsPage.tsx'],
  ['/rgpd/requests', 'features/rgpd/RgpdRequestsPage.tsx'],
  ['/rgpd/ai-act', 'features/rgpd/AiActRegisterPage.tsx'],
  ['/audit-logs', 'features/rgpd/AuditLogsPage.tsx'],
  ['/users', 'features/users/UsersPage.tsx'],
  ['/settings', 'features/settings/SettingsPage.tsx'],
  ['/campaigns', 'features/campaigns/CampaignsListPage.tsx'],
  ['/campaigns/new', 'features/campaigns/CampaignWizardPage.tsx'],
  ['/campaigns/$id', 'features/campaigns/CampaignDetailPage.tsx'],
  ['/tags', 'features/tags/TagsManagerPage.tsx'],
  ['/audiences', 'features/audiences/AudiencesListPage.tsx'],
  ['/audiences/new', 'features/audiences/AudienceBuilderPage.tsx'],
  ['/audiences/$id', 'features/audiences/AudienceDetailPage.tsx'],
  ['/admin/observability', 'features/observability/ObservabilityPage.tsx'],
  ['/console/contacts', 'features/crm-console/ContactsHubPage.tsx'],
  ['/console/vivier', 'features/crm-console/CandidatesPage.tsx'],
  ['/console/arbitrage', 'features/crm-console/ArbitragePage.tsx'],
  ['/console/personnes/$k', 'features/crm-console/PersonTimelinePage.tsx'],
  ['/cold-email', 'features/phase2-scaffold/ColdEmailStub.tsx'],
  ['/linkedin', 'features/phase2-scaffold/LinkedInStub.tsx'],
  ['404', 'features/misc/NotFoundPage.tsx'],
];

const COMPONENTS = [
  // layout
  'Sidebar', 'Header', 'UserMenu', 'WorkspaceSelector', 'AutoBreadcrumbs', 'OnboardingTour',
  // ui
  'Avatar', 'Breadcrumbs', 'Button', 'Card', 'cn', 'DarkModeToggle', 'DropdownMenu', 'EmptyState',
  'ErrorBoundary', 'FormField', 'GlobalSearch', 'IconButton', 'Input', 'KpiCard', 'Modal',
  'PageHeader', 'PageShell', 'QualityBadge', 'SegmentedControl', 'SizeCategoryBadge', 'Skeleton',
  'Spinner', 'Stat', 'StatusPill', 'Tabs', 'Toolbar', 'Tooltip',
  // secondary exports worth tracking
  'CardHeader', 'CardTitle', 'CardEyebrow', 'CardFooter', 'Drawer', 'SearchInput', 'LiveBadge',
  'CompaniesTableSkeleton', 'mapStatusToTone',
];

function namedImportsFrom(src) {
  // returns Map componentName -> true for imports coming from @/components/**
  const found = new Set();
  const re = /import\s+(?:type\s+)?\{([^}]*)\}\s*from\s*['"]([^'"]+)['"]/g;
  let m;
  while ((m = re.exec(src))) {
    const from = m[2];
    if (!/@\/components/.test(from)) continue;
    for (let raw of m[1].split(',')) {
      raw = raw.trim().replace(/^type\s+/, '');
      if (!raw) continue;
      const name = raw.split(/\s+as\s+/)[0].trim();
      found.add(name);
    }
  }
  // default/namespace imports
  const re2 = /import\s+(\w+)\s*(?:,\s*\{[^}]*\})?\s*from\s*['"](@\/components[^'"]*)['"]/g;
  while ((m = re2.exec(src))) found.add(m[1]);
  return found;
}

// --- ALL consumer files (screens + sub-components + layout + app) ---
function walk(dir, acc = []) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walk(p, acc);
    else if (/\.(tsx|ts)$/.test(e.name)) acc.push(p);
  }
  return acc;
}
const allFiles = walk(SRC).filter(f => !f.includes('__tests__') && !/\.test\./.test(f));

const screenSet = new Map(SCREENS.map(([label, rel]) => [path.join(SRC, rel).replace(/\\/g, '/'), label]));

const perScreen = new Map(); // label -> Set
for (const [rel, label] of SCREENS.map(([l, r]) => [r, l])) {
  const abs = path.join(SRC, rel);
  const src = fs.readFileSync(abs, 'utf8');
  perScreen.set(label, namedImportsFrom(src));
}

// per component: which screens import it
const compScreens = new Map(COMPONENTS.map(c => [c, []]));
for (const [label, set] of perScreen) {
  for (const n of set) if (compScreens.has(n)) compScreens.get(n).push(label);
}

// per component: any file in src (excluding the component's own dir + barrel) referencing it
const compAllFiles = new Map(COMPONENTS.map(c => [c, []]));
for (const f of allFiles) {
  const norm = f.replace(/\\/g, '/');
  if (norm.includes('/components/ui/') || norm.includes('/components/layout/')) continue;
  if (norm.endsWith('/components/OnboardingTour.tsx')) continue;
  const src = fs.readFileSync(f, 'utf8');
  const set = namedImportsFrom(src);
  for (const n of set) if (compAllFiles.has(n)) compAllFiles.get(n).push(norm.replace(SRC + '/', ''));
}

// internal usage (inside components dir, excluding own file)
const compInternal = new Map(COMPONENTS.map(c => [c, []]));
for (const f of allFiles) {
  const norm = f.replace(/\\/g, '/');
  if (!(norm.includes('/components/'))) continue;
  const src = fs.readFileSync(f, 'utf8');
  const set = namedImportsFrom(src);
  for (const n of set) if (compInternal.has(n)) compInternal.get(n).push(norm.replace(SRC + '/', ''));
}

const rows = [];
for (const c of COMPONENTS) {
  rows.push({
    composant: c,
    ecrans: compScreens.get(c).length,
    listeEcrans: compScreens.get(c).join(' · '),
    autresFichiers: compAllFiles.get(c).length,
    listeAutres: compAllFiles.get(c).join(' · '),
    interne: compInternal.get(c).join(' · '),
  });
}
rows.sort((a, b) => a.ecrans - b.ecrans || a.composant.localeCompare(b.composant));

console.log('=== COMPOSANT -> NB ECRANS (sur 37) | NB FICHIERS CONSOMMATEURS HORS components/ | USAGE INTERNE components/ ===');
for (const r of rows) {
  console.log(`${r.composant.padEnd(24)} ecrans=${String(r.ecrans).padStart(2)}  fichiersHorsComp=${String(r.autresFichiers).padStart(2)}  interne=[${r.interne}]`);
  if (r.ecrans) console.log(`    ecrans: ${r.listeEcrans}`);
  if (r.autresFichiers) console.log(`    fichiers: ${r.listeAutres}`);
}

console.log('\n=== ECRAN -> COMPOSANTS DS IMPORTES ===');
for (const [label] of SCREENS) {
  const set = [...perScreen.get(label)].sort();
  console.log(`${label.padEnd(24)} (${set.length}) ${set.join(', ') || '— AUCUN —'}`);
}
