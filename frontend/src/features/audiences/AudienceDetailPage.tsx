/**
 * Sprint Pipeline 360° — AudienceDetailPage.
 *
 * Détail d'une audience : header + KPI + tabs (Membres / Critères / Préparation campagne).
 * Refresh, Edit (toast), Delete.
 */
import { useState } from 'react';
import { Link, useParams, useNavigate } from '@tanstack/react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import {
  RefreshCw, Edit, Trash2, Users2, Zap, Mail, Building, Send,
} from 'lucide-react';
import { api, messageApiLisible } from '@/lib/api';
import { libelleReferentiel } from '@/lib/prospection-referentiels';
import { SECTEURS, TAILLES } from '@/lib/referentiels.generated';
import {
  Button,
  Card,
  EmptyState,
  KpiCard,
  PageHeader,
  QueryErrorState,
  ReponseVideState,
  Spinner,
  StatusPill,
  Tabs,
  type TabItem,
} from '@/components/ui';
import type { EmailAudience } from './AudiencesListPage';
import {
  REGLAGE_PAR_DEFAUT,
  reglageVersApi,
  type ApercuDestinataires,
  type ReglageDestinataires,
} from './destinataires';
import { ReglageDestinatairesChamps } from './ReglageDestinatairesChamps';
import { ApercuDestinatairesCarte } from './ApercuDestinatairesCarte';

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

type DetailTab = 'members' | 'destinataires' | 'criteria' | 'campaign';

const TABS: Array<TabItem<DetailTab>> = [
  { id: 'members',  label: 'Membres',               icon: <Users2 className="h-3.5 w-3.5" /> },
  // 2026-09-30 — à qui l'audience écrirait : réglage et aperçu chiffré.
  { id: 'destinataires', label: 'Destinataires',    icon: <Mail className="h-3.5 w-3.5" /> },
  { id: 'criteria', label: 'Critères',              icon: <Zap className="h-3.5 w-3.5" /> },
  { id: 'campaign', label: 'Préparation campagne',  icon: <Send className="h-3.5 w-3.5" /> },
];

/**
 * Ouvre l'enveloppe `{ data: … }` de l'API SANS SUPPOSER qu'elle est là.
 *
 * D25-004 — un corps de réponse vide (200 sans contenu, cache intermédiaire,
 * ressource filtrée par une portée) laisse `response.data` à `null`. Le code
 * écrivait `.data.data` : cela lève, et l'écran se met alors à raconter une
 * panne réseau qui n'a pas eu lieu.
 *
 * Rend `null` — jamais `undefined`, que React Query v5 refuse.
 */
function enveloppe<T>(corps: unknown): T | null {
  if (corps === null || corps === undefined || typeof corps !== 'object') return null;
  const contenu = (corps as { data?: unknown }).data;
  return contenu === undefined || contenu === null ? null : (contenu as T);
}

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

  const idLisible = Number.isFinite(id) && id > 0;

  const { data: audience, isLoading, error, refetch } = useQuery({
    queryKey: ['audience', id],
    // ⚠️ `.data.data` NU EST UN PIÈGE, et il était posé ici. Sur une réponse
    // 200 au corps vide, axios laisse `response.data` à `null` : lire `.data`
    // dessus LÈVE un `TypeError`, React Query le range en erreur, et l'écran
    // affichait « Erreur inattendue — aucune réponse du serveur ». C'est FAUX
    // deux fois : le serveur a répondu, et il a répondu 200. Mesuré le
    // 2026-08-21 (`tests/screens/sablier-eternel.test.tsx`).
    //
    // On rend `null` — et surtout PAS `undefined` : React Query v5 lève
    // « data is undefined » sur un `queryFn` qui rend `undefined`, ce qui
    // ramènerait exactement le faux message d'erreur qu'on vient d'ôter.
    queryFn: async () => enveloppe<EmailAudience>((await api.get(`/audiences/${id}`)).data),
    enabled: idLisible,
  });

  const {
    data: members,
    isLoading: membersLoading,
    error: membersError,
    refetch: membersRefetch,
  } = useQuery({
    queryKey: ['audience-members', id],
    // Même piège, même parade que ci-dessus.
    queryFn: async () =>
      enveloppe<AudienceMember[]>(
        (await api.get<MembersResponse>(`/audiences/${id}/members`, { params: { limit: 100 } })).data,
      ),
    enabled: idLisible,
  });

  const refreshMutation = useMutation({
    mutationFn: async () => (await api.post<{ data: EmailAudience }>(`/audiences/${id}/refresh`)).data.data,
    onSuccess: () => {
      toast.success('Audience rafraîchie');
      void qc.invalidateQueries({ queryKey: ['audience', id] });
      void qc.invalidateQueries({ queryKey: ['audience-members', id] });
    },
    onError: (e) => toast.error(extractApiMessage(e) ?? 'Mise à jour impossible'),
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

  // ═══ D25-004 — LE SABLIER QUI NE S'ARRÊTAIT JAMAIS ══════════════════════
  //
  // Cet écran écrivait `if (isLoading || !audience) return <Spinner/>`, la même
  // faute que `CampaignDetailPage`. React Query v5 : sur un échec, `isLoading`
  // retombe à `false` et `audience` reste `undefined` — le second terme restait
  // vrai indéfiniment, le sablier ne pouvait plus disparaître.
  //
  // Les trois façons de n'avoir pas de donnée sont désormais distinguées, parce
  // qu'elles appellent trois gestes différents : lien faux, panne/refus du
  // serveur, ou réponse 200 au corps vide (ce dernier cas n'a AUCUNE erreur à
  // montrer — c'est celui qu'un correctif « `error !== null` » laisse ouvert).
  //
  // ⚠️ `audience === undefined` fait partie de la condition d'échec : React
  // Query conserve la dernière réponse réussie quand un rafraîchissement
  // échoue, et l'effacer ferait perdre à l'écran ce qu'il affichait déjà.
  const echecLecture = error !== null && audience === undefined;

  if (!idLisible) {
    return (
      <div>
        <EmptyState
          title="Adresse d’audience invalide"
          description={`L’adresse ne contient pas d’identifiant d’audience lisible (« ${String(audienceId ?? '')} »). Le lien est probablement tronqué.`}
          action={<Link to="/audiences"><Button variant="secondary" size="sm">Retour aux audiences</Button></Link>}
        />
      </div>
    );
  }
  if (echecLecture) {
    return (
      <div>
        <QueryErrorState error={error} contexte="cette audience" onRetry={() => void refetch()} />
      </div>
    );
  }
  if (isLoading) {
    return <div className="flex h-[60vh] items-center justify-center"><Spinner /></div>;
  }
  if (!audience) {
    return (
      <div>
        <ReponseVideState contexte="cette audience" onRetry={() => void refetch()} />
      </div>
    );
  }

  return (
    <div>
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
            {audience.auto_refresh ? <StatusPill tone="info">Mise à jour auto</StatusPill> : null}
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
              Mettre à jour
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
          label="Dernière mise à jour"
          value={audience.refreshed_at ? formatRelative(audience.refreshed_at) : 'Pas encore faite'}
          sublabel={
            audience.refreshed_at
              ? new Date(audience.refreshed_at).toLocaleString('fr-FR')
              : audience.auto_refresh
                ? 'mise à jour automatique chaque nuit à 4 h'
                : '—'
          }
        />
        <KpiCard
          tone={audience.is_active ? 'emerald' : 'slate'}
          label="Statut"
          value={audience.is_active ? 'Active' : 'Inactive'}
          sublabel={audience.is_active ? 'mise à jour chaque nuit' : 'jamais mise à jour seule'}
        />
        <KpiCard
          tone={audience.auto_refresh ? 'amber' : 'slate'}
          label="Mise à jour auto"
          value={audience.auto_refresh ? 'Oui' : 'Non'}
          sublabel={audience.auto_refresh ? 'chaque jour' : 'à la main seulement'}
        />
      </div>

      {/* Tabs */}
      <div className="mb-4">
        <Tabs items={TABS} value={tab} onChange={setTab} variant="underline" />
      </div>

      {tab === 'members' ? (
        <MembersTab
          members={members ?? []}
          loading={membersLoading}
          // Mesure du 2026-08-21 : la fiche pouvait arriver et les MEMBRES
          // échouer. L'onglet affichait alors « Aucun membre pour l'instant.
          // Lance un refresh » — une affirmation sur un segment qu'il n'avait
          // pas pu lire, et une invitation à un geste qui ne sert à rien.
          error={membersError}
          onRetry={() => void membersRefetch()}
        />
      ) : null}
      {tab === 'destinataires' ? (
        <DestinatairesTab audience={audience} />
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
function MembersTab({
  members,
  loading,
  error,
  onRetry,
}: {
  members: AudienceMember[];
  loading: boolean;
  error: unknown;
  onRetry: () => void;
}) {
  // D25-001 appliqué à un ONGLET : « la liste est vide » et « je n'ai pas pu
  // lire la liste » ne se disent plus pareil. L'échec passe AVANT le
  // chargement — sur un échec, `loading` est déjà `false`, mais l'ordre rend
  // l'intention lisible et protège d'une inversion future.
  if (error !== null && error !== undefined && members.length === 0) {
    return (
      <QueryErrorState error={error} contexte="les membres de cette audience" onRetry={onRetry} />
    );
  }
  if (loading) {
    return <div className="flex h-40 items-center justify-center"><Spinner /></div>;
  }
  if (members.length === 0) {
    return (
      <Card padding="lg" className="text-center text-sm text-slate-500 dark:text-slate-400">
        Aucun membre pour l'instant. Cliquez sur « Mettre à jour » pour calculer la liste.
      </Card>
    );
  }
  return (
    <Card padding="none" className="overflow-hidden">
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
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
                  {libelleReferentiel(TAILLES, m.size_category) ?? '—'}
                </td>
                <td className="px-4 py-2.5 text-xs text-slate-600 dark:text-slate-400">
                  {libelleReferentiel(SECTEURS, m.sector_main) ?? '—'}
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
        <div className="border-t border-slate-100 bg-slate-50 px-4 py-2 text-center text-xs text-slate-500 dark:border-slate-800 dark:bg-slate-800/40 dark:text-slate-400">
          Affichage des 100 premiers membres. Le segment complet contient potentiellement plus.
        </div>
      ) : null}
    </Card>
  );
}

// ---------------------------------------------------------------------------
// Tab — Destinataires (2026-09-30)
// ---------------------------------------------------------------------------
/**
 * Le réglage « à qui écrire dans chaque organisation », enregistré sur
 * l'audience, et l'aperçu chiffré qu'il donne : organisations, adresses
 * DISTINCTES (jamais deux fois la même), exclues par motif. Rien n'est envoyé.
 */
function DestinatairesTab({ audience }: { audience: EmailAudience }) {
  const qc = useQueryClient();
  const [reglage, setReglage] = useState<ReglageDestinataires>(audience.destinataires ?? REGLAGE_PAR_DEFAUT);
  const listeExigee = (audience.criteria.all ?? []).some(
    (c) => c.field === 'liste_manuelle' && c.op === 'in',
  );

  const apercu = useQuery({
    queryKey: ['audience', audience.id, 'destinataires'],
    queryFn: async () =>
      enveloppe<ApercuDestinataires>((await api.get(`/audiences/${audience.id}/destinataires`)).data),
  });

  const enregistrement = useMutation({
    mutationFn: async () => (await api.put<unknown>(`/audiences/${audience.id}`, reglageVersApi(reglage))).data,
    onSuccess: () => {
      toast.success('Réglage des destinataires enregistré');
      void qc.invalidateQueries({ queryKey: ['audience', audience.id] });
    },
    onError: (e) => toast.error(extractApiMessage(e) ?? 'Enregistrement impossible'),
  });

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(280px,1fr)]">
      <Card padding="md" className="space-y-4">
        <ReglageDestinatairesChamps valeur={reglage} onChange={setReglage} listeExigee={listeExigee} />
        <Button
          variant="primary"
          size="md"
          loading={enregistrement.isPending}
          onClick={() => enregistrement.mutate()}
        >
          Enregistrer le réglage
        </Button>
      </Card>
      <Card padding="md">
        <h3 className="mb-3 text-sm font-semibold text-slate-900 dark:text-white">Aperçu (rien n’est envoyé)</h3>
        {apercu.isLoading ? (
          <div className="flex h-24 items-center justify-center"><Spinner /></div>
        ) : apercu.error !== null ? (
          <QueryErrorState error={apercu.error} contexte="les destinataires de cette audience" onRetry={() => void apercu.refetch()} />
        ) : apercu.data ? (
          <ApercuDestinatairesCarte apercu={apercu.data} />
        ) : (
          <ReponseVideState contexte="les destinataires de cette audience" onRetry={() => void apercu.refetch()} />
        )}
      </Card>
    </div>
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
          <h3 className="text-sm font-semibold text-slate-900 dark:text-white">Critères (format technique)</h3>
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
          <h3 className="text-base font-semibold text-slate-900 dark:text-white">Bientôt : l’envoi d’e-mails</h3>
        </div>
        <p className="text-sm text-slate-600 dark:text-slate-400">
          L’envoi d’e-mails à cette audience arrivera plus tard, avec le suivi des ouvertures
          et les relances automatiques.
        </p>
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

/** Lot 2 UX — jamais un code brut à l'écran (voir `messageApiLisible`). */
function extractApiMessage(err: unknown): string | null {
  return messageApiLisible(err);
}
