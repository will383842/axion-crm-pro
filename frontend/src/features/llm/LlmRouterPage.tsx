import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Activity, Bot, FileText, KeyRound } from 'lucide-react';
import {
  Card,
  CardEyebrow,
  CardHeader,
  CardTitle,
  CompaniesTableSkeleton,
  EmptyState,
  KpiCard,
  PageHeader,
  StatusPill,
  Tabs,
  type TabItem,
  cn,
} from '@/components/ui';
import { api } from '@/lib/api';

interface UseCase {
  id: number;
  slug: string;
  description?: string | null;
  primary_provider: string;
  model: string;
  fallback_chain: string[];
  prompt_version: number;
  cost_cap_eur: number;
  enabled: boolean;
  last_used_at?: string | null;
  cost_per_1k?: number | null;
}

interface UsageSummary {
  total_eur: number;
  tokens_in?: number;
  tokens_out?: number;
  by_provider: Record<string, number>;
  by_use_case: Record<string, { cost_eur: number; tokens_in: number; tokens_out: number; calls: number }>;
}

type TabKey = 'use_cases' | 'providers' | 'prompts' | 'usage';

const TABS: Array<TabItem<TabKey>> = [
  { id: 'use_cases', label: 'Use cases', icon: <Bot className="h-3.5 w-3.5" /> },
  { id: 'providers', label: 'Providers', icon: <KeyRound className="h-3.5 w-3.5" /> },
  { id: 'prompts', label: 'Prompts', icon: <FileText className="h-3.5 w-3.5" /> },
  { id: 'usage', label: 'Usage 30j', icon: <Activity className="h-3.5 w-3.5" /> },
];

const PROVIDERS = ['anthropic', 'openai', 'mistral', 'groq', 'together'] as const;

const USE_CASES_GRID = 'minmax(180px,1.4fr) 130px minmax(160px,1fr) minmax(180px,1.2fr) 110px 100px';

export function LlmRouterPage() {
  const [tab, setTab] = useState<TabKey>('use_cases');

  const useCases = useQuery({
    queryKey: ['llm', 'use-cases'],
    queryFn: async () => (await api.get<{ data: UseCase[] }>('/llm/use-cases')).data.data,
    enabled: tab === 'use_cases' || tab === 'prompts',
  });

  const usage = useQuery({
    queryKey: ['llm', 'usage-summary'],
    queryFn: async () => (await api.get<{ summary: UsageSummary }>('/llm/usage/summary')).data.summary,
    enabled: tab === 'usage',
  });

  const useCasesData = useCases.data ?? [];

  const tabItems = useMemo<Array<TabItem<TabKey>>>(() => {
    return TABS.map((t) =>
      t.id === 'use_cases' && useCasesData.length > 0
        ? { ...t, count: useCasesData.length }
        : t,
    );
  }, [useCasesData.length]);

  return (
    <div className="px-6 py-6">
      <PageHeader
        title="LLM Router"
        subtitle="9 use cases × 5 providers + fallback chain + cost tracking + idempotency cache 24h."
      />

      <div className="mb-6">
        <Tabs items={tabItems} value={tab} onChange={setTab} />
      </div>

      {tab === 'use_cases' && (
        useCases.isLoading ? (
          <CompaniesTableSkeleton rows={5} />
        ) : useCasesData.length === 0 ? (
          <EmptyState
            icon={<Bot className="h-10 w-10" />}
            title="Aucun use case configuré"
            description="Les use cases LLM sont seedés au déploiement initial (LlmUseCaseSeeder)."
          />
        ) : (
          <Card padding="none" className="overflow-hidden">
            <div
              role="row"
              className={cn(
                'sticky top-0 z-10 grid items-center gap-3 border-b border-slate-200 bg-slate-50/80 px-4 py-3 text-[11px] font-semibold uppercase tracking-wider text-slate-600 backdrop-blur',
                'dark:border-slate-800 dark:bg-slate-900/80 dark:text-slate-400',
              )}
              style={{ gridTemplateColumns: USE_CASES_GRID }}
            >
              <div>Use case</div>
              <div>Provider</div>
              <div>Modèle</div>
              <div>Fallback chain</div>
              <div className="text-right">Cost cap €</div>
              <div>État</div>
            </div>
            <div className="divide-y divide-slate-100 dark:divide-slate-800">
              {useCasesData.map((u) => (
                <div
                  key={u.id}
                  role="row"
                  className="grid items-center gap-3 px-4 py-3 text-sm transition hover:bg-slate-50/70 dark:hover:bg-slate-800/30"
                  style={{ gridTemplateColumns: USE_CASES_GRID }}
                >
                  <div className="truncate font-mono text-xs text-slate-700 dark:text-slate-200">
                    {u.slug}
                  </div>
                  <div className="capitalize text-slate-700 dark:text-slate-200">
                    {u.primary_provider}
                  </div>
                  <div className="truncate font-mono text-xs text-slate-500">{u.model}</div>
                  <div className="truncate text-xs text-slate-500">
                    {(u.fallback_chain ?? []).join(' → ') || '—'}
                  </div>
                  <div className="text-right tabular-nums">{u.cost_cap_eur}</div>
                  <div>
                    <StatusPill tone={u.enabled ? 'success' : 'neutral'}>
                      {u.enabled ? 'ON' : 'OFF'}
                    </StatusPill>
                  </div>
                </div>
              ))}
            </div>
          </Card>
        )
      )}

      {tab === 'providers' && (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {PROVIDERS.map((p) => (
            <Card key={p}>
              <CardHeader>
                <div>
                  <CardEyebrow>Provider</CardEyebrow>
                  <CardTitle className="mt-1 text-base capitalize">{p}</CardTitle>
                </div>
                <StatusPill tone="success">Actif</StatusPill>
              </CardHeader>
              <div className="space-y-2 text-sm">
                <div className="flex items-center justify-between">
                  <span className="text-slate-500">Clé API</span>
                  <code className="rounded bg-slate-100 px-2 py-0.5 text-xs dark:bg-slate-800">
                    ••••••••
                  </code>
                </div>
                <div className="flex items-center justify-between">
                  <span className="text-slate-500">Use cases routés</span>
                  <span className="font-medium">
                    {useCasesData.filter((u) => u.primary_provider === p).length}
                  </span>
                </div>
              </div>
            </Card>
          ))}
        </div>
      )}

      {tab === 'prompts' && (
        useCases.isLoading ? (
          <CompaniesTableSkeleton rows={5} />
        ) : useCasesData.length === 0 ? (
          <EmptyState
            icon={<FileText className="h-10 w-10" />}
            title="Aucun prompt"
            description="Les templates prompts sont versionnés en DB (prompt_template_versions)."
          />
        ) : (
          <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            {useCasesData.map((u) => (
              <Card key={u.id} hover>
                <CardHeader>
                  <div className="min-w-0">
                    <CardEyebrow>{u.primary_provider}</CardEyebrow>
                    <CardTitle className="mt-1 truncate font-mono text-xs">{u.slug}</CardTitle>
                  </div>
                  <StatusPill tone="info">v{u.prompt_version}</StatusPill>
                </CardHeader>
                <p className="line-clamp-2 text-sm text-slate-600 dark:text-slate-300">
                  {u.description ?? '—'}
                </p>
                <div className="mt-3 flex items-center justify-between text-xs text-slate-500">
                  <span>Modèle</span>
                  <code className="rounded bg-slate-100 px-1.5 py-0.5 dark:bg-slate-800">
                    {u.model}
                  </code>
                </div>
              </Card>
            ))}
          </div>
        )
      )}

      {tab === 'usage' && (
        usage.isLoading ? (
          <CompaniesTableSkeleton rows={5} />
        ) : (
          <div className="space-y-4">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <KpiCard
                tone="violet"
                label="Coût total 30j"
                value={`${(usage.data?.total_eur ?? 0).toFixed(2)} €`}
                icon={<Activity className="h-4 w-4" />}
              />
              <KpiCard
                tone="sky"
                label="Tokens in 24h"
                value={(usage.data?.tokens_in ?? 0).toLocaleString('fr-FR')}
                sublabel="estimé"
              />
              <KpiCard
                tone="emerald"
                label="Tokens out 24h"
                value={(usage.data?.tokens_out ?? 0).toLocaleString('fr-FR')}
                sublabel="estimé"
              />
              <KpiCard
                tone="amber"
                label="Use cases actifs"
                value={Object.keys(usage.data?.by_use_case ?? {}).length}
                sublabel="dernières 30j"
              />
            </div>

            <Card>
              <CardHeader>
                <div>
                  <CardEyebrow>Répartition</CardEyebrow>
                  <CardTitle className="mt-1 text-base">Coût par provider</CardTitle>
                </div>
              </CardHeader>
              <div className="space-y-2">
                {Object.entries(usage.data?.by_provider ?? {}).length === 0 ? (
                  <p className="text-sm text-slate-500">Aucune donnée — pas encore d'appel facturé.</p>
                ) : (
                  Object.entries(usage.data?.by_provider ?? {}).map(([p, eur]) => {
                    const total = usage.data?.total_eur ?? 0;
                    const pct = total ? Math.round((eur / total) * 100) : 0;
                    return (
                      <div key={p}>
                        <div className="mb-1 flex justify-between text-sm">
                          <span className="capitalize text-slate-700 dark:text-slate-200">{p}</span>
                          <span className="tabular-nums">
                            {eur.toFixed(2)} € · {pct}%
                          </span>
                        </div>
                        <div className="h-1.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                          <div
                            className="h-full rounded-full bg-gradient-to-r from-violet-500 to-fuchsia-600"
                            style={{ width: `${Math.max(2, pct)}%` }}
                          />
                        </div>
                      </div>
                    );
                  })
                )}
              </div>
            </Card>
          </div>
        )
      )}
    </div>
  );
}
