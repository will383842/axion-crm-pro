/**
 * Sprint 19.7 — CampaignsListPage.
 *
 * Liste des campagnes de scraping. Card-based, gradient title, KPIs en haut,
 * filter tabs par statut, cards avec progress bar live.
 */
import { useMemo, useState } from 'react';
import { Link, useNavigate } from '@tanstack/react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import {
  Megaphone, Plus, Pause, Play, X, Search,
  Building2, Clock, Map as MapIcon, MoreVertical,
} from 'lucide-react';
import { api } from '@/lib/api';
import {
  Button,
  Card,
  cn,
  DropdownMenu,
  EmptyState,
  Input,
  KpiCard,
  LiveBadge,
  PageHeader,
  Skeleton,
  StatusPill,
  Tabs,
  type MenuItem,
  type TabItem,
} from '@/components/ui';
import {
  type Campaign,
  type CampaignStatus,
  type CampaignsListResponse,
  ALL_SOURCES,
  STATUS_LABEL,
  PAUSED_REASON_LABEL,
  statusToTone,
} from './types';

type Filter = 'all' | CampaignStatus;

const FILTER_TABS: Array<{ id: Filter; label: string }> = [
  { id: 'all',       label: 'Toutes' },
  { id: 'draft',     label: 'Brouillons' },
  { id: 'scheduled', label: 'Planifiées' },
  { id: 'running',   label: 'En cours' },
  { id: 'paused',    label: 'En pause' },
  { id: 'completed', label: 'Terminées' },
];

export function CampaignsListPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const [filter, setFilter] = useState<Filter>('all');
  const [search, setSearch] = useState('');

  const { data, isLoading } = useQuery({
    queryKey: ['campaigns', { search }],
    queryFn: async () =>
      (await api.get<CampaignsListResponse>('/campaigns', {
        params: { per_page: 50, search: search || undefined },
      })).data,
    refetchInterval: 10_000,
  });

  const campaigns = useMemo<Campaign[]>(() => data?.data ?? [], [data]);

  const counts = useMemo(() => {
    const c: Record<Filter, number> = {
      all: campaigns.length,
      draft: 0, scheduled: 0, running: 0, paused: 0, completed: 0,
      failed: 0, cancelled: 0,
    };
    for (const camp of campaigns) {
      if (c[camp.status] !== undefined) c[camp.status]++;
    }
    return c;
  }, [campaigns]);

  const totalCompanies = useMemo(
    () => campaigns.reduce((s, c) => s + c.companies_created, 0),
    [campaigns],
  );

  const filtered = useMemo(() => {
    return campaigns.filter((c) => filter === 'all' ? true : c.status === filter);
  }, [campaigns, filter]);

  const pauseMutation = useMutation({
    mutationFn: async (id: number) => (await api.post<Campaign>(`/campaigns/${id}/pause`)).data,
    onSuccess: () => { toast.success('Campagne mise en pause'); void qc.invalidateQueries({ queryKey: ['campaigns'] }); },
    onError: (e) => toast.error(extractApiMessage(e) ?? 'Pause impossible'),
  });
  const resumeMutation = useMutation({
    mutationFn: async (id: number) => (await api.post<Campaign>(`/campaigns/${id}/resume`)).data,
    onSuccess: () => { toast.success('Campagne reprise'); void qc.invalidateQueries({ queryKey: ['campaigns'] }); },
    onError: (e) => toast.error(extractApiMessage(e) ?? 'Reprise impossible'),
  });
  const cancelMutation = useMutation({
    mutationFn: async (id: number) => (await api.post<Campaign>(`/campaigns/${id}/cancel`)).data,
    onSuccess: () => { toast.success('Campagne annulée'); void qc.invalidateQueries({ queryKey: ['campaigns'] }); },
    onError: (e) => toast.error(extractApiMessage(e) ?? 'Annulation impossible'),
  });
  const deleteMutation = useMutation({
    mutationFn: async (id: number) => api.delete(`/campaigns/${id}`),
    onSuccess: () => { toast.success('Campagne supprimée'); void qc.invalidateQueries({ queryKey: ['campaigns'] }); },
    onError: (e) => toast.error(extractApiMessage(e) ?? 'Suppression impossible'),
  });

  const tabs: Array<TabItem<Filter>> = FILTER_TABS.map((t) => ({
    id: t.id,
    label: t.label,
    count: counts[t.id],
  }));

  return (
    <div className="px-6 py-6">
      <PageHeader
        title="Campagnes de scraping"
        subtitle="Lance et supervise des campagnes multi-sources avec budgets et auto-pause anti-blacklist."
        badge={<LiveBadge label="En direct" refreshLabel="actualisé toutes les 10s" />}
        actions={
          <Button
            variant="primary"
            size="md"
            iconLeft={<Plus className="h-4 w-4" />}
            onClick={() => { void navigate({ to: '/campaigns/new' }); }}
          >
            Nouvelle campagne
          </Button>
        }
      />

      {/* KPIs */}
      <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
        <KpiCard tone="sky"     label="Total"               value={counts.all}      sublabel="campagnes" />
        <KpiCard tone="violet"  label="En cours"            value={counts.running}  sublabel={counts.paused ? `+ ${counts.paused} en pause` : 'en exécution'} />
        <KpiCard tone="emerald" label="Terminées"           value={counts.completed} sublabel="succès complets" />
        <KpiCard tone="amber"   label="Entreprises créées"  value={totalCompanies.toLocaleString('fr-FR')} sublabel="cumul toutes campagnes" />
      </div>

      {/* Filters + search */}
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <Tabs items={tabs} value={filter} onChange={setFilter} variant="pills" />
        <div className="ml-auto w-full max-w-xs">
          <Input
            iconLeft={<Search className="h-4 w-4" />}
            placeholder="Rechercher par nom…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>
      </div>

      {/* Body */}
      {isLoading ? (
        <ListSkeleton />
      ) : filtered.length === 0 ? (
        <EmptyState
          icon={<Megaphone className="h-8 w-8" />}
          title={search || filter !== 'all' ? 'Aucune campagne ne correspond' : 'Aucune campagne pour l’instant'}
          description={
            search || filter !== 'all'
              ? 'Essaie un autre filtre ou réinitialise la recherche.'
              : 'Lance ta première campagne en 3 clics : zones cibles, sources, budget.'
          }
          action={
            search || filter !== 'all' ? (
              <Button variant="secondary" size="sm" onClick={() => { setSearch(''); setFilter('all'); }}>
                Réinitialiser
              </Button>
            ) : (
              <Button
                variant="primary"
                size="md"
                iconLeft={<Plus className="h-4 w-4" />}
                onClick={() => { void navigate({ to: '/campaigns/new' }); }}
              >
                Créer une campagne
              </Button>
            )
          }
        />
      ) : (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
          {filtered.map((c) => (
            <CampaignCard
              key={c.id}
              campaign={c}
              onPause={() => pauseMutation.mutate(c.id)}
              onResume={() => resumeMutation.mutate(c.id)}
              onCancel={() => cancelMutation.mutate(c.id)}
              onDelete={() => deleteMutation.mutate(c.id)}
            />
          ))}
        </div>
      )}
    </div>
  );
}

// ---------------------------------------------------------------------------
// CampaignCard
// ---------------------------------------------------------------------------
function CampaignCard({
  campaign,
  onPause,
  onResume,
  onCancel,
  onDelete,
}: {
  campaign: Campaign;
  onPause: () => void;
  onResume: () => void;
  onCancel: () => void;
  onDelete: () => void;
}) {
  const tone = statusToTone(campaign.status);
  const isLive = campaign.status === 'running';
  const isPaused = campaign.status === 'paused';

  const sources = (campaign.sources ?? []).slice(0, 5);
  const moreSources = Math.max(0, (campaign.sources?.length ?? 0) - sources.length);

  const menuItems: MenuItem[] = [
    { id: 'open', label: 'Voir détails', onSelect: () => { window.location.assign(`/campaigns/${campaign.id}`); } },
  ];
  if (campaign.can_pause) {
    menuItems.push({ id: 'pause', label: 'Mettre en pause', onSelect: onPause });
  }
  if (campaign.can_resume) {
    menuItems.push({ id: 'resume', label: 'Reprendre', onSelect: onResume });
  }
  if (campaign.can_cancel) {
    menuItems.push({ id: 'cancel', label: 'Annuler', destructive: true, onSelect: onCancel });
  }
  if (campaign.status === 'draft') {
    menuItems.push({ id: 'div', divider: true, label: '' });
    menuItems.push({ id: 'delete', label: 'Supprimer', destructive: true, onSelect: onDelete });
  }

  return (
    <Card hover padding="md" className="flex flex-col gap-3">
      <div className="flex items-start justify-between gap-2">
        <Link
          to="/campaigns/$campaignId"
          params={{ campaignId: String(campaign.id) }}
          className="min-w-0 flex-1"
        >
          <h3 className="truncate text-base font-semibold tracking-tight text-slate-900 dark:text-white">
            {campaign.name}
          </h3>
          {campaign.description ? (
            <p className="mt-0.5 truncate text-xs text-slate-500 dark:text-slate-400">{campaign.description}</p>
          ) : null}
        </Link>
        <DropdownMenu
          trigger={
            <button
              type="button"
              aria-label="Actions"
              className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800"
            >
              <MoreVertical className="h-4 w-4" />
            </button>
          }
          items={menuItems}
        />
      </div>

      <div className="flex items-center gap-2">
        <StatusPill tone={tone} pulse={isLive}>{STATUS_LABEL[campaign.status]}</StatusPill>
        {isPaused && campaign.paused_reason ? (
          <span className="text-[11px] text-slate-500 dark:text-slate-400">
            · {PAUSED_REASON_LABEL[campaign.paused_reason] ?? campaign.paused_reason}
          </span>
        ) : null}
      </div>

      {/* Sources */}
      <div className="flex flex-wrap items-center gap-1.5">
        {sources.map((s) => {
          const meta = ALL_SOURCES.find((m) => m.id === s);
          return (
            <span
              key={s}
              className="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-300"
            >
              {meta?.label ?? s}
            </span>
          );
        })}
        {moreSources > 0 ? (
          <span className="text-[10px] text-slate-500 dark:text-slate-400">+ {moreSources}</span>
        ) : null}
      </div>

      {/* Zones */}
      <div className="flex flex-wrap items-center gap-1.5">
        <MapIcon className="h-3 w-3 text-slate-400" />
        {(campaign.zones ?? []).slice(0, 6).map((z, i) => (
          <span
            key={`${z.type}-${z.code}-${i}`}
            className="inline-flex items-center gap-1 rounded-md bg-sky-50 px-1.5 py-0.5 text-[10px] font-medium text-sky-700 dark:bg-sky-950/40 dark:text-sky-300"
          >
            {z.type === 'department' ? 'Dépt' : z.type === 'region' ? 'Rég' : 'Ville'} {z.code}
          </span>
        ))}
        {(campaign.zones?.length ?? 0) > 6 ? (
          <span className="text-[10px] text-slate-500">+ {(campaign.zones?.length ?? 0) - 6}</span>
        ) : null}
      </div>

      {/* Progress entreprises */}
      <div>
        <div className="flex items-baseline justify-between gap-2 text-xs">
          <span className="inline-flex items-center gap-1 text-slate-500 dark:text-slate-400">
            <Building2 className="h-3 w-3" /> Entreprises
          </span>
          <span className="font-mono tabular-nums text-slate-700 dark:text-slate-300">
            <span className="font-semibold text-slate-900 dark:text-white">{campaign.companies_created}</span>
            <span className="text-slate-400"> / {campaign.max_companies}</span>
          </span>
        </div>
        <div className="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
          <div
            className={cn(
              'h-full rounded-full bg-gradient-to-r transition-[width] duration-500',
              isLive ? 'from-emerald-500 to-teal-600' : 'from-slate-400 to-slate-500',
            )}
            style={{ width: `${Math.min(100, Math.round((campaign.companies_created / Math.max(1, campaign.max_companies)) * 100))}%` }}
          />
        </div>
      </div>

      {/* Progress durée */}
      <div>
        <div className="flex items-baseline justify-between gap-2 text-xs">
          <span className="inline-flex items-center gap-1 text-slate-500 dark:text-slate-400">
            <Clock className="h-3 w-3" /> Durée
          </span>
          <span className="font-mono tabular-nums text-slate-700 dark:text-slate-300">
            <span className="font-semibold text-slate-900 dark:text-white">{campaign.elapsed_minutes}</span>
            <span className="text-slate-400"> / {campaign.max_duration_minutes} min</span>
          </span>
        </div>
        <div className="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
          <div
            className="h-full rounded-full bg-gradient-to-r from-sky-500 to-blue-600 transition-[width] duration-500"
            style={{ width: `${Math.min(100, Math.round((campaign.elapsed_minutes / Math.max(1, campaign.max_duration_minutes)) * 100))}%` }}
          />
        </div>
      </div>

      <div className="mt-1 flex items-center gap-2 border-t border-slate-100 pt-3 dark:border-slate-800">
        {campaign.can_pause ? (
          <Button variant="ghost" size="sm" iconLeft={<Pause className="h-3.5 w-3.5" />} onClick={onPause}>
            Pause
          </Button>
        ) : null}
        {campaign.can_resume ? (
          <Button variant="ghost" size="sm" iconLeft={<Play className="h-3.5 w-3.5" />} onClick={onResume}>
            Reprendre
          </Button>
        ) : null}
        {campaign.can_cancel ? (
          <Button variant="ghost" size="sm" iconLeft={<X className="h-3.5 w-3.5" />} onClick={onCancel}>
            Annuler
          </Button>
        ) : null}
        <Link
          to="/campaigns/$campaignId"
          params={{ campaignId: String(campaign.id) }}
          className="ml-auto text-xs font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400"
        >
          Voir détails →
        </Link>
      </div>
    </Card>
  );
}

function ListSkeleton() {
  return (
    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
      {Array.from({ length: 6 }).map((_, i) => (
        <Card key={i} padding="md" className="flex flex-col gap-3">
          <Skeleton className="h-5 w-40" />
          <Skeleton className="h-3 w-24" />
          <Skeleton className="h-2 w-full" />
          <Skeleton className="h-2 w-full" />
        </Card>
      ))}
    </div>
  );
}

function extractApiMessage(err: unknown): string | null {
  if (typeof err === 'object' && err !== null) {
    const e = err as { response?: { data?: { message?: string; error?: string } } };
    return e.response?.data?.message ?? e.response?.data?.error ?? null;
  }
  return null;
}
