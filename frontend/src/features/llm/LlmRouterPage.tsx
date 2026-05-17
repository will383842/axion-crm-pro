import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { PageShell } from '@/components/ui/PageShell';
import { CompaniesTableSkeleton } from '@/components/ui/Skeleton';
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
}

interface UsageSummary {
  total_eur: number;
  by_provider: Record<string, number>;
  by_use_case: Record<string, { cost_eur: number; tokens_in: number; tokens_out: number; calls: number }>;
}

export function LlmRouterPage() {
  const [tab, setTab] = useState<'use_cases' | 'providers' | 'prompts' | 'usage'>('use_cases');

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

  return (
    <PageShell
      title="LLM Router"
      subtitle="9 use cases × 5 providers + fallback chain + cost tracking + idempotency cache 24h."
    >
      <div className="mb-6 flex gap-2 border-b border-slate-200">
        {([['use_cases','Use cases'],['providers','Providers'],['prompts','Prompts'],['usage','Usage 30j']] as const).map(([key,label]) => (
          <button
            key={key}
            onClick={() => setTab(key)}
            className={`-mb-px border-b-2 px-3 py-2 text-sm ${tab === key ? 'border-brand-600 font-medium text-brand-700' : 'border-transparent text-slate-700 hover:border-slate-300'}`}
          >
            {label}
          </button>
        ))}
      </div>

      {tab === 'use_cases' && (
        useCases.isLoading ? <CompaniesTableSkeleton rows={5} />
        : <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <table className="min-w-full divide-y divide-slate-200 text-sm">
              <thead className="bg-slate-50 text-left text-xs uppercase text-slate-600">
                <tr><th className="px-4 py-3">Slug</th><th>Provider</th><th>Modèle</th><th>Fallback</th><th>Cost cap €</th><th>État</th></tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {(useCases.data ?? []).map((u) => (
                  <tr key={u.id}>
                    <td className="px-4 py-2 font-mono text-xs">{u.slug}</td>
                    <td className="px-4 py-2">{u.primary_provider}</td>
                    <td className="px-4 py-2 text-xs text-slate-600">{u.model}</td>
                    <td className="px-4 py-2 text-xs text-slate-500">{(u.fallback_chain ?? []).join(' → ')}</td>
                    <td className="px-4 py-2 tabular-nums">{u.cost_cap_eur}</td>
                    <td className="px-4 py-2">
                      <span className={`rounded px-2 py-0.5 text-xs ${u.enabled ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700'}`}>
                        {u.enabled ? 'ON' : 'OFF'}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
      )}

      {tab === 'providers' && (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {['anthropic','openai','mistral','groq','together'].map((p) => (
            <div key={p} className="rounded-xl border border-slate-200 bg-white p-4">
              <p className="text-sm font-medium capitalize">{p}</p>
              <p className="mt-1 text-xs text-slate-500">Configuré via env var</p>
            </div>
          ))}
        </div>
      )}

      {tab === 'prompts' && (
        <div className="rounded-xl border border-slate-200 bg-white p-5">
          <p className="text-sm text-slate-600">Templates versionnés stockés en DB (`prompt_template_versions`).</p>
          <p className="mt-2 text-xs text-slate-500">9 use cases prompt v1 seedés. Édition Sprint 15.</p>
        </div>
      )}

      {tab === 'usage' && (
        <div>
          {usage.isLoading ? <CompaniesTableSkeleton rows={5} />
          : <div className="grid gap-4 lg:grid-cols-3">
              <div className="rounded-xl border border-slate-200 bg-white p-5">
                <p className="text-xs uppercase text-slate-500">Coût total 30j</p>
                <p className="mt-2 text-3xl font-semibold tabular-nums">{(usage.data?.total_eur ?? 0).toFixed(2)} €</p>
              </div>
              <div className="rounded-xl border border-slate-200 bg-white p-5 lg:col-span-2">
                <p className="mb-2 text-xs uppercase text-slate-500">Par provider</p>
                {Object.entries(usage.data?.by_provider ?? {}).map(([p,eur]) => (
                  <div key={p} className="mb-1 flex justify-between text-sm">
                    <span>{p}</span><span className="tabular-nums">{eur.toFixed(2)} €</span>
                  </div>
                ))}
              </div>
            </div>}
        </div>
      )}
    </PageShell>
  );
}
