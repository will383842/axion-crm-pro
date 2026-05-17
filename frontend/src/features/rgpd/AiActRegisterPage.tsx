import { useQuery } from '@tanstack/react-query';
import { PageShell } from '@/components/ui/PageShell';
import { EmptyState } from '@/components/ui/EmptyState';
import { CompaniesTableSkeleton } from '@/components/ui/Skeleton';
import { api } from '@/lib/api';

interface AiActEntry {
  id: number; system_name: string; purpose: string;
  risk_class: 'prohibited'|'high'|'limited'|'minimal';
  provider?: string|null; model?: string|null;
  impact_assessment: Record<string, unknown>;
  dpia_url?: string|null;
}

const riskColors: Record<string, string> = {
  prohibited: 'bg-rose-100 text-rose-800',
  high:       'bg-amber-100 text-amber-800',
  limited:    'bg-sky-100 text-sky-800',
  minimal:    'bg-emerald-100 text-emerald-800',
};

export function AiActRegisterPage() {
  const { data, isLoading } = useQuery({
    queryKey: ['ai-act-register'],
    queryFn: async () => (await api.get<{ data: AiActEntry[] }>('/ai-act/register')).data,
  });

  return (
    <PageShell
      title="Registre AI Act"
      subtitle="Conformité UE 2024/1689 — systèmes IA + classification risque + supervision humaine documentés."
    >
      <div className="mb-4 rounded-lg bg-sky-50 p-4 text-sm text-sky-900">
        💡 <strong>Article 9 AI Act</strong> : tout système IA classé `high` doit être documenté ici avec
        DPIA, mesures de mitigation, supervision humaine, et révision annuelle.
      </div>

      {isLoading ? <CompaniesTableSkeleton rows={3} />
        : (data?.data ?? []).length === 0 ? (
          <EmptyState icon="🤖" title="Aucun système IA enregistré"
            description="Le LLM Router devrait apparaître ici après seed initial (AiActRegisterSeeder)." />
        ) : (
          <div className="space-y-4">
            {data!.data.map((e) => (
              <article key={e.id} className="rounded-xl border border-slate-200 bg-white p-5">
                <header className="mb-3 flex items-start justify-between gap-4">
                  <div>
                    <h2 className="text-lg font-semibold">{e.system_name}</h2>
                    <p className="mt-1 text-sm text-slate-600">{e.purpose}</p>
                  </div>
                  <span className={`shrink-0 rounded px-2 py-1 text-xs font-medium ${riskColors[e.risk_class]}`}>
                    Risque : {e.risk_class.toUpperCase()}
                  </span>
                </header>
                <dl className="grid grid-cols-2 gap-x-6 gap-y-2 border-t border-slate-100 pt-3 text-sm">
                  <div><dt className="text-xs uppercase text-slate-500">Provider</dt><dd>{e.provider ?? '—'}</dd></div>
                  <div><dt className="text-xs uppercase text-slate-500">Modèle</dt><dd className="font-mono text-xs">{e.model ?? '—'}</dd></div>
                  <div><dt className="text-xs uppercase text-slate-500">Supervision humaine</dt>
                    <dd>{(e.impact_assessment as { human_oversight?: string })?.human_oversight ?? '—'}</dd>
                  </div>
                  <div><dt className="text-xs uppercase text-slate-500">Route opt-out</dt>
                    <dd className="font-mono text-xs">{(e.impact_assessment as { opt_out_route?: string })?.opt_out_route ?? '/rgpd/requests'}</dd>
                  </div>
                </dl>
                <details className="mt-3">
                  <summary className="cursor-pointer text-xs uppercase text-slate-500">Impact assessment complet</summary>
                  <pre className="mt-2 overflow-auto rounded bg-slate-50 p-3 text-xs">
                    {JSON.stringify(e.impact_assessment, null, 2)}
                  </pre>
                </details>
              </article>
            ))}
          </div>
        )}
    </PageShell>
  );
}
