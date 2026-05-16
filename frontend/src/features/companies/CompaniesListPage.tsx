import { PageShell } from '@/components/ui/PageShell';

export function CompaniesListPage() {
  return (
    <PageShell
      title="Entreprises"
      subtitle="Liste des entreprises scrapées et enrichies."
      actions={<button className="rounded-md bg-brand-600 px-3 py-1.5 text-sm text-white">Lancer scraping</button>}
    >
      <p className="text-sm text-slate-600">La table virtualisée TanStack Table sera implémentée en Sprint 10.</p>
    </PageShell>
  );
}
