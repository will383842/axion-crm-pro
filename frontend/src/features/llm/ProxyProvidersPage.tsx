import { useMutation, useQuery } from '@tanstack/react-query';
import { Activity, Globe } from 'lucide-react';
import {
  Button,
  Card,
  CompaniesTableSkeleton,
  EmptyState,
  PageHeader,
  StatusPill,
  type StatusTone,
  cn,
} from '@/components/ui';
import { api } from '@/lib/api';
import { toast } from 'sonner';

interface ProxyProvider {
  id: number;
  slug: string;
  type: 'residential' | 'datacenter' | 'mobile';
  zone: string;
  enabled: boolean;
  weight: number;
  endpoints_count: number;
  last_health_check_at?: string | null;
  last_health_status?: string | null;
}

const TYPE_TONE: Record<ProxyProvider['type'], StatusTone> = {
  residential: 'info',
  datacenter: 'success',
  mobile: 'warning',
};

const GRID = 'minmax(160px,1fr) 120px 120px 110px 180px 90px 100px';

export function ProxyProvidersPage() {
  const list = useQuery({
    queryKey: ['proxy-providers'],
    queryFn: async () => (await api.get<{ data: ProxyProvider[] }>('/proxy-providers')).data,
  });

  const testMut = useMutation({
    mutationFn: async (id: number) =>
      (await api.post<{ healthy: boolean }>(`/proxy-providers/${id}/test`)).data,
    onSuccess: (r) => toast.success(r.healthy ? 'Opérationnel ✓' : 'Indisponible ✗'),
    onError: () => toast.error('Échec du test'),
  });

  const rows = list.data?.data ?? [];

  return (
    <div className="px-6 py-6">
      <PageHeader
        title="Fournisseurs de proxies"
        subtitle="Webshare datacenter + IPRoyal résidentiel + Mock — bascule automatique selon zone."
      />

      {list.isLoading ? (
        <CompaniesTableSkeleton rows={4} />
      ) : rows.length === 0 ? (
        <EmptyState
          icon={<Globe className="h-10 w-10" />}
          title="Aucun fournisseur configuré"
          description="Configure WEBSHARE_API_KEY ou IPROYAL_USERNAME dans .env serveur pour activer les proxies."
          action={
            <Button variant="secondary" iconLeft={<Activity className="h-3.5 w-3.5" />}>
              Voir la documentation
            </Button>
          }
        />
      ) : (
        <Card padding="none" className="overflow-hidden">
          <div
            role="row"
            className={cn(
              'sticky top-0 z-10 grid items-center gap-3 border-b border-slate-200 bg-slate-50/80 px-4 py-3 text-[11px] font-semibold uppercase tracking-wider text-slate-600 backdrop-blur',
              'dark:border-slate-800 dark:bg-slate-900/80 dark:text-slate-400',
            )}
            style={{ gridTemplateColumns: GRID }}
          >
            <div>Identifiant</div>
            <div>Type</div>
            <div>Zone</div>
            <div className="text-right">Points de sortie</div>
            <div>État de santé</div>
            <div className="text-right">Poids</div>
            <div className="text-right">Actions</div>
          </div>
          <div className="divide-y divide-slate-100 dark:divide-slate-800">
            {rows.map((p) => (
              <div
                key={p.id}
                role="row"
                className="grid items-center gap-3 px-4 py-3 text-sm transition hover:bg-slate-50/70 dark:hover:bg-slate-800/30"
                style={{ gridTemplateColumns: GRID }}
              >
                <div className="truncate font-medium text-slate-900 dark:text-white">{p.slug}</div>
                <div>
                  <StatusPill tone={TYPE_TONE[p.type]}>{p.type}</StatusPill>
                </div>
                <div className="font-mono text-xs text-slate-500">{p.zone}</div>
                <div className="text-right tabular-nums">{p.endpoints_count}</div>
                <div className="text-xs text-slate-500">
                  {p.last_health_status ? (
                    <StatusPill tone={p.last_health_status === 'healthy' ? 'success' : 'danger'}>
                      {p.last_health_status}
                    </StatusPill>
                  ) : (
                    '—'
                  )}
                  {p.last_health_check_at ? (
                    <span className="ml-1 text-[11px] text-slate-400">
                      {new Date(p.last_health_check_at).toLocaleString('fr-FR')}
                    </span>
                  ) : null}
                </div>
                <div className="text-right tabular-nums">{p.weight}</div>
                <div className="text-right">
                  <Button
                    variant="secondary"
                    size="sm"
                    loading={testMut.isPending}
                    onClick={() => testMut.mutate(p.id)}
                  >
                    Tester
                  </Button>
                </div>
              </div>
            ))}
          </div>
        </Card>
      )}
    </div>
  );
}
