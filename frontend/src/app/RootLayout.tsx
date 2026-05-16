import { Outlet, Link } from '@tanstack/react-router';

export function RootLayout() {
  return (
    <div className="flex min-h-screen bg-slate-50">
      <a href="#main" className="skip-link">Aller au contenu</a>

      <aside className="w-60 shrink-0 border-r border-slate-200 bg-white">
        <div className="px-5 py-6 text-xl font-bold tracking-tight text-brand-700">Axion CRM Pro</div>
        <nav aria-label="Navigation principale" className="flex flex-col gap-1 px-3 text-sm">
          <NavLink to="/">Dashboard</NavLink>
          <NavLink to="/companies">Entreprises</NavLink>
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
          <NavLink to="/settings">Paramètres</NavLink>
          <div className="mt-4 px-3 text-xs font-semibold uppercase text-slate-400">Phase 2 (scaffold)</div>
          <NavLink to="/campaigns">Campagnes</NavLink>
          <NavLink to="/cold-email">Cold email</NavLink>
          <NavLink to="/linkedin">LinkedIn outreach</NavLink>
          <NavLink to="/crm">CRM pipeline</NavLink>
          <NavLink to="/analytics">Analytics</NavLink>
        </nav>
      </aside>

      <main id="main" className="flex-1 overflow-x-hidden">
        <Outlet />
      </main>
    </div>
  );
}

function NavLink({ to, children }: { to: string; children: React.ReactNode }) {
  return (
    <Link
      to={to}
      className="rounded-md px-3 py-1.5 text-slate-700 hover:bg-slate-100 [&.active]:bg-brand-100 [&.active]:text-brand-700"
    >
      {children}
    </Link>
  );
}
