import { useQuery } from '@tanstack/react-query';
import { Repeat } from 'lucide-react';
import {
  Card,
  CardEyebrow,
  CardTitle,
  CompaniesTableSkeleton,
  EmptyState,
  PageHeader,
  StatusPill,
  cn,
} from '@/components/ui';
import { api } from '@/lib/api';

type Dimension = 'proxy' | 'user_agent' | 'target' | 'search_engine' | 'llm';

interface Rotation {
  id: number;
  dimension: Dimension;
  slug: string;
  weight: number;
  cooldown_seconds: number;
  enabled: boolean;
  last_used_at?: string | null;
}

const DIMENSION_LABELS: Record<Dimension, string> = {
  proxy: 'Proxy',
  user_agent: 'User agent',
  target: 'Target',
  search_engine: 'Search engine',
  llm: 'LLM provider',
};

const ROW_GRID = 'minmax(180px,1fr) 90px 110px 90px 180px';

export function RotationsPage() {
  const { data, isLoading } = useQuery({
    queryKey: ['rotations'],
    queryFn: async () => (await api.get<{ data: Rotation[] }>('/rotations')).data,
  });

  const byDimension = (data?.data ?? []).reduce<Record<string, Rotation[]>>((acc, r) => {
    (acc[r.dimension] ??= []).push(r);
    return acc;
  }, {});

  const total = (data?.data ?? []).length;

  return (
    <div className="px-6 py-6">
      <PageHeader
        title="Rotations"
        subtitle="5 dimensions de rotation : proxies + user-agents + targets + moteurs de recherche + LLM providers."
      />

      {isLoading ? (
        <CompaniesTableSkeleton rows={5} />
      ) : total === 0 ? (
        <EmptyState
          icon={<Repeat className="h-10 w-10" />}
          title="Aucune rotation configurée"
          description="Configure des rotations pour fluidifier le scraping et éviter le rate-limiting des providers."
        />
      ) : (
        <div className="space-y-4">
          {(Object.keys(DIMENSION_LABELS) as Dimension[]).map((dim) => {
            const items = byDimension[dim] ?? [];
            return (
              <Card key={dim} padding="none" className="overflow-hidden">
                <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4 dark:border-slate-800">
                  <div>
                    <CardEyebrow>Dimension</CardEyebrow>
                    <CardTitle className="mt-0.5 text-base">{DIMENSION_LABELS[dim]}</CardTitle>
                  </div>
                  <StatusPill tone="neutral">{items.length}</StatusPill>
                </div>
                {items.length === 0 ? (
                  <div className="px-5 py-6 text-sm text-slate-500">
                    Aucune rotation configurée pour cette dimension.
                  </div>
                ) : (
                  <>
                    <div
                      role="row"
                      className={cn(
                        'grid items-center gap-3 bg-slate-50/60 px-5 py-2 text-[11px] font-semibold uppercase tracking-wider text-slate-600',
                        'dark:bg-slate-900/40 dark:text-slate-400',
                      )}
                      style={{ gridTemplateColumns: ROW_GRID }}
                    >
                      <div>Slug</div>
                      <div className="text-right">Poids</div>
                      <div className="text-right">Cooldown</div>
                      <div>État</div>
                      <div>Dernier usage</div>
                    </div>
                    <div className="divide-y divide-slate-100 dark:divide-slate-800">
                      {items.map((r) => (
                        <div
                          key={r.id}
                          role="row"
                          className="grid items-center gap-3 px-5 py-3 text-sm"
                          style={{ gridTemplateColumns: ROW_GRID }}
                        >
                          <div className="truncate font-mono text-xs">{r.slug}</div>
                          <div className="text-right tabular-nums">{r.weight}</div>
                          <div className="text-right tabular-nums text-xs">
                            {r.cooldown_seconds}s
                          </div>
                          <div>
                            <StatusPill tone={r.enabled ? 'success' : 'neutral'}>
                              {r.enabled ? 'ON' : 'OFF'}
                            </StatusPill>
                          </div>
                          <div className="text-xs text-slate-500">
                            {r.last_used_at
                              ? new Date(r.last_used_at).toLocaleString('fr-FR')
                              : 'Jamais'}
                          </div>
                        </div>
                      ))}
                    </div>
                  </>
                )}
              </Card>
            );
          })}
        </div>
      )}
    </div>
  );
}

