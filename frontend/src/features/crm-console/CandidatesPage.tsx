/**
 * 🎓 VIVIER CANDIDATS — l'univers étanche.
 *
 * Écran délibérément SÉPARÉ du hub de contacts : la conception (§2.2) fait de
 * l'univers une ambiance, pas un menu déroulant. Un candidat n'est pas un
 * prospect « d'un autre type » — c'est une autre base légale, une autre horloge
 * de conservation, un autre écran.
 *
 * L'horloge CNIL est AFFICHÉE (conception §2.3) : la conformité se pilote à
 * vue, pas dans un cron invisible.
 */
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { Card, EmptyState, KpiCard, PageHeader, SearchInput, Tabs, Toolbar } from '@/components/ui';
import type { TabItem } from '@/components/ui';
import { api } from '@/lib/api';
import { ConsoleGate, ConsoleListSkeleton } from './ConsoleGate';
import {
  CANDIDATE_FAMILIES,
  CANDIDATE_FAMILY_LABELS,
  CANDIDATE_STAGE_LABELS,
  tagLabel,
  type CandidateFamily,
  type CandidateRow,
  type CountsResponse,
  type CursorResponse,
} from './types';

type TabId = 'tous' | CandidateFamily;

function formatDate(value: string | null): string {
  if (value === null) return '—';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString('fr-FR');
}

export function CandidatesPage() {
  return (
    <ConsoleGate requiresVivier>
      <CandidatesContent />
    </ConsoleGate>
  );
}

function CandidatesContent() {
  const [tab, setTab] = useState<TabId>('tous');
  const [search, setSearch] = useState('');

  const counts = useQuery<CountsResponse>({
    queryKey: ['crm', 'candidates', 'counts'],
    queryFn: async () => (await api.get<CountsResponse>('/crm/candidates/counts')).data,
  });

  const list = useQuery<CursorResponse<CandidateRow>>({
    queryKey: ['crm', 'candidates', tab, search],
    queryFn: async () => {
      const params = new URLSearchParams({ per_page: '50' });
      if (tab !== 'tous') params.set('relation_type', tab);
      if (search.trim().length > 0) params.set('q', search.trim());
      return (await api.get<CursorResponse<CandidateRow>>(`/crm/candidates?${params.toString()}`)).data;
    },
    placeholderData: (previous) => previous,
  });

  const byFamily = counts.data?.by_relation_type ?? {};
  const byStage = counts.data?.by_lifecycle_stage ?? {};

  const tabs: Array<TabItem<TabId>> = [
    { id: 'tous', label: 'Tous les candidats', count: counts.data?.total ?? 0 },
    ...CANDIDATE_FAMILIES.map((family) => ({
      id: family,
      label: CANDIDATE_FAMILY_LABELS[family],
      count: byFamily[family] ?? 0,
    })),
  ];

  const rows = list.data?.data ?? [];

  return (
    <div className="px-6 py-6">
      <PageHeader
        title="Vivier candidats"
        subtitle="Univers étanche — base légale et durées de conservation distinctes de la base commerciale."
      />

      <div className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {/* « À qualifier » est le SEUL compteur qui appelle une action : c'est
            l'écran anti-« 71 candidatures jamais triées ». */}
        <KpiCard label="À qualifier" value={byStage['nouveau'] ?? 0} tone="amber" />
        <KpiCard label="Présélection" value={byStage['preselection'] ?? 0} tone="sky" />
        <KpiCard label="Entretien" value={byStage['entretien'] ?? 0} tone="violet" />
        <KpiCard label="Conservés en vivier" value={byStage['vivier'] ?? 0} tone="slate" />
      </div>

      <Tabs items={tabs} value={tab} onChange={setTab} className="mb-4" />

      <Toolbar
        left={
          <SearchInput value={search} onChange={setSearch} placeholder="Nom, prénom, e-mail…" className="w-72" />
        }
      />

      <div className="mt-4">
        {/* Même raison qu'au hub de contacts : sous `placeholderData`,
            `isLoading` reste faux au changement de vue et l'état vide de la vue
            PRÉCÉDENTE s'affiche pendant le chargement de la suivante. */}
        {list.isLoading || list.isPlaceholderData ? (
          <ConsoleListSkeleton />
        ) : rows.length === 0 ? (
          <EmptyState
            title="Aucun candidat dans cette vue"
            description="Les candidatures arrivent depuis le site, avec leur consentement v2 — rien à créer ici."
          />
        ) : (
          <Card padding="none" className="overflow-hidden">
            <ul className="divide-y divide-slate-100 dark:divide-slate-800">
              {rows.map((candidate) => (
                <li key={candidate.id} className="px-4 py-3">
                  <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <span className="text-sm font-semibold text-slate-900 dark:text-white">
                      {candidate.person_key !== null ? (
                        <Link
                          to="/console/personnes/$personKey"
                          params={{ personKey: candidate.person_key }}
                          className="underline decoration-dotted underline-offset-2 hover:text-brand-600"
                        >
                          {candidate.first_name} {candidate.last_name}
                        </Link>
                      ) : (
                        <>
                          {candidate.first_name} {candidate.last_name}
                        </>
                      )}
                    </span>
                    <span className="text-xs text-slate-500 dark:text-slate-400">
                      {CANDIDATE_FAMILY_LABELS[candidate.relation_type]} ·{' '}
                      {CANDIDATE_STAGE_LABELS[candidate.lifecycle_stage]}
                    </span>
                    {candidate.opt_out && (
                      <span className="rounded-full bg-rose-100 px-2 py-0.5 text-[11px] text-rose-700 dark:bg-rose-950 dark:text-rose-300">
                        Opposition
                      </span>
                    )}
                  </div>

                  <div className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Dernière interaction : {formatDate(candidate.derniere_interaction_at)}
                    {candidate.consent_vivier_at !== null && (
                      <> · Horloge CNIL : purge prévue le {formatDate(candidate.purge_prevue_le)}</>
                    )}
                    {candidate.cv_ref !== null && <> · CV stocké sur le site</>}
                  </div>

                  {candidate.tags.length > 0 && (
                    <div className="mt-1 flex flex-wrap gap-1">
                      {candidate.tags.slice(0, 4).map((slug) => (
                        <span
                          key={slug}
                          className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600 dark:bg-slate-800 dark:text-slate-300"
                        >
                          {tagLabel(slug)}
                        </span>
                      ))}
                    </div>
                  )}
                </li>
              ))}
            </ul>
          </Card>
        )}
      </div>
    </div>
  );
}
