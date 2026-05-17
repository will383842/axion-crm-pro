import { createRootRoute, createRoute, Outlet } from '@tanstack/react-router';
import { RootLayout } from './RootLayout';
import { LoginPage } from '@/features/auth/LoginPage';
import { TwoFactorPage } from '@/features/auth/TwoFactorPage';
import { MagicLinkPage } from '@/features/auth/MagicLinkPage';
import { PasswordResetPage } from '@/features/auth/PasswordResetPage';
import { DashboardPage } from '@/features/dashboard/DashboardPage';
import { CompaniesListPage } from '@/features/companies/CompaniesListPage';
import { CompanyDetailPage } from '@/features/companies/CompanyDetailPage';
import { ContactsListPage } from '@/features/contacts/ContactsListPage';
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
// Phase 2 scaffold stubs
import { ColdEmailStub } from '@/features/phase2-scaffold/ColdEmailStub';
import { LinkedInStub } from '@/features/phase2-scaffold/LinkedInStub';
import { CrmStub } from '@/features/phase2-scaffold/CrmStub';
import { AnalyticsStub } from '@/features/phase2-scaffold/AnalyticsStub';

export const rootRoute = createRootRoute({ component: () => <Outlet /> });

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
const contactsRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/contacts', component: ContactsListPage });
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
// Phase 2 stubs
const coldEmailRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/cold-email', component: ColdEmailStub });
const linkedInRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/linkedin', component: LinkedInStub });
const crmRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/crm', component: CrmStub });
const analyticsRoute = createRoute({ getParentRoute: () => layoutRoute, path: '/analytics', component: AnalyticsStub });

const notFoundRoute = createRoute({ getParentRoute: () => rootRoute, path: '/*', component: NotFoundPage });

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
    coldEmailRoute,
    linkedInRoute,
    crmRoute,
    analyticsRoute,
  ]),
  notFoundRoute,
]);
