import { Outlet, Link } from '@tanstack/react-router';
import { GlobalSearch } from '@/components/ui/GlobalSearch';
import { DarkModeToggle } from '@/components/ui/DarkModeToggle';
import { OnboardingTour } from '@/components/OnboardingTour';

export function RootLayout() {
  return (
    <div className="flex min-h-screen bg-slate-50 dark:bg-slate-900">
      <a href="#main" className="skip-link">Aller au contenu</a>

      <aside data-tour="sidebar" className="w-60 shrink-0 border-r border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
        <div className="px-5 py-6 text-xl font-bold tracking-tight text-brand-700">Axion CRM Pro</div>
        <nav aria-label="Navigation principale" className="flex flex-col gap-1 px-3 text-sm">
          <NavLink to="/" dataTour="nav-dashboard">Dashboard</NavLink>
          <NavLink to="/companies" dataTour="nav-companies">Entreprises</NavLink>
          <NavLink to="/contacts">Contacts</NavLink>
          <NavLink to="/coverage">Couverture France</NavLink>
          <NavLink to="/scraper-runs">Scraper runs</NavLink>
          <div className="mt-4 px-3 text-xs font-semibold uppercase text-slate-400">LLM</div>
          <NavLink to="/llm/router">LLM Router</NavLink>
          <NavLink to="/llm/proxy-providers">Proxies</NavLink>
          <NavLink to="/llm/rotations">Rotations</NavLink>
          <div className="mt-4 px-3 text-xs font-semibold uppercase text-slate-400">RGPD</div>
          <NavLink to="/rgpd/requests">RGPD requests</NavLink>
          <NavLink to="/rgpd/ai-act">AI Act register</NavLink>
          <NavLink to="/audit-logs">Audit logs</NavLink>
          <div className="mt-4 px-3 text-xs font-semibold uppercase text-slate-400">Admin</div>
          <NavLink to="/users">Utilisateurs</NavLink>
          <NavLink to="/settings" dataTour="nav-settings">Paramètres</NavLink>
          <div className="mt-4 px-3 text-xs font-semibold uppercase text-slate-400">Phase 2 (scaffold)</div>
          <NavLink to="/campaigns">Campagnes</NavLink>
          <NavLink to="/cold-email">Cold email</NavLink>
          <NavLink to="/linkedin">LinkedIn outreach</NavLink>
          <NavLink to="/crm">CRM pipeline</NavLink>
          <NavLink to="/analytics">Analytics</NavLink>
        </nav>
      </aside>

      <main id="main" className="flex-1 overflow-x-hidden">
        <header className="sticky top-0 z-10 flex items-center gap-3 border-b border-slate-200 bg-white/90 px-6 py-2.5 backdrop-blur dark:border-slate-700 dark:bg-slate-800/90">
          <div data-tour="global-search" className="flex-1"><GlobalSearch /></div>
          <div data-tour="dark-mode"><DarkModeToggle /></div>
        </header>
        <Outlet />
      </main>

      <OnboardingTour />
    </div>
  );
}

function NavLink({ to, children, dataTour }: { to: string; children: React.ReactNode; dataTour?: string }) {
  return (
    <Link
      to={to}
      data-tour={dataTour}
      className="rounded-md px-3 py-1.5 text-slate-700 hover:bg-slate-100 [&.active]:bg-brand-100 [&.active]:text-brand-700 dark:text-slate-200 dark:hover:bg-slate-700"
    >
      {children}
    </Link>
  );
}
