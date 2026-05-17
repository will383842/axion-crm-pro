import { useQuery } from '@tanstack/react-query';
import { PageShell } from '@/components/ui/PageShell';
import { CompaniesTableSkeleton } from '@/components/ui/Skeleton';
import { api } from '@/lib/api';

interface Run { id:number; source:string; status:string; latency_ms?:number|null; error?:string|null; started_at?:string|null; finished_at?:string|null }

export function ScraperRunsPage() {
  const { data, isLoading } = useQuery({
    queryKey: ['scraper-runs'],
    queryFn: async () => (await api.get<{ data: Run[] }>('/scraper-runs?per_page=50')).data,
    refetchInterval: 10_000,
  });

  return (
    <PageShell title="Scraper runs" subtitle="État des exécutions Horizon ↔ Node BullMQ (refresh 10s).">
      {isLoading ? <CompaniesTableSkeleton rows={8} /> : (
        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
          <table className="min-w-full divide-y divide-slate-200 text-sm">
            <thead className="bg-slate-50 text-left text-xs uppercase text-slate-600">
              <tr><th className="px-4 py-3">Source</th><th>Statut</th><th>Latence</th><th>Démarré</th><th>Fini</th><th>Erreur</th></tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {(data?.data ?? []).map((r) => (
                <tr key={r.id}>
                  <td className="px-4 py-2 font-medium">{r.source}</td>
                  <td className="px-4 py-2">
                    <span className={`rounded px-2 py-0.5 text-xs ${
                      r.status === 'success' ? 'bg-emerald-100 text-emerald-800' :
                      r.status === 'failed' ? 'bg-rose-100 text-rose-800' :
                      r.status === 'running' ? 'bg-blue-100 text-blue-800' :
                      'bg-slate-100 text-slate-700'
                    }`}>{r.status}</span>
                  </td>
                  <td className="px-4 py-2 tabular-nums text-slate-600">{r.latency_ms ? `${r.latency_ms} ms` : '—'}</td>
                  <td className="px-4 py-2 text-xs">{r.started_at ? new Date(r.started_at).toLocaleString('fr-FR') : '—'}</td>
                  <td className="px-4 py-2 text-xs">{r.finished_at ? new Date(r.finished_at).toLocaleString('fr-FR') : '—'}</td>
                  <td className="px-4 py-2 text-xs text-rose-700 max-w-xs truncate">{r.error ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </PageShell>
  );
}
