import { useQuery } from '@tanstack/react-query';
import { PageShell } from '@/components/ui/PageShell';
import { QualityBadge } from '@/components/ui/QualityBadge';
import { api } from '@/lib/api';

interface DashboardStats {
  companies_total: number;
  companies_enriched_24h: number;
  contacts_qualified: number;
  scraper_runs_24h: number;
  llm_cost_eur_month: number;
  quality_distribution: { complete: number; partielle: number; basique: number };
  size_distribution: Record<string, number>;
}

export function DashboardPage() {
  const { data } = useQuery({
    queryKey: ['dashboard-stats'],
    queryFn: async () => (await api.get<DashboardStats>('/dashboard/stats')).data,
    refetchInterval: 30_000,
    placeholderData: {
      companies_total: 0, companies_enriched_24h: 0, contacts_qualified: 0,
      scraper_runs_24h: 0, llm_cost_eur_month: 0,
      quality_distribution: { complete: 0, partielle: 0, basique: 0 },
      size_distribution: {},
    },
  });

  const total = data?.companies_total ?? 0;
  const qd = data?.quality_distribution ?? { complete: 0, partielle: 0, basique: 0 };
  const qSum = qd.complete + qd.partielle + qd.basique || 1;

  return (
    <PageShell title="Tableau de bord" subtitle="Vue d'ensemble de l'activité de prospection — actualisé chaque 30s.">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Kpi label="Entreprises totales" value={total.toLocaleString('fr-FR')} />
        <Kpi label="Enrichies 24h" value={(data?.companies_enriched_24h ?? 0).toLocaleString('fr-FR')} variant="success" />
        <Kpi label="Contacts qualifiés (🟢)" value={(data?.contacts_qualified ?? 0).toLocaleString('fr-FR')} />
        <Kpi label="Coût LLM ce mois" value={`${(data?.llm_cost_eur_month ?? 0).toFixed(2)} €`} variant="warning" />
      </div>

      <h2 className="mt-8 mb-3 text-sm font-semibold uppercase tracking-wide text-slate-600">Qualité des fiches</h2>
      <div className="rounded-xl border border-slate-200 bg-white p-5">
        <div className="flex items-center gap-4">
          <QualityBadge badge="complete" />
          <div className="flex-1">
            <div className="h-3 overflow-hidden rounded-full bg-slate-100">
              <div className="h-full bg-emerald-500" style={{ width: `${(qd.complete / qSum) * 100}%` }} />
            </div>
            <p className="mt-1 text-xs text-slate-500">{qd.complete} fiches ({((qd.complete / qSum) * 100).toFixed(1)} %)</p>
          </div>
        </div>
        <div className="mt-3 flex items-center gap-4">
          <QualityBadge badge="partielle" />
          <div className="flex-1">
            <div className="h-3 overflow-hidden rounded-full bg-slate-100">
              <div className="h-full bg-amber-500" style={{ width: `${(qd.partielle / qSum) * 100}%` }} />
            </div>
            <p className="mt-1 text-xs text-slate-500">{qd.partielle} fiches ({((qd.partielle / qSum) * 100).toFixed(1)} %)</p>
          </div>
        </div>
        <div className="mt-3 flex items-center gap-4">
          <QualityBadge badge="basique" />
          <div className="flex-1">
            <div className="h-3 overflow-hidden rounded-full bg-slate-100">
              <div className="h-full bg-rose-500" style={{ width: `${(qd.basique / qSum) * 100}%` }} />
            </div>
            <p className="mt-1 text-xs text-slate-500">{qd.basique} fiches ({((qd.basique / qSum) * 100).toFixed(1)} %)</p>
          </div>
        </div>
      </div>

      <h2 className="mt-8 mb-3 text-sm font-semibold uppercase tracking-wide text-slate-600">Distribution par taille</h2>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        {(['artisan','tpe','pme','eti','grande_entreprise'] as const).map((s) => (
          <div key={s} className="rounded-xl border border-slate-200 bg-white p-4">
            <p className="text-xs uppercase text-slate-500">{s === 'grande_entreprise' ? 'Grande' : s.toUpperCase()}</p>
            <p className="mt-1 text-2xl font-semibold tabular-nums">{data?.size_distribution?.[s] ?? 0}</p>
          </div>
        ))}
      </div>
    </PageShell>
  );
}

function Kpi({ label, value, variant }: { label: string; value: string; variant?: 'success'|'warning' }) {
  const accent = variant === 'success' ? 'border-emerald-200 bg-emerald-50' : variant === 'warning' ? 'border-amber-200 bg-amber-50' : 'border-slate-200 bg-white';
  return (
    <div className={`rounded-xl border p-5 shadow-sm ${accent}`}>
      <p className="text-xs uppercase text-slate-600">{label}</p>
      <p className="mt-2 text-3xl font-semibold tabular-nums">{value}</p>
    </div>
  );
}
