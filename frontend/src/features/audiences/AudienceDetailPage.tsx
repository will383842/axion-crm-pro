/**
 * Sprint Pipeline 360° — AudienceDetailPage.
 *
 * Détail d'une audience : header + KPI + tabs (Membres / Critères / Préparation campagne).
 * Refresh, Edit (toast), Delete.
 */
import { useState } from 'react';
import { useParams, useNavigate } from '@tanstack/react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import {
  RefreshCw, Edit, Trash2, Users2, Zap, Mail, Building, Send,
} from 'lucide-react';
import { api } from '@/lib/api';
import {
  Button,
  Card,
  KpiCard,
  PageHeader,
  Spinner,
  StatusPill,
  Tabs,
  type TabItem,
} from '@/components/ui';
import type { EmailAudience } from './AudiencesListPage';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------
interface AudienceMember {
  id: number;
  added_at: string;
  company_id: number;
  denomination: string;
  department_code: string | null;
  size_category: string | null;
  sector_main: string | null;
  contact_id: number | null;
  first_name: string | null;
  last_name: string | null;
  email: string | null;
}

interface MembersResponse {
  data: AudienceMember[];
}

type DetailTab = 'members' | 'criteria' | 'campaign';

const TABS: Array<TabItem<DetailTab>> = [
  { id: 'members',  label: 'Membres',               icon: <Users2 className="h-3.5 w-3.5" /> },
  { id: 'criteria', label: 'Critères',              icon: <Zap className="h-3.5 w-3.5" /> },
  { id: 'campaign', label: 'Préparation campagne',  icon: <Send className="h-3.5 w-3.5" /> },
];

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------
export function AudienceDetailPage() {
  const params = useParams({ strict: false });
  const audienceId = (params as { audienceId?: string }).audienceId;
  const id = Number(audienceId);
  const navigate = useNavigate();
  const qc = useQueryClient();
  const [tab, setTab] = useState<DetailTab>('members');

  const { data: audience, isLoading } = useQuery({
    queryKey: ['audience', id],
    queryFn: async () => (await api.get<{ data: EmailAudience }>(`/audiences/${id}`)).data.data,
    enabled: Number.isFinite(id) && id > 0,
  });

  const { data: members, isLoading: membersLoading } = useQuery({
    queryKey: ['audience-members', id],
    queryFn: async () =>
      (await api.get<MembersResponse>(`/audiences/${id}/members`, { params: { limit: 100 } })).data.data,
    enabled: Number.isFinite(id) && id > 0,
  });

  const refreshMutation = useMutation({
    mutationFn: async () => (await api.post<{ data: EmailAudience }>(`/audiences/${id}/refresh`)).data.data,
    onSuccess: () => {
      toast.success('Audience rafraîchie');
      void qc.invalidateQueries({ queryKey: ['audience', id] });
      void qc.invalidateQueries({ queryKey: ['audience-members', id] });
    },
    onError: (e) => toast.error(extractApiMessage(e) ?? 'Refresh impossible'),
  });

  const deleteMutation = useMutation({
    mutationFn: async () => api.delete(`/audiences/${id}`),
    onSuccess: () => {
      toast.success('Audience supprimée');
      void qc.invalidateQueries({ queryKey: ['audiences'] });
      void navigate({ to: '/audiences' });
    },
    onError: (e) => toast.error(extractApiMessage(e) ?? 'Suppression impossible'),
  });

  if (isLoading || !audience) {
    return <div className="flex h-[60vh] items-center justify-center"><Spinner /></div>;
  }

  return (
    <div className="px-6 py-6">
      <PageHeader
        breadcrumbs={[
          { label: 'Audiences', to: '/audiences' },
          { label: audience.name },
        ]}
        title={audience.name}
        subtitle={audience.description ?? undefined}
        badge={
          <div className="flex items-center gap-2">
            <StatusPill tone={audience.is_active ? 'success' : 'neutral'} pulse={audience.is_active}>
              {audience.is_active ? 'Active' : 'Inactive'}
            </StatusPill>
            {audience.auto_refresh ? <StatusPill tone="info">Auto-refresh</StatusPill> : null}
          </div>
        }
        actions={
          <>
            <Button
              variant="secondary"
              size="md"
              iconLeft={<RefreshCw className="h-4 w-4" />}
              loading={refreshMutation.isPending}
              onClick={() => refreshMutation.mutate()}
            >
              Refresh
            </Button>
            <Button
              variant="ghost"
              size="md"
              iconLeft={<Edit className="h-4 w-4" />}
              onClick={() => toast.info('Édition bientôt disponible')}
            >
              Edit
            </Button>
            <Button
              variant="destructive"
              size="md"
              iconLeft={<Trash2 className="h-4 w-4" />}
              loading={deleteMutation.isPending}
              onClick={() => {
                if (window.confirm(`Supprimer l'audience « ${audience.name} » ?`)) {
                  deleteMutation.mutate();
                }
              }}
            >
              Supprimer
            </Button>
          </>
        }
      />

      {/* KPIs */}
      <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
        <KpiCard
          tone="sky"
          label="Membres"
          value={audience.member_count.toLocaleString('fr-FR')}
          sublabel="entreprises + contacts"
        />
        <KpiCard
          tone="violet"
          label="Dernière refresh"
          value={formatRelative(audience.refreshed_at)}
          sublabel={audience.refreshed_at ? new Date(audience.refreshed_at).toLocaleString('fr-FR') : '—'}
        />
        <KpiCard
          tone={audience.is_active ? 'emerald' : 'slate'}
          label="Statut"
          value={audience.is_active ? 'Active' : 'Inactive'}
          sublabel={audience.is_active ? 'incluse dans refresh batch' : 'exclue du refresh'}
        />
        <KpiCard
          tone={audience.auto_refresh ? 'amber' : 'slate'}
          label="Auto-refresh"
          value={audience.auto_refresh ? 'ON' : 'OFF'}
          sublabel={audience.auto_refresh ? 'refresh planifié quotidien' : 'manuel uniquement'}
        />
      </div>

      {/* Tabs */}
      <div className="mb-4">
        <Tabs items={TABS} value={tab} onChange={setTab} variant="underline" />
      </div>

      {tab === 'members' ? (
        <MembersTab members={members ?? []} loading={membersLoading} />
      ) : null}
      {tab === 'criteria' ? (
        <CriteriaTab audience={audience} />
      ) : null}
      {tab === 'campaign' ? (
        <CampaignPlaceholderTab />
      ) : null}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Tab — Membres
// ---------------------------------------------------------------------------
function MembersTab({ members, loading }: { members: AudienceMember[]; loading: boolean }) {
  if (loading) {
    return <div className="flex h-40 items-center justify-center"><Spinner /></div>;
  }
  if (members.length === 0) {
    return (
      <Card padding="lg" className="text-center text-sm text-slate-500 dark:text-slate-400">
        Aucun membre pour l'instant. Lance un refresh pour matérialiser le segment.
      </Card>
    );
  }
  return (
    <Card padding="none" className="overflow-hidden">
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="bg-slate-50 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
            <tr>
              <th className="px-4 py-2.5">Entreprise</th>
              <th className="px-4 py-2.5">Dépt</th>
              <th className="px-4 py-2.5">Taille</th>
              <th className="px-4 py-2.5">Secteur</th>
              <th className="px-4 py-2.5">Contact email</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
            {members.map((m) => (
              <tr key={m.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                <td className="px-4 py-2.5">
                  <div className="flex items-center gap-1.5">
                    <Building className="h-3.5 w-3.5 text-slate-400" />
                    <span className="font-medium text-slate-900 dark:text-white">{m.denomination}</span>
                  </div>
                </td>
                <td className="px-4 py-2.5 font-mono text-xs tabular-nums text-slate-600 dark:text-slate-400">
                  {m.department_code ?? '—'}
                </td>
                <td className="px-4 py-2.5 text-xs text-slate-600 dark:text-slate-400">
                  {m.size_category ?? '—'}
                </td>
                <td className="px-4 py-2.5 text-xs text-slate-600 dark:text-slate-400">
                  {m.sector_main ?? '—'}
                </td>
                <td className="px-4 py-2.5">
                  {m.email ? (
                    <div className="flex items-center gap-1.5">
                      <Mail className="h-3.5 w-3.5 text-emerald-500" />
                      <span className="text-xs text-slate-700 dark:text-slate-300">
                        {m.first_name ? `${m.first_name} ${m.last_name ?? ''} · ` : ''}
                        <span className="font-mono">{m.email}</span>
                      </span>
                    </div>
                  ) : (
                    <span className="text-xs italic text-slate-400">aucun email</span>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {members.length >= 100 ? (
        <div className="border-t border-slate-100 bg-slate-50 px-4 py-2 text-center text-[11px] text-slate-500 dark:border-slate-800 dark:bg-slate-800/40 dark:text-slate-400">
          Affichage des 100 premiers membres. Le segment complet contient potentiellement plus.
        </div>
      ) : null}
    </Card>
  );
}

// ---------------------------------------------------------------------------
// Tab — Critères
// ---------------------------------------------------------------------------
function CriteriaTab({ audience }: { audience: EmailAudience }) {
  const json = JSON.stringify(audience.criteria, null, 2);
  return (
    <div className="space-y-3">
      <Card padding="md">
        <div className="mb-3 flex items-center justify-between">
          <h3 className="text-sm font-semibold text-slate-900 dark:text-white">Critères (JSON brut)</h3>
          <Button
            variant="secondary"
            size="sm"
            iconLeft={<Edit className="h-3.5 w-3.5" />}
            onClick={() => toast.info('Édition bientôt disponible')}
          >
            Modifier
          </Button>
        </div>
        <pre className="overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-100 dark:bg-slate-950 dark:text-slate-200">
          <code>{json}</code>
        </pre>
      </Card>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Tab — Campagne placeholder
// ---------------------------------------------------------------------------
function CampaignPlaceholderTab() {
  return (
    <Card padding="lg" variant="glass" className="border border-amber-200/60 bg-amber-50/50 dark:border-amber-900/40 dark:bg-amber-950/20">
      <div className="flex flex-col items-start gap-3">
        <div className="flex items-center gap-2">
          <Send className="h-5 w-5 text-amber-600 dark:text-amber-400" />
          <h3 className="text-base font-semibold text-slate-900 dark:text-white">Bientôt — envoi de campagne email</h3>
        </div>
        <p className="text-sm text-slate-600 dark:text-slate-400">
          L'envoi de campagnes cold email sur cette audience sera disponible dans un prochain sprint
          (intégration Resend / Mailjet + tracking ouverture + relances automatiques).
        </p>
        <a
          href="https://github.com/will383842/axion-crm-pro/blob/main/_docs/PROSPECTION-PIPELINE.md"
          target="_blank"
          rel="noopener noreferrer"
          className="text-xs font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400"
        >
          Voir roadmap pipeline →
        </a>
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

function extractApiMessage(err: unknown): string | null {
  if (typeof err === 'object' && err !== null) {
    const e = err as { response?: { data?: { message?: string; error?: string } } };
    return e.response?.data?.message ?? e.response?.data?.error ?? null;
  }
  return null;
}
