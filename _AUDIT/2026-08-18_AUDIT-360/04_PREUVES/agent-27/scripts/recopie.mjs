import fs from 'node:fs';
import path from 'node:path';
const SRC = 'C:/Users/willi/Documents/Projets/Axion-CRM-Pro/frontend/src';

const SCREENS = [
  ['login','features/auth/LoginPage.tsx'],['2fa','features/auth/TwoFactorPage.tsx'],
  ['magic-link','features/auth/MagicLinkPage.tsx'],['password-reset','features/auth/PasswordResetPage.tsx'],
  ['/ dashboard','features/dashboard/DashboardPage.tsx'],['/companies','features/companies/CompaniesListPage.tsx'],
  ['/companies/$id','features/companies/CompanyDetailPage.tsx'],['/contacts','features/contacts/ContactsListPage.tsx'],
  ['/international/roumanie','features/international/RoumaniePage.tsx'],['/media','features/media/MediaListPage.tsx'],
  ['/media/$id','features/media/MediaDetailPage.tsx'],['/journalists','features/media/JournalistsListPage.tsx'],
  ['/coverage','features/coverage/CoveragePage.tsx'],['/scraper-runs','features/scraping/ScraperRunsPage.tsx'],
  ['/llm/router','features/llm/LlmRouterPage.tsx'],['/llm/proxy-providers','features/llm/ProxyProvidersPage.tsx'],
  ['/llm/rotations','features/llm/RotationsPage.tsx'],['/rgpd/requests','features/rgpd/RgpdRequestsPage.tsx'],
  ['/rgpd/ai-act','features/rgpd/AiActRegisterPage.tsx'],['/audit-logs','features/rgpd/AuditLogsPage.tsx'],
  ['/users','features/users/UsersPage.tsx'],['/settings','features/settings/SettingsPage.tsx'],
  ['/campaigns','features/campaigns/CampaignsListPage.tsx'],['/campaigns/new','features/campaigns/CampaignWizardPage.tsx'],
  ['/campaigns/$id','features/campaigns/CampaignDetailPage.tsx'],['/tags','features/tags/TagsManagerPage.tsx'],
  ['/audiences','features/audiences/AudiencesListPage.tsx'],['/audiences/new','features/audiences/AudienceBuilderPage.tsx'],
  ['/audiences/$id','features/audiences/AudienceDetailPage.tsx'],['/admin/observability','features/observability/ObservabilityPage.tsx'],
  ['/console/contacts','features/crm-console/ContactsHubPage.tsx'],['/console/vivier','features/crm-console/CandidatesPage.tsx'],
  ['/console/arbitrage','features/crm-console/ArbitragePage.tsx'],['/console/personnes/$k','features/crm-console/PersonTimelinePage.tsx'],
  ['/cold-email','features/phase2-scaffold/ColdEmailStub.tsx'],['/linkedin','features/phase2-scaffold/LinkedInStub.tsx'],
  ['404','features/misc/NotFoundPage.tsx'],
];

// motifs de balisage RECOPIE : ce qu'un composant du DS fait deja
const PATTERNS = [
  ['EN-TETE DE PAGE recopie',      /<h1\b/g,                                                   'PageHeader / PageShell'],
  ['BOUTON recopie (<button> nu)', /<button\b/g,                                               'Button / IconButton'],
  ['CARTE recopiee',               /className=(?:"|\{')[^"']*rounded-(?:xl|2xl)[^"']*(?:bg-white|bg-slate-50|bg-slate-900)[^"']*ring-1/g, 'Card'],
  ['PASTILLE recopiee',            /rounded-full[^"'`]{0,60}px-2(?:\.5)?[^"'`]{0,40}text-(?:\[1[01]px\]|xs)/g, 'StatusPill / QualityBadge'],
  ['EN-TETE DE TABLEAU recopie',   /sticky top-0 z-10 grid items-center gap-3 border-b/g,      '(aucun composant Table dans le DS)'],
  ['OMBRE en dur (jeton double)',  /shadow-\[0_/g,                                             'shadow-[var(--shadow-*)]'],
];

const rows = [];
for (const [label, rel] of SCREENS) {
  const f = path.join(SRC, rel);
  const src = fs.readFileSync(f, 'utf8');
  const lines = src.split(/\r?\n/);
  const cells = {};
  for (const [name, re] of PATTERNS) {
    const locs = [];
    lines.forEach((l, i) => { re.lastIndex = 0; const m = l.match(re); if (m) for (let k = 0; k < m.length; k++) locs.push(i + 1); });
    cells[name] = locs;
  }
  // composants locaux qui portent le MEME NOM qu'un export du DS
  const dsNames = new Set(['Button','IconButton','Card','CardHeader','CardTitle','CardEyebrow','CardFooter','KpiCard','SegmentedControl','SegOption','StatusPill','Tabs','Spinner','Tooltip','Modal','Drawer','DropdownMenu','Breadcrumbs','PageHeader','LiveBadge','Toolbar','SearchInput','Avatar','Stat','Input','PageShell','QualityBadge','SizeCategoryBadge','EmptyState','ErrorBoundary','Skeleton','FormField','DarkModeToggle','GlobalSearch']);
  const shadows = [];
  lines.forEach((l, i) => {
    const m = l.match(/^\s*(?:function|const|interface|type)\s+(\w+)/);
    if (m && dsNames.has(m[1])) shadows.push(`${m[1]}@L${i + 1}`);
  });
  rows.push({ label, rel, cells, shadows });
}

console.log('=== ECRAN -> BALISAGE RECOPIE (numeros de ligne) ===\n');
const hdr = PATTERNS.map(p => p[0]);
for (const r of rows) {
  const parts = hdr.map(h => `${h}=${r.cells[h].length}${r.cells[h].length ? ' (L' + [...new Set(r.cells[h])].join(',L') + ')' : ''}`);
  const tot = hdr.reduce((a, h) => a + r.cells[h].length, 0);
  console.log(`${r.label.padEnd(24)} TOTAL=${String(tot).padStart(3)}  ${parts.join('  |  ')}`);
  if (r.shadows.length) console.log(`${''.padEnd(24)}   >>> REDEFINIT LOCALEMENT DES NOMS DU DS : ${r.shadows.join(', ')}   [${r.rel}]`);
}
console.log('\n=== TOTAUX PAR MOTIF ===');
for (const [name, , remplace] of PATTERNS) {
  const n = rows.reduce((a, r) => a + r.cells[name].length, 0);
  const e = rows.filter(r => r.cells[name].length).length;
  console.log(`${name.padEnd(32)} ${String(n).padStart(3)} occurrences sur ${String(e).padStart(2)} ecrans   (le DS fournit : ${remplace})`);
}
const touches = rows.filter(r => hdr.reduce((a, h) => a + r.cells[h].length, 0) > 0).length;
console.log(`\nEcrans qui recopient au moins un motif : ${touches} / 37`);
