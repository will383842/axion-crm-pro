import { useQuery } from '@tanstack/react-query';
import { PageShell } from '@/components/ui/PageShell';
import { CompaniesTableSkeleton } from '@/components/ui/Skeleton';
import { api } from '@/lib/api';

interface Rotation {
  id: number; dimension: 'proxy'|'user_agent'|'target'|'search_engine'|'llm';
  slug: string; weight: number; cooldown_seconds: number; enabled: boolean;
  last_used_at?: string|null;
}

export function RotationsPage() {
  const { data, isLoading } = useQuery({
    queryKey: ['rotations'],
    queryFn: async () => (await api.get<{ data: Rotation[] }>('/rotations')).data,
  });

  const byDimension = (data?.data ?? []).reduce<Record<string, Rotation[]>>((acc, r) => {
    (acc[r.dimension] ??= []).push(r);
    return acc;
  }, {});

  return (
    <PageShell
      title="Rotations"
      subtitle="5 dimensions de rotation : proxies + user-agents + targets + moteurs recherche + LLM providers."
    >
      {isLoading ? <CompaniesTableSkeleton rows={5} /> : (
        <div className="space-y-6">
          {(['proxy','user_agent','target','search_engine','llm'] as const).map((dim) => {
            const items = byDimension[dim] ?? [];
            return (
              <section key={dim} className="rounded-xl border border-slate-200 bg-white p-5">
                <h2 className="mb-3 text-sm font-semibold uppercase text-slate-600">
                  {dim.replace('_', ' ')} ({items.length})
                </h2>
                {items.length === 0 ? (
                  <p className="text-sm text-slate-500">Aucune rotation configurée.</p>
                ) : (
                  <table className="min-w-full text-sm">
                    <thead className="text-left text-xs uppercase text-slate-500">
                      <tr><th className="py-2">Slug</th><th>Poids</th><th>Cooldown</th><th>État</th><th>Dernier usage</th></tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {items.map((r) => (
                        <tr key={r.id}>
                          <td className="py-2 font-mono text-xs">{r.slug}</td>
                          <td className="tabular-nums">{r.weight}</td>
                          <td className="tabular-nums text-xs">{r.cooldown_seconds}s</td>
                          <td>
                            <span className={`rounded px-2 py-0.5 text-xs ${r.enabled ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600'}`}>
                              {r.enabled ? 'ON' : 'OFF'}
                            </span>
                          </td>
                          <td className="text-xs text-slate-500">
                            {r.last_used_at ? new Date(r.last_used_at).toLocaleString('fr-FR') : 'Jamais'}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                )}
              </section>
            );
          })}
        </div>
      )}
    </PageShell>
  );
}
