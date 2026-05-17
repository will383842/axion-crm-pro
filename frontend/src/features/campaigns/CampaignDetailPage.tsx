/**
 * Sprint 19.7 — CampaignDetailPage.
 *
 * UX live ultra-claire :
 *  - Breadcrumbs + status pill pulsé
 *  - Header gradient title + actions (pause / cancel / resume)
 *  - Progress bars (entreprises + temps)
 *  - 4 KpiCards : companies / runs / req per min / ETA
 *  - 5 tabs : Suivi temps réel | Sources | Zones | Runs | Configuration
 *  - Refresh 5s via refetchInterval
 */
import { useState } from 'react';
import { Link, useParams, useNavigate } from '@tanstack/react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import {
  Pause, Play, X, Activity, Database, Map as MapIcon,
  Settings as SettingsIcon, ListChecks, Building2, Clock,
  Gauge, Timer, Copy,
} from 'lucide-react';
import { api } from '@/lib/api';
import {
  Button,
  Card,
  cn,
  KpiCard,
  LiveBadge,
  PageHeader,
  Spinner,
  StatusPill,
  Tabs,
  type TabItem,
} from '@/components/ui';
import {
  ALL_SOURCES,
  PAUSED_REASON_LABEL,
  STATUS_LABEL,
  statusToTone,
  type Campaign,
  type CampaignStatsResponse,
} from './types';

type DetailTab = 'live' | 'sources' | 'zones' | 'runs' | 'config';

const TABS: Array<TabItem<DetailTab>> = [
  { id: 'live',    label: 'Suivi temps réel', icon: <Activity className="h-3.5 w-3.5" /> },
  { id: 'sources', label: 'Sources',          icon: <Database className="h-3.5 w-3.5" /> },
  { id: 'zones',   label: 'Zones',            icon: <MapIcon className="h-3.5 w-3.5" /> },
  { id: 'runs',    label: 'Runs',             icon: <ListChecks className="h-3.5 w-3.5" /> },
  { id: 'config',  label: 'Configuration',    icon: <SettingsIcon className="h-3.5 w-3.5" /> },
];

export function CampaignDetailPage() {
  const params = useParams({ strict: false });
  const campaignId = (params as { campaignId?: string }).campaignId;
  const id = Number(campaignId);
  const navigate = useNavigate();
  const qc = useQueryClient();
  const [tab, setTab] = useState<DetailTab>('live');

  const { data: campaign, isLoading } = useQuery({
    queryKey: ['campaign', id],
    queryFn: async () => (await api.get<Campaign>(`/campaigns/${id}`)).data,
    refetchInterval: 5_000,
    enabled: Number.isFinite(id) && id > 0,
  });

  const { data: stats } = useQuery({
    queryKey: ['campaign-stats', id],
    queryFn: async () => (await api.get<CampaignStatsResponse>(`/campaigns/${id}/stats`)).data,
    refetchInterval: 5_000,
    enabled: Number.isFinite(id) && id > 0,
  });

  const pauseMutation = useMutation({
    mutationFn: async () => (await api.post<Campaign>(`/campaigns/${id}/pause`)).data,
    onSuccess: () => { toast.success('Campagne mise en pause'); void qc.invalidateQueries({ queryKey: ['campaign', id] }); },
    onError: (e) => toast.error(extractApiMessage(e) ?? 'Pause impossible'),
  });
  const resumeMutation = useMutation({
    mutationFn: async () => (await api.post<Campaign>(`/campaigns/${id}/resume`)).data,
    onSuccess: () => { toast.success('Campagne reprise'); void qc.invalidateQueries({ queryKey: ['campaign', id] }); },
    onError: (e) => toast.error(extractApiMessage(e) ?? 'Reprise impossible'),
  });
  const cancelMutation = useMutation({
    mutationFn: async () => (await api.post<Campaign>(`/campaigns/${id}/cancel`)).data,
    onSuccess: () => { toast.success('Campagne annulée'); void qc.invalidateQueries({ queryKey: ['campaign', id] }); },
    onError: (e) => toast.error(extractApiMessage(e) ?? 'Annulation impossible'),
  });
  const startMutation = useMutation({
    mutationFn: async () => (await api.post<Campaign>(`/campaigns/${id}/start`)).data,
    onSuccess: () => { toast.success('Campagne lancée'); void qc.invalidateQueries({ queryKey: ['campaign', id] }); },
    onError: (e) => toast.error(extractApiMessage(e) ?? 'Démarrage impossible'),
  });

  if (isLoading || !campaign) {
    return (
      <div className="flex h-[60vh] items-center justify-center"><Spinner /></div>
    );
  }

  const isLive = campaign.status === 'running';
  const isPaused = campaign.status === 'paused';
  const isCompleted = campaign.status === 'completed' || campaign.status === 'cancelled' || campaign.status === 'failed';

  const companiesPercent = Math.min(100, Math.round((campaign.companies_created / Math.max(1, campaign.max_companies)) * 100));
  const durationPercent = Math.min(100, Math.round((campaign.elapsed_minutes / Math.max(1, campaign.max_duration_minutes)) * 100));

  // ETA basé sur companies_per_minute live
  const companiesPerMin = stats?.companies_per_minute ?? 0;
  const remainingCompanies = Math.max(0, campaign.max_companies - campaign.companies_created);
  const etaMin = companiesPerMin > 0
    ? Math.min(campaign.remaining_minutes, Math.ceil(remainingCompanies / companiesPerMin))
    : campaign.remaining_minutes;

  return (
    <div className="px-6 py-6">
      <PageHeader
        breadcrumbs={[
          { label: 'Campagnes', to: '/campaigns' },
          { label: campaign.name },
        ]}
        badge={
          <div className="flex items-center gap-2">
            <StatusPill tone={statusToTone(campaign.status)} pulse={isLive}>
              {STATUS_LABEL[campaign.status]}
            </StatusPill>
            {isPaused && campaign.paused_reason ? (
              <span className="text-[11px] text-amber-700 dark:text-amber-300">
                · {PAUSED_REASON_LABEL[campaign.paused_reason] ?? campaign.paused_reason}
              </span>
            ) : null}
            {isLive ? <LiveBadge label="Live" refreshLabel="actualisé 5s" /> : null}
          </div>
        }
        title={campaign.name}
        subtitle={
          campaign.started_at ? (
            <>
              Lancée {formatRelative(campaign.started_at)} · {campaign.elapsed_minutes} min écoulées
              {isLive ? ` · reste ${campaign.remaining_minutes} min` : ''}
              {' · '}
              <span className="font-medium text-slate-700 dark:text-slate-300">
                {campaign.companies_created}/{campaign.max_companies} entreprises
              </span>
            </>
          ) : (
            campaign.description ?? 'Pas encore démarrée'
          )
        }
        actions={
          <div className="flex items-center gap-2">
            {campaign.can_start ? (
              <Button variant="primary" size="md" iconLeft={<Play className="h-4 w-4" />} loading={startMutation.isPending} onClick={() => startMutation.mutate()}>
                Lancer
              </Button>
            ) : null}
            {campaign.can_pause ? (
              <Button variant="secondary" size="md" iconLeft={<Pause className="h-4 w-4" />} loading={pauseMutation.isPending} onClick={() => pauseMutation.mutate()}>
                Pause
              </Button>
            ) : null}
            {campaign.can_resume ? (
              <Button variant="primary" size="md" iconLeft={<Play className="h-4 w-4" />} loading={resumeMutation.isPending} onClick={() => resumeMutation.mutate()}>
                Reprendre
              </Button>
            ) : null}
            {campaign.can_cancel ? (
              <Button variant="ghost" size="md" iconLeft={<X className="h-4 w-4" />} loading={cancelMutation.isPending} onClick={() => cancelMutation.mutate()}>
                Annuler
              </Button>
            ) : null}
          </div>
        }
      />

      {/* Progress bars */}
      <Card padding="md" className="mb-4">
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <div>
            <div className="mb-1 flex items-baseline justify-between text-xs">
              <span className="inline-flex items-center gap-1 font-semibold text-slate-700 dark:text-slate-300">
                <Building2 className="h-3.5 w-3.5" /> Entreprises créées
              </span>
              <span className="font-mono tabular-nums text-slate-900 dark:text-white">
                {campaign.companies_created} / {campaign.max_companies}
                <span className="ml-1 text-xs text-slate-500">({companiesPercent}%)</span>
              </span>
            </div>
            <div className="h-3 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
              <div
                className={cn(
                  'h-full rounded-full bg-gradient-to-r transition-[width] duration-500',
                  isLive ? 'from-emerald-500 to-teal-600' : 'from-slate-400 to-slate-500',
                )}
                style={{ width: `${companiesPercent}%` }}
              />
            </div>
          </div>
          <div>
            <div className="mb-1 flex items-baseline justify-between text-xs">
              <span className="inline-flex items-center gap-1 font-semibold text-slate-700 dark:text-slate-300">
                <Clock className="h-3.5 w-3.5" /> Temps écoulé
              </span>
              <span className="font-mono tabular-nums text-slate-900 dark:text-white">
                {campaign.elapsed_minutes} / {campaign.max_duration_minutes} min
                <span className="ml-1 text-xs text-slate-500">({durationPercent}%)</span>
              </span>
            </div>
            <div className="h-3 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
              <div
                className="h-full rounded-full bg-gradient-to-r from-sky-500 to-blue-600 transition-[width] duration-500"
                style={{ width: `${durationPercent}%` }}
              />
            </div>
          </div>
        </div>
      </Card>

      {/* KPIs */}
      <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
        <KpiCard
          tone="emerald"
          label="Entreprises créées"
          value={campaign.companies_created.toLocaleString('fr-FR')}
          sublabel={isLive && companiesPerMin > 0 ? `+${companiesPerMin}/min` : `${campaign.companies_remaining} restantes`}
        />
        <KpiCard
          tone="violet"
          label="Runs"
          value={`${campaign.runs_completed}/${campaign.runs_total}`}
          sublabel={`${Math.round((campaign.runs_completed / Math.max(1, campaign.runs_total)) * 100)}% terminés`}
          progress={Math.round((campaign.runs_completed / Math.max(1, campaign.runs_total)) * 100)}
        />
        <KpiCard
          tone="amber"
          label="Débit max"
          value={`${campaign.max_requests_per_minute}/min`}
          sublabel="anti-blacklist"
          icon={<Gauge className="h-4 w-4" />}
        />
        <KpiCard
          tone="sky"
          label={isCompleted ? 'Terminée' : 'ETA fin'}
          value={isCompleted ? '—' : etaMin >= 60 ? `${Math.floor(etaMin / 60)}h ${etaMin % 60}m` : `${etaMin}m`}
          sublabel={isCompleted ? 'campagne close' : 'estimation'}
          icon={<Timer className="h-4 w-4" />}
        />
      </div>

      {/* Tabs */}
      <div className="mb-4">
        <Tabs items={TABS} value={tab} onChange={setTab} variant="pills" />
      </div>

      {tab === 'live' ? (
        <TabLiveTimeline events={stats?.last_events ?? []} />
      ) : null}
      {tab === 'sources' ? (
        <TabSources campaign={campaign} perSource={stats?.per_source ?? []} />
      ) : null}
      {tab === 'zones' ? (
        <TabZones campaign={campaign} />
      ) : null}
      {tab === 'runs' ? (
        <TabRuns runs={stats?.last_events ?? []} />
      ) : null}
      {tab === 'config' ? (
        <TabConfig
          campaign={campaign}
          onDuplicate={() => { void navigate({ to: '/campaigns/new' }); }}
        />
      ) : null}
    </div>
  );
}

// ---------------------------------------------------------------------------
// TAB Live — timeline d'événements
// ---------------------------------------------------------------------------
function TabLiveTimeline({ events }: { events: CampaignStatsResponse['last_events'] }) {
  if (events.length === 0) {
    return (
      <Card padding="lg" className="text-center text-sm text-slate-500 dark:text-slate-400">
        Aucun événement pour l’instant. La campagne va générer des runs dans quelques secondes.
      </Card>
    );
  }
  return (
    <Card padding="md">
      <ol className="space-y-2">
        {events.map((ev) => {
          const ts = ev.finished_at ?? ev.started_at;
          const isErr = ev.status === 'failed';
          const isOk = ev.status === 'success' || ev.status === 'completed';
          return (
            <li key={ev.id} className="flex items-start gap-3 border-l-2 border-slate-100 pl-3 dark:border-slate-800">
              <span className={cn(
                'mt-1 inline-block h-2 w-2 shrink-0 rounded-full',
                isErr ? 'bg-rose-500' : isOk ? 'bg-emerald-500' : ev.status === 'running' ? 'bg-sky-500 axion-pulse-dot' : 'bg-slate-400',
              )} />
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-baseline gap-2 text-xs">
                  <span className="font-mono tabular-nums text-slate-500 dark:text-slate-400">
                    {ts ? new Date(ts).toLocaleTimeString('fr-FR') : '—'}
                  </span>
                  <StatusPill tone={isErr ? 'danger' : isOk ? 'success' : ev.status === 'running' ? 'running' : 'pending'}>
                    {ev.status}
                  </StatusPill>
                  <span className="font-medium text-slate-700 dark:text-slate-300">{ev.source}</span>
                  <Link
                    to="/scraper-runs"
                    className="ml-auto text-[11px] text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-white"
                  >
                    Run #{ev.id} →
                  </Link>
                </div>
                {ev.error ? (
                  <div className="mt-0.5 truncate text-xs text-rose-600 dark:text-rose-400" title={ev.error}>
                    {ev.error}
                  </div>
                ) : null}
              </div>
            </li>
          );
        })}
      </ol>
    </Card>
  );
}

// ---------------------------------------------------------------------------
// TAB Sources
// ---------------------------------------------------------------------------
function TabSources({ campaign, perSource }: { campaign: Campaign; perSource: CampaignStatsResponse['per_source'] }) {
  return (
    <Card padding="none" className="overflow-hidden">
      <table className="w-full text-sm">
        <thead className="bg-slate-50 text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
          <tr>
            <th className="px-4 py-2 text-left">Source</th>
            <th className="px-4 py-2 text-left">Statut</th>
            <th className="px-4 py-2 text-right">Runs</th>
            <th className="px-4 py-2 text-right">Succès</th>
            <th className="px-4 py-2 text-right">Échecs</th>
            <th className="px-4 py-2 text-right">Entreprises</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
          {campaign.sources.map((s) => {
            const meta = ALL_SOURCES.find((m) => m.id === s);
            const stat = perSource.find((p) => p.source === s);
            const running = (stat?.running ?? 0) > 0;
            return (
              <tr key={s}>
                <td className="px-4 py-2 font-medium text-slate-900 dark:text-white">{meta?.label ?? s}</td>
                <td className="px-4 py-2">
                  {running ? (
                    <StatusPill tone="running" pulse>En cours</StatusPill>
                  ) : (stat?.total ?? 0) > 0 ? (
                    <StatusPill tone="success">Terminé</StatusPill>
                  ) : (
                    <StatusPill tone="pending">En attente</StatusPill>
                  )}
                </td>
                <td className="px-4 py-2 text-right font-mono tabular-nums">{stat?.total ?? 0}</td>
                <td className="px-4 py-2 text-right font-mono tabular-nums text-emerald-600 dark:text-emerald-400">{stat?.success ?? 0}</td>
                <td className="px-4 py-2 text-right font-mono tabular-nums text-rose-600 dark:text-rose-400">{stat?.failed ?? 0}</td>
                <td className="px-4 py-2 text-right font-mono tabular-nums">{stat?.companies ?? 0}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </Card>
  );
}

// ---------------------------------------------------------------------------
// TAB Zones
// ---------------------------------------------------------------------------
function TabZones({ campaign }: { campaign: Campaign }) {
  return (
    <Card padding="none" className="overflow-hidden">
      <table className="w-full text-sm">
        <thead className="bg-slate-50 text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
          <tr>
            <th className="px-4 py-2 text-left">Type</th>
            <th className="px-4 py-2 text-left">Code</th>
            <th className="px-4 py-2 text-left">Libellé</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
          {campaign.zones.map((z, i) => (
            <tr key={`${z.type}-${z.code}-${i}`}>
              <td className="px-4 py-2 capitalize">
                {z.type === 'department' ? 'Département' : z.type === 'region' ? 'Région' : 'Ville'}
              </td>
              <td className="px-4 py-2 font-mono tabular-nums">{z.code}</td>
              <td className="px-4 py-2 text-slate-700 dark:text-slate-300">{z.label ?? '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </Card>
  );
}

// ---------------------------------------------------------------------------
// TAB Runs
// ---------------------------------------------------------------------------
function TabRuns({ runs }: { runs: CampaignStatsResponse['last_events'] }) {
  if (runs.length === 0) {
    return (
      <Card padding="lg" className="text-center text-sm text-slate-500 dark:text-slate-400">
        Aucun run généré pour cette campagne.
      </Card>
    );
  }
  return (
    <Card padding="none" className="overflow-hidden">
      <table className="w-full text-sm">
        <thead className="bg-slate-50 text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
          <tr>
            <th className="px-4 py-2 text-left">#</th>
            <th className="px-4 py-2 text-left">Source</th>
            <th className="px-4 py-2 text-left">Statut</th>
            <th className="px-4 py-2 text-left">Démarré</th>
            <th className="px-4 py-2"></th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
          {runs.map((r) => (
            <tr key={r.id}>
              <td className="px-4 py-2 font-mono text-xs tabular-nums">#{r.id}</td>
              <td className="px-4 py-2">{r.source}</td>
              <td className="px-4 py-2">
                <StatusPill
                  tone={
                    r.status === 'failed' ? 'danger' :
                    r.status === 'success' || r.status === 'completed' ? 'success' :
                    r.status === 'running' ? 'running' : 'pending'
                  }
                  pulse={r.status === 'running'}
                >
                  {r.status}
                </StatusPill>
              </td>
              <td className="px-4 py-2 text-xs text-slate-500 dark:text-slate-400">
                {r.started_at ? formatRelative(r.started_at) : '—'}
              </td>
              <td className="px-4 py-2 text-right">
                <Link
                  to="/scraper-runs"
                  className="text-xs font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400"
                >
                  Voir →
                </Link>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </Card>
  );
}

// ---------------------------------------------------------------------------
// TAB Config (readonly)
// ---------------------------------------------------------------------------
function TabConfig({ campaign, onDuplicate }: { campaign: Campaign; onDuplicate: () => void }) {
  return (
    <Card padding="md" className="space-y-4">
      <div className="flex items-center justify-between gap-2">
        <h3 className="text-sm font-semibold text-slate-900 dark:text-white">Configuration de la campagne</h3>
        <Button variant="secondary" size="sm" iconLeft={<Copy className="h-3.5 w-3.5" />} onClick={onDuplicate}>
          Dupliquer
        </Button>
      </div>
      <dl className="grid grid-cols-1 gap-3 text-sm md:grid-cols-2">
        <Definition label="Nom" value={campaign.name} />
        <Definition label="Statut" value={STATUS_LABEL[campaign.status]} />
        <Definition label="Description" value={campaign.description ?? '—'} />
        <Definition label="Sources" value={campaign.sources.join(', ')} />
        <Definition label="Zones" value={`${campaign.zones.length} zone(s)`} />
        <Definition label="Max entreprises" value={campaign.max_companies.toLocaleString('fr-FR')} />
        <Definition label="Max durée (min)" value={String(campaign.max_duration_minutes)} />
        <Definition label="Max req/min" value={String(campaign.max_requests_per_minute)} />
        <Definition label="Planifiée" value={campaign.scheduled_at ? new Date(campaign.scheduled_at).toLocaleString('fr-FR') : '—'} />
        <Definition label="Expire" value={campaign.expires_at ? new Date(campaign.expires_at).toLocaleString('fr-FR') : '—'} />
        <Definition label="Démarrée" value={campaign.started_at ? new Date(campaign.started_at).toLocaleString('fr-FR') : '—'} />
        <Definition label="Terminée" value={campaign.finished_at ? new Date(campaign.finished_at).toLocaleString('fr-FR') : '—'} />
      </dl>
    </Card>
  );
}

function Definition({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg bg-slate-50 p-3 ring-1 ring-slate-100 dark:bg-slate-800/40 dark:ring-slate-800">
      <dt className="text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{label}</dt>
      <dd className="mt-0.5 break-words text-sm font-medium text-slate-900 dark:text-white">{value}</dd>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Utils
// ---------------------------------------------------------------------------
function formatRelative(iso: string): string {
  const t = new Date(iso).getTime();
  if (Number.isNaN(t)) return '—';
  const diffMs = Date.now() - t;
  const sec = Math.floor(diffMs / 1000);
  if (sec < 5) return 'à l’instant';
  if (sec < 60) return `il y a ${sec}s`;
  const min = Math.floor(sec / 60);
  if (min < 60) return `il y a ${min} min`;
  const h = Math.floor(min / 60);
  if (h < 24) return `il y a ${h}h`;
  const d = Math.floor(h / 24);
  return `il y a ${d}j`;
}

function extractApiMessage(err: unknown): string | null {
  if (typeof err === 'object' && err !== null) {
    const e = err as { response?: { data?: { message?: string; error?: string } } };
    return e.response?.data?.message ?? e.response?.data?.error ?? null;
  }
  return null;
}
