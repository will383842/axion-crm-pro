import { createRootRoute, createRoute, Outlet } from '@tanstack/react-router';
import { RootLayout } from './RootLayout';
import { RouteErrorBoundary } from './RouteErrorBoundary';
import { LoginPage } from '@/features/auth/LoginPage';
import { TwoFactorPage } from '@/features/auth/TwoFactorPage';
import { MagicLinkPage } from '@/features/auth/MagicLinkPage';
import { PasswordResetPage } from '@/features/auth/PasswordResetPage';
import { DashboardPage } from '@/features/dashboard/DashboardPage';
import { CompaniesListPage } from '@/features/companies/CompaniesListPage';
import { CompanyDetailPage } from '@/features/companies/CompanyDetailPage';
import { RoumaniePage } from '@/features/international/RoumaniePage';
// D22-005 — la route `/contacts` ne monte plus l'écran directement : elle passe
// par `ContactsRoute`, qui redirige vers `/console/contacts` quand le drapeau
// `console_v2` est ouvert. Sans cela, l'écran restait joignable par signet alors
// que la barre latérale ne le montrait plus.
import { ContactsRoute } from '@/features/contacts/ContactsRoute';
import { MediaListPage } from '@/features/media/MediaListPage';
import { MediaDetailPage } from '@/features/media/MediaDetailPage';
import { JournalistsListPage } from '@/features/media/JournalistsListPage';
import { CoveragePage } from '@/features/coverage/CoveragePage';
import { ScraperRunsPage } from '@/features/scraping/ScraperRunsPage';
import { LlmRouterPage } from '@/features/llm/LlmRouterPage';
import { ProxyProvidersPage } from '@/features/llm/ProxyProvidersPage';
import { RotationsPage } from '@/features/llm/RotationsPage';
import { RgpdRequestsPage } from '@/features/rgpd/RgpdRequestsPage';
import { AiActRegisterPage } from '@/features/rgpd/AiActRegisterPage';
import { AuditLogsPage } from '@/features/rgpd/AuditLogsPage';
import { UsersPage } from '@/features/users/UsersPage';
import { SettingsPage } from '@/features/settings/SettingsPage';
import { NotFoundPage } from '@/features/misc/NotFoundPage';
// Sprint 19.7 — Scraping Campaigns (live)
import { CampaignsListPage } from '@/features/campaigns/CampaignsListPage';
import { CampaignWizardPage } from '@/features/campaigns/CampaignWizardPage';
import { CampaignDetailPage } from '@/features/campaigns/CampaignDetailPage';
// Sprint Pipeline 360° — Tags Manager + Audiences
import { TagsManagerPage } from '@/features/tags/TagsManagerPage';
import { AudiencesListPage } from '@/features/audiences/AudiencesListPage';
import { AudienceBuilderPage } from '@/features/audiences/AudienceBuilderPage';
import { AudienceDetailPage } from '@/features/audiences/AudienceDetailPage';
// Sprint H4 Hardening — Dashboard observabilité
import { ObservabilityPage } from '@/features/observability/ObservabilityPage';
// Lot L6 — Console CRM v2 (derrière le drapeau runtime CRM_CONSOLE_V2_ENABLED).
// Préfixe `/console` et non `/crm` : au moment du Lot L6, `/crm` était pris par
// le stub Phase 2 `CrmStub`, et écraser une route existante pour un lot gaté
// aurait remplacé un écran qui répond par un écran qui n'affiche rien.
// ⚠️ Depuis F7 (étape 0), `CrmStub` et `AnalyticsStub` sont SUPPRIMÉS : `/crm`
// et `/analytics` sont LIBRES. Le préfixe `/console` est conservé tel quel —
// le chantier CRM cible pourra reprendre `/crm` sans collision, et c'est lui
// qui décidera si `/console/*` y est redirigé ou reste l'adresse de la v2.
import { ContactsHubPage } from '@/features/crm-console/ContactsHubPage';
import { CandidatesPage } from '@/features/crm-console/CandidatesPage';
import { ArbitragePage } from '@/features/crm-console/ArbitragePage';
import { PersonTimelinePage } from '@/features/crm-console/PersonTimelinePage';
// Phase 2 scaffold stubs
import { ColdEmailStub } from '@/features/phase2-scaffold/ColdEmailStub';
import { LinkedInStub } from '@/features/phase2-scaffold/LinkedInStub';

// P6-UI-005 — SITE DE MONTAGE 1/3 de la frontiere d'erreur (cf.
// `RouteErrorBoundary.tsx` pour la mesure et la justification des trois sites).
//
// Cette frontiere-ci couvre ce que la coquille ne couvre PAS : les quatre
// ecrans d'authentification ci-dessous (`/login`, `/2fa`, `/magic-link`,
// `/password-reset`) sont enfants de `rootRoute`, pas de `layoutRoute`. Une
// reparation qui n'aurait touche que `RootLayout` les aurait laisses sur
// l'ecran anglais « Something went wrong! » de TanStack Router.
//
// Elle est posee A L'INTERIEUR du routeur, donc SOUS le filet global de la
// librairie (`@tanstack/react-router/dist/esm/Matches.js:36`) : une frontiere
// React plus profonde attrape en premier, et c'est notre message qui gagne.
export const rootRoute = createRootRoute({
  component: () => (
    <RouteErrorBoundary level="root">
      <Outlet />
    </RouteErrorBoundary>
  ),
  // D22-007 / D28-013 — l'écran « Page introuvable » existait, était importé,
  // était livré dans le bundle… et ne s'est JAMAIS affiché. Il était branché sur
  // une route `path: '/*'`, or le jeton fourre-tout de TanStack Router v1 est
  // `$` et non `*` (`router-core/dist/esm/new-process-route-tree.js:53` ne teste
  // que le code 36, `'$'`) : `'/*'` n'était qu'un segment STATIQUE nommé « * ».
  // Une URL inconnue tombait donc sur l'écran par défaut de la librairie —
  // `react-router/dist/esm/not-found.js:41`, un `<p>Not Found</p>` en anglais,
  // sans un seul élément atteignable au clavier (mesure du 2026-08-22).
  //
  // `notFoundComponent` sur la racine plutôt que `path: '$'` : `Match.js:73`
  // consulte cette option pour la route racine, et cette voie ne touche PAS
  // l'appariement des routes — corriger le chemin en `'$'` aurait fait
  // correspondre une vraie route fourre-tout, avec le risque de capter des URL
  // qui tombaient jusque-là ailleurs.
  notFoundComponent: NotFoundPage,
});

const layoutRoute = createRoute({
  getParentRoute: () => rootRoute,
  id: 'layout',
  component: RootLayout,
});

const loginRoute = createRoute({ getParentRoute: () => rootRoute, path: '/login', component: LoginPage });
const twoFactorRoute = createRoute({ getParentRoute: () => rootRoute, path: '/2fa', component: TwoFactorPage });
const magicLinkRoute = createRoute({ getParentRoute: () => rootRoute, path: '/magic-link', component: MagicLinkPage });
const passwordResetRoute = createRoute({ getParentRoute: () => rootRoute, path: '/password-reset', component: PasswordResetPage });

const dashboardRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/', component: DashboardPage });
const companiesRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/companies', component: CompaniesListPage });
const companyDetailRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/companies/$companyId', component: CompanyDetailPage });
const contactsRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/contacts', component: ContactsRoute });
const roumanieRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/international/roumanie', component: RoumaniePage });
const mediaRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/media', component: MediaListPage });
const mediaDetailRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/media/$mediaId', component: MediaDetailPage });
const journalistsRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/journalists', component: JournalistsListPage });
const coverageRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/coverage', component: CoveragePage });
const scraperRunsRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/scraper-runs', component: ScraperRunsPage });
const llmRouterRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/llm/router', component: LlmRouterPage });
const proxyProvidersRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/llm/proxy-providers', component: ProxyProvidersPage });
const rotationsRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/llm/rotations', component: RotationsPage });
const rgpdRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/rgpd/requests', component: RgpdRequestsPage });
const aiActRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/rgpd/ai-act', component: AiActRegisterPage });
const auditRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/audit-logs', component: AuditLogsPage });
const usersRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/users', component: UsersPage });
const settingsRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/settings', component: SettingsPage });

// Sprint 19.7 — Campagnes de scraping (live)
const campaignsRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/campaigns', component: CampaignsListPage });
const campaignsNewRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/campaigns/new', component: CampaignWizardPage });
const campaignDetailRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/campaigns/$campaignId', component: CampaignDetailPage });
// Sprint Pipeline 360° — Tags + Audiences
const tagsRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/tags', component: TagsManagerPage });
const audiencesRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/audiences', component: AudiencesListPage });
const audiencesNewRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/audiences/new', component: AudienceBuilderPage });
const audienceDetailRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/audiences/$audienceId', component: AudienceDetailPage });
// Sprint H4 Hardening — Dashboard observabilité
const observabilityRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/admin/observability', component: ObservabilityPage });
// Lot L6 — Console CRM v2
const consoleContactsRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/console/contacts', component: ContactsHubPage });
const consoleVivierRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/console/vivier', component: CandidatesPage });
const consoleArbitrageRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/console/arbitrage', component: ArbitragePage });
const consolePersonRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/console/personnes/$personKey', component: PersonTimelinePage });
// Phase 2 stubs
const coldEmailRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/cold-email', component: ColdEmailStub });
const linkedInRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/linkedin', component: LinkedInStub });
// F7 — `/crm` et `/analytics` retirés : les écrans bouchons portaient le
// vocabulaire du chantier CRM cible. Ils tombent désormais sur l'écran
// « Page introuvable » — ce qui n'est vrai que depuis D22-007 : jusque-là ils
// tombaient sur le `<p>Not Found</p>` anglais de la librairie.

// D22-007 / D28-013 — `notFoundRoute` (`path: '/*'`) est SUPPRIMÉE : elle
// n'attrapait rien (voir `notFoundComponent` sur `rootRoute` ci-dessus) et sa
// seule présence laissait croire le contraire à la lecture.

export const routeTree = rootRoute.addChildren([
  loginRoute,
  twoFactorRoute,
  magicLinkRoute,
  passwordResetRoute,
  layoutRoute.addChildren([
    dashboardRoute,
    companiesRoute,
    companyDetailRoute,
    contactsRoute,
    roumanieRoute,
    mediaRoute,
    mediaDetailRoute,
    journalistsRoute,
    coverageRoute,
    scraperRunsRoute,
    llmRouterRoute,
    proxyProvidersRoute,
    rotationsRoute,
    rgpdRoute,
    aiActRoute,
    auditRoute,
    usersRoute,
    settingsRoute,
    campaignsRoute,
    campaignsNewRoute,
    campaignDetailRoute,
    tagsRoute,
    audiencesRoute,
    audiencesNewRoute,
    audienceDetailRoute,
    observabilityRoute,
    consoleContactsRoute,
    consoleVivierRoute,
    consoleArbitrageRoute,
    consolePersonRoute,
    coldEmailRoute,
    linkedInRoute,
  ]),
]);
