import { useQuery } from '@tanstack/react-query';
import { Card, CardHeader, CardTitle, CardEyebrow, Avatar, EmptyState } from '@/components/ui';
import { api } from '@/lib/api';

interface AuditLog {
  id: string;
  action: string;
  actor_name?: string | null;
  actor_email?: string | null;
  resource_type?: string | null;
  resource_id?: string | null;
  created_at: string;
}

interface AuditLogsResponse {
  data: AuditLog[];
}

function humanizeAction(a: string): string {
  return a.replace(/[._-]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function timeAgo(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  const diff = (Date.now() - d.getTime()) / 1000;
  if (diff < 60) return 'à l\'instant';
  if (diff < 3600) return `il y a ${Math.floor(diff / 60)} min`;
  if (diff < 86400) return `il y a ${Math.floor(diff / 3600)} h`;
  return `il y a ${Math.floor(diff / 86400)} j`;
}

export function ActivityFeed() {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['dashboard-activity'],
    queryFn: async () => {
      const r = await api.get<AuditLogsResponse | AuditLog[]>('/audit-logs', { params: { limit: 5 } });
      const payload = r.data as { data?: AuditLog[] };
      return (Array.isArray(r.data) ? (r.data as AuditLog[]) : (payload.data ?? [])).slice(0, 5);
    },
    staleTime: 30_000,
    retry: false,
  });

  const items = data ?? [];

  return (
    <Card>
      <CardHeader>
        <div className="min-w-0">
          <CardEyebrow>Temps réel</CardEyebrow>
          <CardTitle>Activité récente</CardTitle>
        </div>
      </CardHeader>

      {isLoading ? (
        <ul className="space-y-3" aria-busy="true">
          {Array.from({ length: 5 }).map((_, i) => (
            <li key={i} className="flex items-start gap-3">
              <div className="h-8 w-8 shrink-0 animate-pulse rounded-full bg-slate-200 dark:bg-slate-800" />
              <div className="flex-1 space-y-1.5">
                <div className="h-3 w-3/4 animate-pulse rounded bg-slate-200 dark:bg-slate-800" />
                <div className="h-2.5 w-1/3 animate-pulse rounded bg-slate-100 dark:bg-slate-800/60" />
              </div>
            </li>
          ))}
        </ul>
      ) : isError || items.length === 0 ? (
        <EmptyState
          title="Activité bientôt disponible"
          description="Les actions de ton équipe (scrapes, enrichissements, exports) apparaîtront ici."
          icon="📋"
        />
      ) : (
        <ul className="relative space-y-3 pl-1">
          {items.map((log, idx) => {
            const isLast = idx === items.length - 1;
            const name = log.actor_name ?? log.actor_email ?? 'Système';
            return (
              <li key={log.id} className="relative flex items-start gap-3">
                {/* timeline line */}
                {!isLast ? (
                  <span
                    className="absolute left-[15px] top-8 h-[calc(100%+0.25rem)] w-px bg-slate-200 dark:bg-slate-700"
                    aria-hidden
                  />
                ) : null}
                <Avatar name={name} size="sm" />
                <div className="min-w-0 flex-1 pt-0.5">
                  <p className="truncate text-xs text-slate-800 dark:text-slate-200">
                    <span className="font-medium">{name}</span>
                    <span className="text-slate-500 dark:text-slate-400"> · {humanizeAction(log.action)}</span>
                  </p>
                  {log.resource_type ? (
                    <p className="truncate text-[11px] text-slate-500 dark:text-slate-400">
                      {log.resource_type}{log.resource_id ? ` #${String(log.resource_id).slice(0, 8)}` : ''}
                    </p>
                  ) : null}
                  <p className="mt-0.5 text-[11px] text-slate-400 dark:text-slate-500">{timeAgo(log.created_at)}</p>
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </Card>
  );
}
