/**
 * 👥 CONTACTS — le moteur de liste UNIQUE de l'univers business.
 *
 * Parti-pris n°2 de la conception : les « types » (Clients, Presse,
 * Investisseurs…) ne sont pas huit pages, ce sont des VUES PRÉRÉGLÉES du même
 * écran. Huit pages auraient signifié huit fois les mêmes filtres à maintenir,
 * donc sept occasions de diverger.
 *
 * Le périmètre de température est AFFICHÉ, jamais implicite (conception §3a) :
 * la base froide (4,29 M de fiches collectées) ne se mélange pas au quotidien —
 * on change de vue, on ne coche pas une case.
 */
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { Card, EmptyState, KpiCard, PageHeader, SearchInput, Tabs, Toolbar, cn } from '@/components/ui';
import type { TabItem } from '@/components/ui';
import { api } from '@/lib/api';
import { COUNTRY_OPTIONS, PROSPECTION_STATUS_OPTIONS } from '@/lib/prospection-referentiels';
import { ConsoleGate, ConsoleListSkeleton } from './ConsoleGate';
import {
  LIFECYCLE_LABELS,
  RELATION_TYPES,
  RELATION_TYPE_LABELS,
  tagLabel,
  type CountsResponse,
  type CursorResponse,
  type HubCompany,
  type RelationType,
} from './types';

const SELECT_CLS =
  'h-9 rounded-lg bg-white px-3 text-xs text-slate-900 ring-1 ring-slate-200 transition focus:outline-none focus:ring-2 focus:ring-slate-300 dark:bg-slate-900 dark:text-white dark:ring-slate-700';

type TabId = 'tous' | RelationType;
type Temperature = 'actifs' | 'froids' | 'tous';

const TEMPERATURES: Array<{ id: Temperature; label: string }> = [
  { id: 'actifs', label: 'Contacts actifs' },
  { id: 'froids', label: 'Prospection (base froide)' },
  { id: 'tous', label: 'Tout' },
];

export function ContactsHubPage() {
  return (
    <ConsoleGate>
      <ContactsHubContent />
    </ConsoleGate>
  );
}

function ContactsHubContent() {
  const [tab, setTab] = useState<TabId>('tous');
  const [temperature, setTemperature] = useState<Temperature>('actifs');
  const [search, setSearch] = useState('');
  // Pays et statut de prospection : l'API les acceptait DÉJÀ
  // (`CompanyQueryFilters`), seul l'écran ne les proposait pas — donc les
  // fiches étrangères restaient introuvables depuis la console.
  const [country, setCountry] = useState('');
  const [prospection, setProspection] = useState('');

  const counts = useQuery<CountsResponse>({
    queryKey: ['crm', 'contacts-hub', 'counts'],
    queryFn: async () => (await api.get<CountsResponse>('/crm/contacts-hub/counts')).data,
  });

  const list = useQuery<CursorResponse<HubCompany>>({
    queryKey: ['crm', 'contacts-hub', tab, temperature, search, country, prospection],
    queryFn: async () => {
      const params = new URLSearchParams({ temperature, per_page: '50' });
      if (tab !== 'tous') params.set('relation_type', tab);
      if (search.trim().length > 0) params.set('q', search.trim());
      if (country) params.set('filter[country_code]', country);
      if (prospection) params.set('filter[prospection_status]', prospection);
      return (await api.get<CursorResponse<HubCompany>>(`/crm/contacts-hub?${params.toString()}`)).data;
    },
    placeholderData: (previous) => previous,
  });

  const byType = counts.data?.by_relation_type ?? {};
  const byStage = counts.data?.by_lifecycle_stage ?? {};

  const tabs: Array<TabItem<TabId>> = [
    { id: 'tous', label: 'Tous', count: counts.data?.total ?? 0 },
    ...RELATION_TYPES.map((type) => ({
      id: type,
      label: RELATION_TYPE_LABELS[type],
      count: byType[type] ?? 0,
    })),
  ];

  const rows = list.data?.data ?? [];
  const temperatureLabel = TEMPERATURES.find((t) => t.id === temperature)?.label ?? '';

  return (
    <div className="px-6 py-6">
      <PageHeader
        title="Contacts"
        subtitle={`${tab === 'tous' ? 'Tous les types' : RELATION_TYPE_LABELS[tab]} — ${temperatureLabel}`}
      />

      <div className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <KpiCard label="Clients" value={byType['client'] ?? 0} tone="emerald" />
        <KpiCard label="Prospects" value={byType['prospect'] ?? 0} tone="sky" />
        <KpiCard label="Opportunités" value={byStage['opportunite'] ?? 0} tone="violet" />
        {/* La base froide est un STOCK, pas une file : ton neutre, jamais de
            pastille rouge (conception §2.3). */}
        <KpiCard label="Dormants" value={byStage['dormant'] ?? 0} tone="slate" />
      </div>

      <Tabs items={tabs} value={tab} onChange={setTab} className="mb-4" />

      <Toolbar
        left={
          <>
            <SearchInput
              value={search}
              onChange={setSearch}
              placeholder="Nom d'entreprise, SIREN, personne…"
              className="w-72"
            />
            <select
              value={country}
              onChange={(e) => setCountry(e.target.value)}
              aria-label="Filtre pays"
              className={SELECT_CLS}
            >
              {COUNTRY_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </select>
            <select
              value={prospection}
              onChange={(e) => setProspection(e.target.value)}
              aria-label="Filtre statut de prospection"
              className={SELECT_CLS}
            >
              {PROSPECTION_STATUS_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </select>
          </>
        }
        right={
          <div className="flex items-center gap-1">
            {TEMPERATURES.map((option) => (
              <button
                key={option.id}
                type="button"
                onClick={() => setTemperature(option.id)}
                className={cn(
                  'rounded-lg px-3 py-1.5 text-xs font-medium transition',
                  temperature === option.id
                    ? 'bg-slate-100 text-slate-900 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-white dark:ring-slate-700'
                    : 'text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white',
                )}
                aria-pressed={temperature === option.id}
              >
                {option.label}
              </button>
            ))}
          </div>
        }
      />

      <div className="mt-4">
        {/* `isLoading` NE SUFFIT PAS ici : `placeholderData` garde les lignes de
            la vue précédente pendant le chargement de la suivante, donc React
            Query considère qu'il y a déjà des données et `isLoading` reste faux.
            Quand la vue précédente était LÉGITIMEMENT vide (« Contacts actifs »
            l'est tant qu'aucune fiche n'a de provenance hors collecte), on
            affichait « Aucun contact dans cette vue » pendant toute la requête
            suivante — soit un mensonge de plusieurs secondes sur une base de
            4,29 M de fiches. `isPlaceholderData` est vrai exactement pendant ce
            créneau : c'est lui qui doit déclencher le squelette. */}
        {list.isLoading || list.isPlaceholderData ? (
          <ConsoleListSkeleton />
        ) : rows.length === 0 ? (
          <EmptyState
            title="Aucun contact dans cette vue"
            description="Les fiches arrivent automatiquement depuis le site (formulaires, RDV, avis) — rien à créer ici."
          />
        ) : (
          <Card padding="none" className="overflow-hidden">
            <ul className="divide-y divide-slate-100 dark:divide-slate-800">
              {rows.map((company) => (
                <li key={company.id} className="px-4 py-3">
                  <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <span className="text-sm font-semibold text-slate-900 dark:text-white">
                      {company.denomination ?? company.siren ?? `Fiche ${company.id}`}
                    </span>
                    <span className="text-xs text-slate-500 dark:text-slate-400">
                      {RELATION_TYPE_LABELS[company.relation_type]} ·{' '}
                      {LIFECYCLE_LABELS[company.lifecycle_stage]}
                    </span>
                    {company.city_name !== null && (
                      <span className="text-xs text-slate-400 dark:text-slate-500">{company.city_name}</span>
                    )}
                  </div>

                  {company.contacts.length > 0 && (
                    <div className="mt-1 flex flex-wrap gap-x-3 text-xs text-slate-600 dark:text-slate-300">
                      {company.contacts.slice(0, 3).map((contact) => (
                        <span key={contact.id}>
                          {contact.person_key !== null ? (
                            <Link
                              to="/console/personnes/$personKey"
                              params={{ personKey: contact.person_key }}
                              className="underline decoration-dotted underline-offset-2 hover:text-brand-600"
                            >
                              {contact.first_name} {contact.last_name}
                            </Link>
                          ) : (
                            <>
                              {contact.first_name} {contact.last_name}
                            </>
                          )}
                        </span>
                      ))}
                    </div>
                  )}

                  {company.tags.length > 0 && (
                    <div className="mt-1 flex flex-wrap gap-1">
                      {company.tags.slice(0, 3).map((slug) => (
                        <span
                          key={slug}
                          className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600 dark:bg-slate-800 dark:text-slate-300"
                        >
                          {tagLabel(slug)}
                        </span>
                      ))}
                      {company.tags.length > 3 && (
                        <span className="text-[11px] text-slate-400">+{company.tags.length - 3}</span>
                      )}
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
