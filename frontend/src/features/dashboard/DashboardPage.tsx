import { PageShell } from '@/components/ui/PageShell';

export function DashboardPage() {
  return (
    <PageShell title="Tableau de bord" subtitle="Vue d'ensemble de l'activité de prospection.">
      <div className="grid gap-4 md:grid-cols-3">
        <Kpi label="Entreprises enrichies" value="—" />
        <Kpi label="Contacts qualifiés (🟢)" value="—" />
        <Kpi label="Scraper runs 24h" value="—" />
      </div>
    </PageShell>
  );
}

function Kpi({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
      <p className="text-sm text-slate-600">{label}</p>
      <p className="mt-2 text-3xl font-semibold tabular-nums">{value}</p>
    </div>
  );
}
