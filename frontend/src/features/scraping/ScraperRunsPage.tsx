import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { api } from '@/lib/api';
import {
  Button,
  Card,
  cn,
  Drawer,
  DropdownMenu,
  EmptyState,
  IconButton,
  KpiCard,
  LiveBadge,
  mapStatusToTone,
  Modal,
  PageHeader,
  SearchInput,
  Skeleton,
  StatusPill,
  Tabs,
  Toolbar,
  Tooltip,
  type MenuItem,
  type TabItem,
} from '@/components/ui';

// ---------------------------------------------------------------------------
// Types — alignés sur ScraperRunsController@index (response: { data: Run[] })
// ---------------------------------------------------------------------------
type RunStatus = 'pending' | 'running' | 'success' | 'completed' | 'failed' | 'cancelled' | string;

interface RequestPayload {
  type?: string;
  department?: string;
  naf?: string | null;
  size_category?: string | null;
  limit?: number;
  [k: string]: unknown;
}

interface ResponsePayload {
  companies_created?: number;
  companies_target?: number;
  [k: string]: unknown;
}

interface Run {
  id: number;
  source: string;
  status: RunStatus;
  workspace_id?: string | null;
  company_id?: number | null;
  latency_ms?: number | null;
  error?: string | null;
  started_at?: string | null;
  finished_at?: string | null;
  request_payload?: RequestPayload | null;
  response_payload?: ResponsePayload | null;
}

interface ApiList { data: Run[]; meta?: { total?: number; per_page?: number; current_page?: number; last_page?: number } }

type Filter = 'all' | 'running' | 'pending' | 'success' | 'failed' | 'cancelled';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
const PAGE_SIZE = 15;

function getZone(run: Run): string {
  const payload = run.request_payload ?? {};
  if (payload.department) return String(payload.department);
  return '—';
}

function getCompaniesStat(run: Run): { created: number; target: number } | null {
  const resp = run.response_payload ?? {};
  const req = run.request_payload ?? {};
  const created = typeof resp.companies_created === 'number' ? resp.companies_created : null;
  const target = typeof resp.companies_target === 'number'
    ? resp.companies_target
    : typeof req.limit === 'number' ? req.limit : null;
  if (created === null && target === null) return null;
  return { created: created ?? 0, target: target ?? 0 };
}

function isCancellable(s: RunStatus): boolean {
  return s === 'pending' || s === 'running';
}

function isRetryable(s: RunStatus): boolean {
  return s === 'failed' || s === 'cancelled';
}

function formatRelative(iso: string | null | undefined): string {
  if (!iso) return '—';
  const t = new Date(iso).getTime();
  if (Number.isNaN(t)) return '—';
  const diffMs = Date.now() - t;
  const sec = Math.floor(diffMs / 1000);
  if (sec < 5) return 'à l’instant';
  if (sec < 60) return `il y a ${sec}s`;
  const min = Math.floor(sec / 60);
  if (min < 60) return `il y a ${min}m`;
  const h = Math.floor(min / 60);
  if (h < 24) return `il y a ${h}h`;
  const d = Math.floor(h / 24);
  if (d < 7) return `il y a ${d}j`;
  return new Date(iso).toLocaleDateString('fr-FR');
}

function formatAbsolute(iso: string | null | undefined): string {
  if (!iso) return '—';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '—';
  return d.toLocaleString('fr-FR');
}

function statusLabel(s: RunStatus): string {
  const map: Record<string, string> = {
    pending: 'En attente',
    running: 'En cours',
    success: 'Terminé',
    completed: 'Terminé',
    failed: 'Échec',
    cancelled: 'Annulé',
  };
  return map[s] ?? s;
}

function isSuccess(s: RunStatus): boolean {
  return s === 'success' || s === 'completed';
}

// ---------------------------------------------------------------------------
// Icons (inline SVG, zéro dépendance)
// ---------------------------------------------------------------------------
function IconDownload() {
  return (
    <svg viewBox="0 0 20 20" className="h-3.5 w-3.5" fill="none" aria-hidden>
      <path d="M10 3v10m0 0l-3.5-3.5M10 13l3.5-3.5" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" />
      <path d="M4 16h12" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
    </svg>
  );
}
function IconStop() {
  return (
    <svg viewBox="0 0 20 20" className="h-3.5 w-3.5" fill="currentColor" aria-hidden>
      <rect x="6" y="6" width="8" height="8" rx="1.5" />
    </svg>
  );
}
function IconRetry() {
  return (
    <svg viewBox="0 0 20 20" className="h-3.5 w-3.5" fill="none" aria-hidden>
      <path d="M4 10a6 6 0 1 0 1.76-4.24" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
      <path d="M3 3v4h4" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}
function IconDots() {
  return (
    <svg viewBox="0 0 20 20" className="h-4 w-4" fill="currentColor" aria-hidden>
      <circle cx="5" cy="10" r="1.5" />
      <circle cx="10" cy="10" r="1.5" />
      <circle cx="15" cy="10" r="1.5" />
    </svg>
  );
}
function IconRocket() {
  return (
    <svg viewBox="0 0 24 24" className="h-8 w-8" fill="none" aria-hidden>
      <path d="M14 5l5 5-7 7-5-5 7-7zM5 19l3-3M14 5l1-2 4 4-2 1M5 19l-1 1" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

// ---------------------------------------------------------------------------
// CSV Export
// ---------------------------------------------------------------------------
function exportCsv(runs: Run[]) {
  const headers = ['id', 'source', 'zone', 'status', 'started_at', 'finished_at', 'latency_ms', 'error'];
  const rows = runs.map((r) => [
    r.id,
    r.source,
    getZone(r),
    r.status,
    r.started_at ?? '',
    r.finished_at ?? '',
    r.latency_ms ?? '',
    (r.error ?? '').replace(/[\r\n,;"]+/g, ' ').slice(0, 200),
  ]);
  const csv = [headers, ...rows].map((row) => row.map((c) => `"${String(c).replace(/"/g, '""')}"`).join(',')).join('\n');
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = `scraper-runs-${new Date().toISOString().slice(0, 10)}.csv`;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------
export function ScraperRunsPage() {
  const qc = useQueryClient();

  const { data, isLoading } = useQuery({
    queryKey: ['scraper-runs'],
    queryFn: async () => (await api.get<ApiList>('/scraper-runs?per_page=50')).data,
    refetchInterval: 10_000,
  });

  const runs = useMemo<Run[]>(() => data?.data ?? [], [data]);

  const [filter, setFilter] = useState<Filter>('all');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<Run | null>(null);
  const [confirmCancel, setConfirmCancel] = useState<Run | null>(null);

  // ----------------------------------------------------------------------
  // KPIs
  // ----------------------------------------------------------------------
  const counts = useMemo(() => {
    const c = { all: runs.length, running: 0, pending: 0, success: 0, failed: 0, cancelled: 0 };
    for (const r of runs) {
      if (r.status === 'running') c.running++;
      else if (r.status === 'pending') c.pending++;
      else if (isSuccess(r.status)) c.success++;
      else if (r.status === 'failed') c.failed++;
      else if (r.status === 'cancelled') c.cancelled++;
    }
    return c;
  }, [runs]);

  const successRate = useMemo(() => {
    const finished = counts.success + counts.failed + counts.cancelled;
    if (finished === 0) return 0;
    return Math.round((counts.success / finished) * 100);
  }, [counts]);

  // ----------------------------------------------------------------------
  // Filtrage
  // ----------------------------------------------------------------------
  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    return runs.filter((r) => {
      // Tab filter
      if (filter === 'running' && r.status !== 'running') return false;
      if (filter === 'pending' && r.status !== 'pending') return false;
      if (filter === 'success' && !isSuccess(r.status)) return false;
      if (filter === 'failed' && r.status !== 'failed') return false;
      if (filter === 'cancelled' && r.status !== 'cancelled') return false;
      // Search filter
      if (q) {
        const hay = `${r.source} ${getZone(r)} ${r.id}`.toLowerCase();
        if (!hay.includes(q)) return false;
      }
      return true;
    });
  }, [runs, filter, search]);

  const totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
  const currentPage = Math.min(page, totalPages);
  const pageStart = (currentPage - 1) * PAGE_SIZE;
  const pageRows = filtered.slice(pageStart, pageStart + PAGE_SIZE);

  // Reset page when filter/search change
  function handleFilter(f: Filter) {
    setFilter(f);
    setPage(1);
  }
  function handleSearch(v: string) {
    setSearch(v);
    setPage(1);
  }

  // ----------------------------------------------------------------------
  // Mutations
  // ----------------------------------------------------------------------
  const cancelMutation = useMutation({
    mutationFn: async (id: number) => {
      const resp = await api.post<Run>(`/scraper-runs/${id}/cancel`);
      return resp.data;
    },
    onSuccess: (run) => {
      toast.success(`Run #${run.id} annulé`);
      void qc.invalidateQueries({ queryKey: ['scraper-runs'] });
      setConfirmCancel(null);
    },
    onError: (err: unknown) => {
      const msg = extractApiMessage(err) ?? 'Annulation impossible';
      toast.error(msg);
    },
  });

  const retryMutation = useMutation({
    mutationFn: async (id: number) => {
      const resp = await api.post<Run>(`/scraper-runs/${id}/retry`);
      return { sourceId: id, newRun: resp.data };
    },
    onSuccess: ({ sourceId, newRun }) => {
      toast.success(`Run #${sourceId} relancé`, { description: `Nouveau run #${newRun.id}` });
      void qc.invalidateQueries({ queryKey: ['scraper-runs'] });
    },
    onError: (err: unknown) => {
      const msg = extractApiMessage(err) ?? 'Relance impossible';
      toast.error(msg);
    },
  });

  const tabs: Array<TabItem<Filter>> = [
    { id: 'all',       label: 'Tous',      count: counts.all },
    { id: 'running',   label: 'Running',   count: counts.running },
    { id: 'pending',   label: 'Pending',   count: counts.pending },
    { id: 'success',   label: 'Completed', count: counts.success },
    { id: 'failed',    label: 'Failed',    count: counts.failed },
    { id: 'cancelled', label: 'Cancelled', count: counts.cancelled },
  ];

  return (
    <div className="px-6 py-6" data-testid="scraper-runs-page">
      <PageHeader
        title="Scraper Runs"
        subtitle="Monitorage des jobs scraping en temps réel."
        badge={<LiveBadge label="Live" refreshLabel="refresh 10s" />}
        actions={
          <>
            <Button
              variant="secondary"
              size="sm"
              iconLeft={<IconDownload />}
              onClick={() => exportCsv(filtered)}
              disabled={filtered.length === 0}
              data-testid="scraper-runs-export"
            >
              Export CSV
            </Button>
          </>
        }
      />

      {/* KPI cards */}
      <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
        <KpiCard
          tone="sky"
          label="Total"
          value={counts.all}
          sublabel="runs sur la fenêtre"
        />
        <KpiCard
          tone="violet"
          label="Running"
          value={counts.running}
          sublabel={counts.pending > 0 ? `+ ${counts.pending} en attente` : 'en cours'}
        />
        <KpiCard
          tone="emerald"
          label="Success rate"
          value={`${successRate}%`}
          sublabel={`${counts.success} OK / ${counts.success + counts.failed + counts.cancelled} clos`}
          progress={successRate}
        />
        <KpiCard
          tone="rose"
          label="Failed"
          value={counts.failed}
          sublabel={counts.failed > 0 ? 'à retry' : 'aucun échec'}
        />
      </div>

      {/* Filters + search */}
      <div className="mb-4">
        <Tabs items={tabs} value={filter} onChange={handleFilter} variant="pills" />
      </div>

      <Toolbar
        left={
          <SearchInput
            value={search}
            onChange={handleSearch}
            placeholder="Rechercher source, dept, id…"
          />
        }
        right={
          <span className="text-xs text-slate-500 dark:text-slate-400 tabular-nums">
            {filtered.length} run{filtered.length > 1 ? 's' : ''}
          </span>
        }
      />

      {/* Table */}
      {isLoading ? (
        <RunsTableSkeleton />
      ) : filtered.length === 0 ? (
        <EmptyState
          icon={<IconRocket />}
          title={search || filter !== 'all' ? 'Aucun run ne correspond' : 'Aucun run pour l’instant'}
          description={
            search || filter !== 'all'
              ? 'Essaie un autre filtre ou réinitialise la recherche.'
              : 'Lance ton premier scrape depuis la page Couverture France.'
          }
          action={
            search || filter !== 'all' ? (
              <Button variant="secondary" size="sm" onClick={() => { setSearch(''); setFilter('all'); }}>
                Réinitialiser
              </Button>
            ) : (
              <a href="/coverage">
                <Button variant="primary" size="md">Lancer un scrape</Button>
              </a>
            )
          }
        />
      ) : (
        <Card padding="none" className="overflow-hidden" data-testid="scraper-runs-table">
          {/* Header (sticky) */}
          <div className="sticky top-0 z-10 grid grid-cols-[80px_minmax(120px,1fr)_80px_140px_140px_minmax(140px,1.2fr)_72px] gap-3 border-b border-slate-200/70 bg-slate-50/80 px-4 py-2.5 text-[10px] font-semibold uppercase tracking-wider text-slate-500 backdrop-blur dark:border-slate-800 dark:bg-slate-900/80 dark:text-slate-400">
            <div>ID</div>
            <div>Source</div>
            <div>Zone</div>
            <div>Statut</div>
            <div>Entreprises</div>
            <div>Démarré</div>
            <div className="text-right">Actions</div>
          </div>

          {/* Rows */}
          <div className="divide-y divide-slate-100 dark:divide-slate-800">
            {pageRows.map((run) => (
              <RunRow
                key={run.id}
                run={run}
                onOpenDetail={() => setSelected(run)}
                onAskCancel={() => setConfirmCancel(run)}
                onRetry={() => retryMutation.mutate(run.id)}
                retryLoading={retryMutation.isPending && retryMutation.variables === run.id}
              />
            ))}
          </div>

          {/* Pagination */}
          {totalPages > 1 ? (
            <div className="flex items-center justify-between border-t border-slate-100 bg-white/60 px-4 py-3 text-xs text-slate-600 dark:border-slate-800 dark:bg-slate-900/40 dark:text-slate-400">
              <span>
                Page {currentPage} / {totalPages}
                <span className="mx-2 text-slate-300 dark:text-slate-700">·</span>
                {filtered.length} runs
              </span>
              <div className="flex items-center gap-1">
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  disabled={currentPage <= 1}
                >
                  ◀ Précédent
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                  disabled={currentPage >= totalPages}
                >
                  Suivant ▶
                </Button>
              </div>
            </div>
          ) : null}
        </Card>
      )}

      {/* Drawer détail */}
      <Drawer
        open={!!selected}
        onClose={() => setSelected(null)}
        title={selected ? `Run #${selected.id}` : ''}
        width="lg"
      >
        {selected ? <RunDrawerContent run={selected} /> : null}
      </Drawer>

      {/* Confirm cancel modal */}
      <Modal
        open={!!confirmCancel}
        onClose={() => setConfirmCancel(null)}
        title="Annuler ce run ?"
        description={confirmCancel ? `Run #${confirmCancel.id} · source ${confirmCancel.source}. Les jobs en cours seront interrompus.` : ''}
        size="sm"
        footer={
          <>
            <Button variant="ghost" onClick={() => setConfirmCancel(null)} disabled={cancelMutation.isPending}>
              Garder
            </Button>
            <Button
              variant="destructive"
              loading={cancelMutation.isPending}
              onClick={() => confirmCancel && cancelMutation.mutate(confirmCancel.id)}
              data-testid="scraper-runs-cancel-confirm"
            >
              Annuler le run
            </Button>
          </>
        }
      />
    </div>
  );
}

// ---------------------------------------------------------------------------
// Menu items builder (extracted to avoid TS spread inference issues)
// ---------------------------------------------------------------------------
function buildMenuItems(args: {
  run: Run;
  cancellable: boolean;
  retryable: boolean;
  onOpenDetail: () => void;
  onAskCancel: () => void;
  onRetry: () => void;
}): MenuItem[] {
  const { run, cancellable, retryable, onOpenDetail, onAskCancel, onRetry } = args;
  const items: MenuItem[] = [
    { id: 'detail', label: 'Voir détails', onSelect: onOpenDetail },
    {
      id: 'copy',
      label: 'Copier l’ID',
      onSelect: () => {
        void navigator.clipboard?.writeText(String(run.id));
        toast.info(`ID #${run.id} copié`);
      },
    },
  ];
  if (cancellable || retryable) {
    items.push({ id: 'div', divider: true, label: '' });
  }
  if (cancellable) {
    items.push({ id: 'cancel', label: 'Annuler le run', destructive: true, onSelect: onAskCancel });
  }
  if (retryable) {
    items.push({ id: 'retry', label: 'Relancer', onSelect: onRetry });
  }
  return items;
}

// ---------------------------------------------------------------------------
// Row component
// ---------------------------------------------------------------------------
function RunRow({
  run,
  onOpenDetail,
  onAskCancel,
  onRetry,
  retryLoading,
}: {
  run: Run;
  onOpenDetail: () => void;
  onAskCancel: () => void;
  onRetry: () => void;
  retryLoading: boolean;
}) {
  const cancellable = isCancellable(run.status);
  const retryable = isRetryable(run.status);
  const stat = getCompaniesStat(run);

  return (
    <div
      role="button"
      tabIndex={0}
      onClick={onOpenDetail}
      onKeyDown={(e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          onOpenDetail();
        }
      }}
      data-testid={`run-row-${run.id}`}
      data-run-id={run.id}
      className={cn(
        'grid cursor-pointer grid-cols-[80px_minmax(120px,1fr)_80px_140px_140px_minmax(140px,1.2fr)_72px] items-center gap-3 px-4 py-3 text-sm transition-colors',
        'hover:bg-slate-50 dark:hover:bg-slate-800/40 focus:outline-none focus-visible:bg-slate-50 dark:focus-visible:bg-slate-800/40',
      )}
    >
      <div className="font-mono text-xs text-slate-500 tabular-nums dark:text-slate-400">#{run.id}</div>
      <div className="min-w-0 truncate font-medium text-slate-900 dark:text-white" title={run.source}>
        {run.source}
      </div>
      <div className="text-xs text-slate-600 tabular-nums dark:text-slate-400">{getZone(run)}</div>
      <div>
        <StatusPill tone={mapStatusToTone(run.status)} pulse={run.status === 'running'}>
          {statusLabel(run.status)}
        </StatusPill>
      </div>
      <div className="text-xs tabular-nums text-slate-700 dark:text-slate-300">
        {stat ? (
          <>
            <span className="font-medium">{stat.created}</span>
            <span className="text-slate-400">/{stat.target || '?'}</span>
          </>
        ) : (
          <span className="text-slate-400">—</span>
        )}
      </div>
      <Tooltip content={formatAbsolute(run.started_at)}>
        <span className="text-xs text-slate-600 dark:text-slate-400">{formatRelative(run.started_at)}</span>
      </Tooltip>
      <div className="flex items-center justify-end gap-1" onClick={(e) => e.stopPropagation()}>
        {cancellable ? (
          <Tooltip content="Annuler">
            <IconButton
              label="Annuler le run"
              size="sm"
              variant="ghost"
              onClick={onAskCancel}
              data-testid="run-cancel"
            >
              <IconStop />
            </IconButton>
          </Tooltip>
        ) : retryable ? (
          <Tooltip content="Relancer">
            <IconButton
              label="Relancer le run"
              size="sm"
              variant="ghost"
              onClick={onRetry}
              disabled={retryLoading}
              data-testid="run-retry"
            >
              <IconRetry />
            </IconButton>
          </Tooltip>
        ) : null}
        <DropdownMenu
          trigger={
            <IconButton label="Actions" size="sm" variant="ghost">
              <IconDots />
            </IconButton>
          }
          items={buildMenuItems({ run, cancellable, retryable, onOpenDetail, onAskCancel, onRetry })}
        />
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Drawer content
// ---------------------------------------------------------------------------
function RunDrawerContent({ run }: { run: Run }) {
  const stat = getCompaniesStat(run);
  const payload = run.request_payload ?? {};
  const response = run.response_payload ?? {};

  return (
    <div className="space-y-5 text-sm">
      <div className="flex items-center gap-3">
        <StatusPill tone={mapStatusToTone(run.status)} pulse={run.status === 'running'}>
          {statusLabel(run.status)}
        </StatusPill>
        <span className="font-mono text-xs text-slate-500 dark:text-slate-400">#{run.id}</span>
      </div>

      <div>
        <div className="text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
          Source · Zone
        </div>
        <div className="mt-1 text-base font-medium text-slate-900 dark:text-white">
          {run.source} <span className="text-slate-400 dark:text-slate-500">·</span> {getZone(run)}
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3">
        <DataItem label="Démarré" value={formatAbsolute(run.started_at)} hint={formatRelative(run.started_at)} />
        <DataItem label="Terminé" value={formatAbsolute(run.finished_at)} hint={run.finished_at ? formatRelative(run.finished_at) : 'en cours'} />
        <DataItem label="Latence" value={run.latency_ms ? `${run.latency_ms} ms` : '—'} />
        <DataItem
          label="Entreprises"
          value={stat ? `${stat.created} / ${stat.target || '?'}` : '—'}
          {...(stat && stat.target > 0 ? { hint: `${Math.round((stat.created / stat.target) * 100)}%` } : {})}
        />
      </div>

      {run.error ? (
        <div className="rounded-lg bg-rose-50 p-3 ring-1 ring-rose-200 dark:bg-rose-950/40 dark:ring-rose-900/50">
          <div className="text-[10px] font-semibold uppercase tracking-wider text-rose-700 dark:text-rose-300">
            Erreur
          </div>
          <pre className="mt-1 max-h-32 overflow-auto whitespace-pre-wrap break-words font-mono text-xs text-rose-800 dark:text-rose-200">
            {run.error}
          </pre>
        </div>
      ) : null}

      {Object.keys(payload).length > 0 ? (
        <details className="rounded-lg bg-slate-50 p-3 ring-1 ring-slate-200 dark:bg-slate-800/40 dark:ring-slate-800">
          <summary className="cursor-pointer text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
            Request payload
          </summary>
          <pre className="mt-2 max-h-48 overflow-auto whitespace-pre-wrap break-words font-mono text-[11px] text-slate-700 dark:text-slate-300">
            {JSON.stringify(payload, null, 2)}
          </pre>
        </details>
      ) : null}

      {Object.keys(response).length > 0 ? (
        <details className="rounded-lg bg-slate-50 p-3 ring-1 ring-slate-200 dark:bg-slate-800/40 dark:ring-slate-800">
          <summary className="cursor-pointer text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
            Response payload
          </summary>
          <pre className="mt-2 max-h-48 overflow-auto whitespace-pre-wrap break-words font-mono text-[11px] text-slate-700 dark:text-slate-300">
            {JSON.stringify(response, null, 2)}
          </pre>
        </details>
      ) : null}
    </div>
  );
}

function DataItem({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <div className="rounded-lg bg-slate-50 p-2.5 ring-1 ring-slate-100 dark:bg-slate-800/40 dark:ring-slate-800">
      <div className="text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{label}</div>
      <div className="mt-0.5 truncate font-medium text-slate-900 dark:text-white" title={value}>{value}</div>
      {hint ? <div className="text-[11px] text-slate-500 dark:text-slate-400">{hint}</div> : null}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Skeleton
// ---------------------------------------------------------------------------
function RunsTableSkeleton() {
  return (
    <Card padding="none" className="overflow-hidden" aria-busy="true">
      <div className="border-b border-slate-200/70 px-4 py-2.5 dark:border-slate-800">
        <Skeleton className="h-3.5 w-32" />
      </div>
      <div className="divide-y divide-slate-100 dark:divide-slate-800">
        {Array.from({ length: 8 }).map((_, i) => (
          <div key={i} className="grid grid-cols-7 items-center gap-3 px-4 py-3">
            <Skeleton className="h-3 w-12" />
            <Skeleton className="h-3.5 w-24" />
            <Skeleton className="h-3 w-10" />
            <Skeleton className="h-5 w-20 rounded-full" />
            <Skeleton className="h-3 w-14" />
            <Skeleton className="h-3 w-20" />
            <Skeleton className="h-7 w-16 justify-self-end" />
          </div>
        ))}
      </div>
    </Card>
  );
}

// ---------------------------------------------------------------------------
// Helpers (axios error → string)
// ---------------------------------------------------------------------------
function extractApiMessage(err: unknown): string | null {
  if (typeof err === 'object' && err !== null) {
    const e = err as { response?: { data?: { message?: string; error?: string } } };
    return e.response?.data?.message ?? e.response?.data?.error ?? null;
  }
  return null;
}
