/**
 * Sprint Pipeline 360° — AudiencesListPage.
 *
 * Liste des audiences email (segments dynamiques). Cards avec member count,
 * status pulsé, dernière refresh relative et menu actions (refresh / toggle / delete).
 */
import { useMemo } from 'react';
import { Link, useNavigate } from '@tanstack/react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import {
  Users2, Plus, RefreshCw, Power, Trash2, MoreVertical, Clock,
} from 'lucide-react';
import { api } from '@/lib/api';
import {
  Button,
  Card,
  DropdownMenu,
  EmptyState,
  KpiCard,
  PageHeader,
  Skeleton,
  StatusPill,
  type MenuItem,
} from '@/components/ui';

// ---------------------------------------------------------------------------
// Types (locaux — gardés ici tant que features/audiences/types.ts n'existe pas)
// ---------------------------------------------------------------------------
export interface AudienceCondition {
  field: string;
  op: string;
  value: unknown;
}

export interface AudienceCriteria {
  all?: AudienceCondition[];
  any?: AudienceCondition[];
  not?: AudienceCondition[];
}

export interface EmailAudience {
  id: number;
  name: string;
  description: string | null;
  criteria: AudienceCriteria;
  is_active: boolean;
  auto_refresh: boolean;
  member_count: number;
  refreshed_at: string | null;
  created_at: string;
}

interface AudiencesListResponse {
  data: EmailAudience[];
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------
export function AudiencesListPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();

  const { data, isLoading } = useQuery({
    queryKey: ['audiences'],
    queryFn: async () => (await api.get<AudiencesListResponse>('/audiences')).data,
    refetchInterval: 30_000,
  });

  const audiences = useMemo<EmailAudience[]>(() => data?.data ?? [], [data]);

  const totals = useMemo(() => {
    let active = 0;
    let members = 0;
    for (const a of audiences) {
      if (a.is_active) active++;
      members += a.member_count;
    }
    return { active, members, all: audiences.length };
  }, [audiences]);

  const refreshMutation = useMutation({
    mutationFn: async (id: number) => (await api.post<{ data: EmailAudience }>(`/audiences/${id}/refresh`)).data,
    onSuccess: () => { toast.success('Audience rafraîchie'); void qc.invalidateQueries({ queryKey: ['audiences'] }); },
    onError: (e) => toast.error(extractApiMessage(e) ?? 'Refresh impossible'),
  });
  const toggleMutation = useMutation({
    mutationFn: async (a: EmailAudience) =>
      (await api.put<{ data: EmailAudience }>(`/audiences/${a.id}`, { is_active: !a.is_active })).data,
    onSuccess: () => { toast.success('Statut mis à jour'); void qc.invalidateQueries({ queryKey: ['audiences'] }); },
    onError: (e) => toast.error(extractApiMessage(e) ?? 'Mise à jour impossible'),
  });
  const deleteMutation = useMutation({
    mutationFn: async (id: number) => api.delete(`/audiences/${id}`),
    onSuccess: () => { toast.success('Audience supprimée'); void qc.invalidateQueries({ queryKey: ['audiences'] }); },
    onError: (e) => toast.error(extractApiMessage(e) ?? 'Suppression impossible'),
  });

  return (
    <div className="px-6 py-6">
      <PageHeader
        title="Audiences"
        subtitle="Segments dynamiques d'entreprises et contacts, prêts pour campagne email."
        actions={
          <Button
            variant="primary"
            size="md"
            iconLeft={<Plus className="h-4 w-4" />}
            onClick={() => { void navigate({ to: '/audiences/new' }); }}
          >
            Nouvelle audience
          </Button>
        }
      />

      {/* KPIs */}
      <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3">
        <KpiCard tone="sky"     label="Total audiences" value={totals.all}     sublabel="segments configurés" />
        <KpiCard tone="emerald" label="Actives"         value={totals.active}  sublabel="exécutées au refresh auto" />
        <KpiCard tone="violet"  label="Membres cumul"   value={totals.members.toLocaleString('fr-FR')} sublabel="entreprises × audiences" />
      </div>

      {/* Body */}
      {isLoading ? (
        <ListSkeleton />
      ) : audiences.length === 0 ? (
        <EmptyState
          icon={<Users2 className="h-8 w-8" />}
          title="Aucune audience pour l'instant"
          description="Crée ton premier segment dynamique : filtre par département, taille, secteur, statut prospect…"
          action={
            <Button
              variant="primary"
              size="md"
              iconLeft={<Plus className="h-4 w-4" />}
              onClick={() => { void navigate({ to: '/audiences/new' }); }}
            >
              Créer une audience
            </Button>
          }
        />
      ) : (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
          {audiences.map((a) => (
            <AudienceCard
              key={a.id}
              audience={a}
              onRefresh={() => refreshMutation.mutate(a.id)}
              onToggle={() => toggleMutation.mutate(a)}
              onDelete={() => {
                if (window.confirm(`Supprimer l'audience « ${a.name} » ?`)) {
                  deleteMutation.mutate(a.id);
                }
              }}
            />
          ))}
        </div>
      )}
    </div>
  );
}

// ---------------------------------------------------------------------------
// AudienceCard
// ---------------------------------------------------------------------------
function AudienceCard({
  audience,
  onRefresh,
  onToggle,
  onDelete,
}: {
  audience: EmailAudience;
  onRefresh: () => void;
  onToggle: () => void;
  onDelete: () => void;
}) {
  const menuItems: MenuItem[] = [
    { id: 'refresh', label: 'Rafraîchir', icon: <RefreshCw className="h-3.5 w-3.5" />, onSelect: onRefresh },
    { id: 'toggle',  label: audience.is_active ? 'Désactiver' : 'Activer', icon: <Power className="h-3.5 w-3.5" />, onSelect: onToggle },
    { id: 'div',     label: '', divider: true },
    { id: 'delete',  label: 'Supprimer', icon: <Trash2 className="h-3.5 w-3.5" />, destructive: true, onSelect: onDelete },
  ];

  return (
    <Card hover padding="md" className="flex flex-col gap-3">
      <div className="flex items-start justify-between gap-2">
        <Link
          to="/audiences/$audienceId"
          params={{ audienceId: String(audience.id) }}
          className="min-w-0 flex-1"
        >
          <h3 className="truncate text-base font-semibold tracking-tight text-slate-900 dark:text-white">
            {audience.name}
          </h3>
          {audience.description ? (
            <p className="mt-0.5 line-clamp-2 text-xs text-slate-500 dark:text-slate-400">{audience.description}</p>
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

      <div className="flex flex-wrap items-center gap-2">
        <StatusPill tone={audience.is_active ? 'success' : 'neutral'} pulse={audience.is_active}>
          {audience.is_active ? 'Active' : 'Inactive'}
        </StatusPill>
        {audience.auto_refresh ? (
          <StatusPill tone="info">Auto-refresh</StatusPill>
        ) : null}
      </div>

      {/* Member count chip */}
      <div className="rounded-xl bg-slate-50 p-3 ring-1 ring-slate-100 dark:bg-slate-800/60 dark:ring-slate-800">
        <div className="flex items-center gap-1.5 text-[10px] font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">
          <Users2 className="h-3 w-3" />
          Membres
        </div>
        <div className="mt-0.5 text-2xl font-semibold tracking-tight tabular-nums text-slate-900 dark:text-white">
          {audience.member_count.toLocaleString('fr-FR')}
        </div>
      </div>

      <div className="flex items-center gap-1.5 text-[11px] text-slate-500 dark:text-slate-400">
        <Clock className="h-3 w-3" />
        Dernière refresh : <span className="font-medium text-slate-700 dark:text-slate-300">{formatRelative(audience.refreshed_at)}</span>
      </div>

      <div className="mt-1 flex items-center gap-2 border-t border-slate-100 pt-3 dark:border-slate-800">
        <Button
          variant="ghost"
          size="sm"
          iconLeft={<RefreshCw className="h-3.5 w-3.5" />}
          onClick={onRefresh}
        >
          Refresh
        </Button>
        <Link
          to="/audiences/$audienceId"
          params={{ audienceId: String(audience.id) }}
          className="ml-auto text-xs font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400"
        >
          Détails →
        </Link>
      </div>
    </Card>
  );
}

// ---------------------------------------------------------------------------
// Utils
// ---------------------------------------------------------------------------
function formatRelative(iso: string | null): string {
  if (!iso) return 'jamais';
  const date = new Date(iso);
  const diffSec = Math.floor((Date.now() - date.getTime()) / 1000);
  if (diffSec < 60) return 'à l\'instant';
  if (diffSec < 3600) return `il y a ${Math.floor(diffSec / 60)} min`;
  if (diffSec < 86400) return `il y a ${Math.floor(diffSec / 3600)} h`;
  if (diffSec < 2592000) return `il y a ${Math.floor(diffSec / 86400)} j`;
  return date.toLocaleDateString('fr-FR');
}

function ListSkeleton() {
  return (
    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
      {Array.from({ length: 6 }).map((_, i) => (
        <Card key={i} padding="md" className="flex flex-col gap-3">
          <Skeleton className="h-5 w-40" />
          <Skeleton className="h-3 w-24" />
          <Skeleton className="h-16 w-full" />
          <Skeleton className="h-3 w-32" />
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
