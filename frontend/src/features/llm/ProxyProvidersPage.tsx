import { useQuery, useMutation } from '@tanstack/react-query';
import { PageShell } from '@/components/ui/PageShell';
import { EmptyState } from '@/components/ui/EmptyState';
import { CompaniesTableSkeleton } from '@/components/ui/Skeleton';
import { api } from '@/lib/api';
import { toast } from 'sonner';

interface ProxyProvider {
  id: number; slug: string; type: 'residential'|'datacenter'|'mobile';
  zone: string; enabled: boolean; weight: number;
  endpoints_count: number; last_health_check_at?: string|null; last_health_status?: string|null;
}

export function ProxyProvidersPage() {
  const list = useQuery({
    queryKey: ['proxy-providers'],
    queryFn: async () => (await api.get<{ data: ProxyProvider[] }>('/proxy-providers')).data,
  });

  const testMut = useMutation({
    mutationFn: async (id: number) => (await api.post(`/proxy-providers/${id}/test`)).data,
    onSuccess: (r) => toast.success(r.healthy ? 'Healthy ✓' : 'Unhealthy ✗'),
    onError: () => toast.error('Test failed'),
  });

  return (
    <PageShell
      title="Proxy providers"
      subtitle="Webshare datacenter + IPRoyal résidentiel + Mock — failover automatique selon zone."
    >
      {list.isLoading ? <CompaniesTableSkeleton rows={4} />
        : (list.data?.data ?? []).length === 0 ? (
          <EmptyState icon="🌐" title="Aucun provider configuré"
            description="Configure WEBSHARE_API_KEY ou IPROYAL_USERNAME dans .env serveur." />
        ) : (
          <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <table className="min-w-full divide-y divide-slate-200 text-sm">
              <thead className="bg-slate-50 text-left text-xs uppercase text-slate-600">
                <tr>
                  <th className="px-4 py-3">Slug</th>
                  <th>Type</th><th>Zone</th><th>Endpoints</th>
                  <th>Health</th><th>Weight</th><th></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {list.data!.data.map((p) => (
                  <tr key={p.id}>
                    <td className="px-4 py-2 font-medium">{p.slug}</td>
                    <td className="px-4 py-2">
                      <span className={`rounded px-2 py-0.5 text-xs ${
                        p.type === 'residential' ? 'bg-violet-100 text-violet-800'
                        : p.type === 'datacenter' ? 'bg-sky-100 text-sky-800'
                        : 'bg-amber-100 text-amber-800'
                      }`}>{p.type}</span>
                    </td>
                    <td className="px-4 py-2 font-mono text-xs">{p.zone}</td>
                    <td className="px-4 py-2 tabular-nums">{p.endpoints_count}</td>
                    <td className="px-4 py-2 text-xs">
                      {p.last_health_status ?? '—'}
                      {p.last_health_check_at && (
                        <span className="ml-1 text-slate-400">({new Date(p.last_health_check_at).toLocaleString('fr-FR')})</span>
                      )}
                    </td>
                    <td className="px-4 py-2 tabular-nums">{p.weight}</td>
                    <td className="px-4 py-2">
                      <button onClick={() => testMut.mutate(p.id)} disabled={testMut.isPending}
                        className="rounded bg-brand-600 px-2 py-1 text-xs text-white disabled:opacity-50">
                        Test
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
    </PageShell>
  );
}
