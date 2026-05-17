import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { AlertTriangle, Bot, FileText, ShieldAlert } from 'lucide-react';
import {
  Card,
  CardEyebrow,
  CardHeader,
  CardTitle,
  CompaniesTableSkeleton,
  Drawer,
  EmptyState,
  KpiCard,
  PageHeader,
  StatusPill,
  type StatusTone,
  cn,
} from '@/components/ui';
import { api } from '@/lib/api';

type RiskClass = 'prohibited' | 'high' | 'limited' | 'minimal';

interface AiActEntry {
  id: number;
  system_name: string;
  purpose: string;
  risk_class: RiskClass;
  provider?: string | null;
  model?: string | null;
  impact_assessment: Record<string, unknown>;
  dpia_url?: string | null;
  responsible?: string | null;
  status?: 'production' | 'staging' | 'sunset' | 'draft' | null;
  last_review_at?: string | null;
}

const RISK_TONE: Record<RiskClass, StatusTone> = {
  prohibited: 'danger',
  high: 'warning',
  limited: 'info',
  minimal: 'success',
};

const STATUS_TONE: Record<string, StatusTone> = {
  production: 'success',
  staging: 'info',
  sunset: 'warning',
  draft: 'neutral',
};

const GRID = 'minmax(220px,1.4fr) 110px minmax(180px,1fr) 130px minmax(140px,1fr) 130px';

export function AiActRegisterPage() {
  const [selected, setSelected] = useState<AiActEntry | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['ai-act-register'],
    queryFn: async () => (await api.get<{ data: AiActEntry[] }>('/ai-act/register')).data,
  });

  const rows = data?.data ?? [];

  const kpis = useMemo(() => {
    const total = rows.length;
    const high = rows.filter((r) => r.risk_class === 'high' || r.risk_class === 'prohibited').length;
    const prod = rows.filter((r) => r.status === 'production').length;
    const lastReview = rows
      .map((r) => r.last_review_at)
      .filter((d): d is string => !!d)
      .sort()
      .pop();
    return { total, high, prod, lastReview };
  }, [rows]);

  return (
    <div className="px-6 py-6">
      <PageHeader
        title="Registre AI Act"
        subtitle="Conformité UE 2024/1689 — systèmes IA + classification risque + supervision humaine documentés."
      />

      <div className="mb-4 rounded-2xl border-l-4 border-sky-400 bg-sky-50 p-4 text-sm text-sky-900 dark:border-sky-700 dark:bg-sky-950/40 dark:text-sky-200">
        <p className="flex items-start gap-2">
          <FileText className="mt-0.5 h-4 w-4 shrink-0" />
          <span>
            <strong>Article 9 AI Act</strong> — tout système classé <code>high</code> doit être documenté
            ici avec DPIA, mesures de mitigation, supervision humaine et révision annuelle.
          </span>
        </p>
      </div>

      <div className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <KpiCard tone="slate" label="Total registres" value={kpis.total} icon={<Bot className="h-4 w-4" />} />
        <KpiCard
          tone="rose"
          label="High risk"
          value={kpis.high}
          sublabel="dont prohibited"
          icon={<ShieldAlert className="h-4 w-4" />}
        />
        <KpiCard
          tone="emerald"
          label="En production"
          value={kpis.prod}
          sublabel={kpis.total ? `${Math.round((kpis.prod / kpis.total) * 100)}% des systèmes` : '—'}
        />
        <KpiCard
          tone="amber"
          label="Dernière revue"
          value={kpis.lastReview ? new Date(kpis.lastReview).toLocaleDateString('fr-FR') : '—'}
          sublabel="Annuelle obligatoire"
        />
      </div>

      {isLoading ? (
        <CompaniesTableSkeleton rows={3} />
      ) : rows.length === 0 ? (
        <EmptyState
          icon={<Bot className="h-10 w-10" />}
          title="Aucun système IA enregistré"
          description="Le LLM Router devrait apparaître ici après seed initial (AiActRegisterSeeder)."
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
            <div>Use case</div>
            <div>Risque</div>
            <div>Provider · Modèle</div>
            <div>Statut</div>
            <div>Responsable</div>
            <div>Dernière revue</div>
          </div>
          <div className="divide-y divide-slate-100 dark:divide-slate-800">
            {rows.map((e) => (
              <button
                key={e.id}
                onClick={() => setSelected(e)}
                role="row"
                className="grid w-full items-center gap-3 px-4 py-3 text-left text-sm transition hover:bg-slate-50/70 dark:hover:bg-slate-800/30"
                style={{ gridTemplateColumns: GRID }}
              >
                <div className="min-w-0">
                  <div className="truncate font-medium text-slate-900 dark:text-white">
                    {e.system_name}
                  </div>
                  <div className="truncate text-xs text-slate-500">{e.purpose}</div>
                </div>
                <div>
                  <StatusPill tone={RISK_TONE[e.risk_class]}>
                    {e.risk_class === 'high' || e.risk_class === 'prohibited' ? (
                      <AlertTriangle className="-ml-0.5 mr-0.5 h-3 w-3" />
                    ) : null}
                    {e.risk_class}
                  </StatusPill>
                </div>
                <div className="min-w-0">
                  <div className="truncate text-slate-700 dark:text-slate-200">
                    {e.provider ?? '—'}
                  </div>
                  <div className="truncate font-mono text-[11px] text-slate-500">
                    {e.model ?? '—'}
                  </div>
                </div>
                <div>
                  {e.status ? (
                    <StatusPill tone={STATUS_TONE[e.status] ?? 'neutral'}>{e.status}</StatusPill>
                  ) : (
                    <span className="text-xs text-slate-400">—</span>
                  )}
                </div>
                <div className="truncate text-slate-600 dark:text-slate-300">
                  {e.responsible ?? '—'}
                </div>
                <div className="text-xs text-slate-500">
                  {e.last_review_at
                    ? new Date(e.last_review_at).toLocaleDateString('fr-FR')
                    : '—'}
                </div>
              </button>
            ))}
          </div>
        </Card>
      )}

      <Drawer
        open={!!selected}
        onClose={() => setSelected(null)}
        title={selected?.system_name ?? 'Détail système IA'}
        width="lg"
      >
        {selected ? (
          <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-2">
              <StatusPill tone={RISK_TONE[selected.risk_class]}>
                Risque : {selected.risk_class.toUpperCase()}
              </StatusPill>
              {selected.status ? (
                <StatusPill tone={STATUS_TONE[selected.status] ?? 'neutral'}>
                  {selected.status}
                </StatusPill>
              ) : null}
            </div>

            <Card>
              <CardHeader>
                <div>
                  <CardEyebrow>Finalité</CardEyebrow>
                  <CardTitle className="mt-1 text-base">Objet du traitement</CardTitle>
                </div>
              </CardHeader>
              <p className="text-sm text-slate-700 dark:text-slate-200">{selected.purpose}</p>
            </Card>

            <div className="grid gap-3 sm:grid-cols-2">
              <Card>
                <CardEyebrow>Provider</CardEyebrow>
                <p className="mt-1 text-sm">{selected.provider ?? '—'}</p>
              </Card>
              <Card>
                <CardEyebrow>Modèle</CardEyebrow>
                <p className="mt-1 font-mono text-xs">{selected.model ?? '—'}</p>
              </Card>
              <Card>
                <CardEyebrow>Supervision humaine</CardEyebrow>
                <p className="mt-1 text-sm">
                  {(selected.impact_assessment as { human_oversight?: string })?.human_oversight ?? '—'}
                </p>
              </Card>
              <Card>
                <CardEyebrow>Route opt-out</CardEyebrow>
                <p className="mt-1 font-mono text-xs">
                  {(selected.impact_assessment as { opt_out_route?: string })?.opt_out_route ??
                    '/rgpd/requests'}
                </p>
              </Card>
            </div>

            <Card>
              <CardHeader>
                <div>
                  <CardEyebrow>Impact assessment</CardEyebrow>
                  <CardTitle className="mt-1 text-base">DPIA complet</CardTitle>
                </div>
              </CardHeader>
              <pre className="overflow-auto rounded-lg bg-slate-50 p-3 text-xs dark:bg-slate-800/60">
                {JSON.stringify(selected.impact_assessment, null, 2)}
              </pre>
            </Card>
          </div>
        ) : null}
      </Drawer>
    </div>
  );
}
