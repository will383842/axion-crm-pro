import { useQuery } from '@tanstack/react-query';
import { Activity, AlertTriangle, MailCheck, Archive, MapPin } from 'lucide-react';
import { Card, KpiCard, PageHeader } from '@/components/ui';
import { api } from '@/lib/api';

interface ObservabilitySummary {
  waterfall_errors_24h: number;
  hunter_quota_month: { used: number; soft_limit: number; percent: number };
  google_places_quota: { used: number; soft_limit: number; percent: number; pending_companies: number };
  archive_reasons: Record<string, number>;
  audience_failures_7d: number;
  recent_events: Array<{
    id: number;
    action: string;
    resource_type: string | null;
    resource_id: string | null;
    context: Record<string, unknown> | null;
    created_at: string;
  }>;
}

/**
 * Sprint H4 — Dashboard observabilité.
 * KPI cards + table dernières 50 business_events.
 * Data via /api/v1/observability/summary, queries directes Postgres (<100ms).
 */
export function ObservabilityPage() {
  const { data, isLoading, error } = useQuery({
    queryKey: ['observability', 'summary'],
    queryFn: async () => {
      const res = await api.get<{ data: ObservabilitySummary }>('/observability/summary');
      return res.data.data;
    },
    refetchInterval: 30_000,
  });

  if (isLoading) {
    return <div className="p-6 text-sm text-slate-500">Chargement de l'observabilité…</div>;
  }
  if (error || !data) {
    return (
      <div className="p-6 text-sm text-rose-600">
        Impossible de charger les métriques d'observabilité.
      </div>
    );
  }

  const totalArchived = Object.values(data.archive_reasons).reduce((a, b) => a + b, 0);

  return (
    <div className="space-y-6 p-6">
      <PageHeader
        title="Observabilité"
        subtitle="Santé pipeline waterfall, quota Hunter, archivages, échecs audience refresh."
      />

      <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5">
        <KpiCard
          label="Erreurs waterfall (24h)"
          value={data.waterfall_errors_24h}
          icon={<AlertTriangle className="size-4" />}
          tone={data.waterfall_errors_24h > 10 ? 'rose' : 'slate'}
        />
        <KpiCard
          label="Quota Google Places (mois)"
          value={`${data.google_places_quota.used} / ${data.google_places_quota.soft_limit}`}
          sublabel={
            data.google_places_quota.pending_companies > 0
              ? `${data.google_places_quota.percent}% utilisé · ${data.google_places_quota.pending_companies} en attente`
              : `${data.google_places_quota.percent}% utilisé`
          }
          progress={data.google_places_quota.percent}
          icon={<MapPin className="size-4" />}
          tone={data.google_places_quota.percent >= 100 ? 'rose' : data.google_places_quota.percent > 80 ? 'amber' : 'sky'}
        />
        <KpiCard
          label="Quota Hunter (mois)"
          value={`${data.hunter_quota_month.used} / ${data.hunter_quota_month.soft_limit}`}
          sublabel={`${data.hunter_quota_month.percent}% utilisé`}
          progress={data.hunter_quota_month.percent}
          icon={<MailCheck className="size-4" />}
          tone={data.hunter_quota_month.percent > 80 ? 'amber' : 'sky'}
        />
        <KpiCard
          label="Companies archivées"
          value={totalArchived}
          icon={<Archive className="size-4" />}
          tone="slate"
        />
        <KpiCard
          label="Échecs audience refresh (7j)"
          value={data.audience_failures_7d}
          icon={<Activity className="size-4" />}
          tone={data.audience_failures_7d > 0 ? 'amber' : 'emerald'}
        />
      </div>

      <Card>
        <CardSection title="Archivages par raison">
          {Object.entries(data.archive_reasons).length === 0 ? (
            <div className="text-sm text-slate-500">Aucun archivage enregistré.</div>
          ) : (
            <div className="grid grid-cols-2 gap-2 text-sm md:grid-cols-5">
              {Object.entries(data.archive_reasons).map(([reason, count]) => (
                <div
                  key={reason}
                  className="rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800/40"
                >
                  <div className="text-xs uppercase text-slate-500">{reason}</div>
                  <div className="text-lg font-semibold">{count}</div>
                </div>
              ))}
            </div>
          )}
        </CardSection>
      </Card>

      <Card>
        <CardSection title="50 derniers business events">
          <div className="max-h-[480px] overflow-y-auto text-sm">
            {data.recent_events.length === 0 ? (
              <div className="text-slate-500">Aucun event récent.</div>
            ) : (
              <table className="w-full">
                <thead className="sticky top-0 bg-white text-xs uppercase text-slate-500 dark:bg-slate-900">
                  <tr>
                    <th className="p-2 text-left">Date</th>
                    <th className="p-2 text-left">Action</th>
                    <th className="p-2 text-left">Resource</th>
                  </tr>
                </thead>
                <tbody>
                  {data.recent_events.map((event) => (
                    <tr key={event.id} className="border-t border-slate-100 dark:border-slate-800">
                      <td className="p-2 text-xs text-slate-500">
                        {new Date(event.created_at).toLocaleString('fr-FR')}
                      </td>
                      <td className="p-2 font-mono text-xs">{event.action}</td>
                      <td className="p-2 text-xs text-slate-500">
                        {event.resource_type ? `${event.resource_type} #${event.resource_id ?? '?'}` : '—'}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </CardSection>
      </Card>
    </div>
  );
}

function CardSection({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="p-4">
      <h3 className="mb-3 text-sm font-medium text-slate-700 dark:text-slate-200">{title}</h3>
      {children}
    </div>
  );
}
