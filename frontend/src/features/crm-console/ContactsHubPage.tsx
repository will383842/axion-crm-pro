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
import {
  Card,
  EmptyState,
  KpiCard,
  PageHeader,
  QueryErrorState,
  SearchInput,
  Tabs,
  Toolbar,
  cn,
} from '@/components/ui';
import type { TabItem } from '@/components/ui';
import { api } from '@/lib/api';
import { useAntiRebond } from '@/hooks/useAntiRebond';
import { COUNTRY_OPTIONS, PROSPECTION_STATUS_OPTIONS } from '@/lib/prospection-referentiels';
import { ConsoleGate, ConsoleListSkeleton } from './ConsoleGate';
import { AjouterAUneListe } from '@/features/listes/AjouterAUneListe';
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
  // G42-010 — anti-rebond de 300 ms AVANT la requete.
  //
  // Mesure du 2026-08-20 sur `/companies`, meme cablage (voir
  // `tests/perf/recherche-anti-rebond.test.tsx`) : taper « boulangerie »
  // (11 caracteres) lancait 11 requetes serveur. Apres : 1.
  //
  // ⚠️ Le champ garde `search` (valeur IMMEDIATE) pour son `value` : la
  // lettre s'affiche sans attendre. Seule la requete patiente.
  const rechercheDifferee = useAntiRebond(search);
  // Pays et statut de prospection : l'API les acceptait DÉJÀ
  // (`CompanyQueryFilters`), seul l'écran ne les proposait pas — donc les
  // fiches étrangères restaient introuvables depuis la console.
  const [country, setCountry] = useState('');
  const [prospection, setProspection] = useState('');
  // 2026-09-30 — cocher des organisations ou des PERSONNES pour les mettre dans
  // une liste manuelle (« seulement certains contacts »). Des identifiants
  // VISIBLES seulement : jamais « tout ce qui correspond au filtre ».
  const [orgsCochees, setOrgsCochees] = useState<Set<number>>(new Set());
  const [personnesCochees, setPersonnesCochees] = useState<Set<number>>(new Set());
  const basculer = (ensemble: Set<number>, id: number): Set<number> => {
    const suivant = new Set(ensemble);
    if (suivant.has(id)) suivant.delete(id);
    else suivant.add(id);
    return suivant;
  };

  // 2026-10-02 — « la page Contacts ne charge jamais ». Deux règles :
  //
  //  1. `signal` transmis à axios : quitter la page (ou changer de filtre)
  //     ANNULE la requête en vol. React Query n'annule que si le `queryFn`
  //     consomme le signal ; sans lui, chaque visite laissait une requête de
  //     plus de 100 s tourner côté serveur, et elles s'empilaient.
  //  2. Liste et compteurs se chargent et ÉCHOUENT indépendamment : des
  //     compteurs lents ou en panne ne doivent ni retarder ni masquer les
  //     lignes (cf. le rendu plus bas).
  const counts = useQuery<CountsResponse>({
    queryKey: ['crm', 'contacts-hub', 'counts'],
    queryFn: async ({ signal }) =>
      (await api.get<CountsResponse>('/crm/contacts-hub/counts', { signal })).data,
  });

  const list = useQuery<CursorResponse<HubCompany>>({
    queryKey: ['crm', 'contacts-hub', tab, temperature, rechercheDifferee, country, prospection],
    queryFn: async ({ signal }) => {
      const params = new URLSearchParams({ temperature, per_page: '50' });
      if (tab !== 'tous') params.set('relation_type', tab);
      if (rechercheDifferee.trim().length > 0) params.set('q', rechercheDifferee.trim());
      if (country) params.set('filter[country_code]', country);
      if (prospection) params.set('filter[prospection_status]', prospection);
      return (
        await api.get<CursorResponse<HubCompany>>(`/crm/contacts-hub?${params.toString()}`, { signal })
      ).data;
    },
    placeholderData: (previous) => previous,
  });

  const byType = counts.data?.by_relation_type ?? {};
  const byStage = counts.data?.by_lifecycle_stage ?? {};
  // Tant que les compteurs ne sont pas là, on n'affiche PAS « 0 » : ce serait
  // affirmer une base vide. Pastille absente, vignette « … ».
  const compteursLus = counts.data !== undefined;
  const valeurKpi = (n: number | undefined): number | string => (compteursLus ? (n ?? 0) : '…');

  const tabs: Array<TabItem<TabId>> = [
    { id: 'tous', label: 'Tous', ...(compteursLus ? { count: counts.data?.total ?? 0 } : {}) },
    ...RELATION_TYPES.map((type) => ({
      id: type,
      label: RELATION_TYPE_LABELS[type],
      ...(compteursLus ? { count: byType[type] ?? 0 } : {}),
    })),
  ];

  const rows = list.data?.data ?? [];
  const temperatureLabel = TEMPERATURES.find((t) => t.id === temperature)?.label ?? '';

  /**
   * 🔴 D25-001 — l'échec de chargement n'est pas « il n'y a personne ».
   *
   * Sur une base de 4,29 M de fiches, cet écran affichait « Aucun contact dans
   * cette vue » et quatre compteurs à zéro dès que l'API répondait 403 ou 500 —
   * le MÊME texte que sur une vue légitimement vide. Le commentaire ci-dessous
   * (`isPlaceholderData`) avait déjà réparé le mensonge de l'état TRANSITOIRE ;
   * il restait le mensonge de l'état d'ÉCHEC, plus grave car durable.
   *
   * Compteurs et onglets partent avec le reste : leurs effectifs viennent de
   * `counts`, et des filtres qui ne peuvent rien filtrer n'informent personne.
   *
   * ⚠️ `data === undefined` : React Query v5 conserve la dernière réponse
   * réussie quand un rafraîchissement échoue — on n'efface jamais des lignes
   * que l'opérateur avait déjà sous les yeux.
   */
  //
  // 2026-10-02 — chaque requête porte SON échec. Avant, un échec des compteurs
  // remplaçait TOUT l'écran, lignes comprises : des compteurs lents (≈ 20 s à
  // froid sur 4,3 M de fiches) ou en panne rendaient la liste inaccessible.
  // Désormais : compteurs en échec → vignettes retirées (jamais de « 0 »
  // mensonger), une ligne le dit ; liste en échec → l'erreur à la place des
  // lignes seulement.
  const echecListe = list.error !== null && list.data === undefined;
  const echecCompteurs = counts.error !== null && counts.data === undefined;

  return (
    <div className="px-6 py-6">
      <PageHeader
        title="Contacts"
        subtitle={`${tab === 'tous' ? 'Tous les types' : RELATION_TYPE_LABELS[tab]} — ${temperatureLabel}`}
      />

      {echecCompteurs ? (
        <p className="mb-6 text-xs text-slate-500 dark:text-slate-400" role="status">
          Compteurs indisponibles pour le moment — la liste reste consultable.{' '}
          <button type="button" className="underline" onClick={() => void counts.refetch()}>
            Recharger les compteurs
          </button>
        </p>
      ) : (
        <div className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <KpiCard label="Clients" value={valeurKpi(byType['client'])} tone="emerald" />
          <KpiCard label="Prospects" value={valeurKpi(byType['prospect'])} tone="sky" />
          <KpiCard label="Opportunités" value={valeurKpi(byStage['opportunite'])} tone="violet" />
          {/* La base froide est un STOCK, pas une file : ton neutre, jamais de
              pastille rouge (conception §2.3). */}
          <KpiCard label="Dormants" value={valeurKpi(byStage['dormant'])} tone="slate" />
        </div>
      )}

      <Tabs items={tabs} value={tab} onChange={setTab} className="mb-4" />

      <Toolbar
        left={
          <>
            <SearchInput
              label="Rechercher une entreprise, un SIREN ou une personne"
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
        {echecListe ? (
          <QueryErrorState
            error={list.error}
            contexte="la liste des fiches"
            onRetry={() => void list.refetch()}
          />
        ) : null}
        {/* `isLoading` NE SUFFIT PAS ici : `placeholderData` garde les lignes de
            la vue précédente pendant le chargement de la suivante, donc React
            Query considère qu'il y a déjà des données et `isLoading` reste faux.
            Quand la vue précédente était LÉGITIMEMENT vide (« Contacts actifs »
            l'est tant qu'aucune fiche n'a de provenance hors collecte), on
            affichait « Aucun contact dans cette vue » pendant toute la requête
            suivante — soit un mensonge de plusieurs secondes sur une base de
            4,29 M de fiches. `isPlaceholderData` est vrai exactement pendant ce
            créneau : c'est lui qui doit déclencher le squelette. */}
        {echecListe ? null : list.isLoading || list.isPlaceholderData ? (
          <ConsoleListSkeleton />
        ) : rows.length === 0 ? (
          <EmptyState
            title="Aucun contact dans cette vue"
            description="Les fiches arrivent automatiquement depuis le site (formulaires, RDV, avis) — rien à créer ici."
          />
        ) : (
          <Card padding="none" className="overflow-hidden">
            {orgsCochees.size + personnesCochees.size > 0 ? (
              <div className="flex flex-wrap items-center gap-2 border-b border-slate-200 bg-brand-50/60 px-4 py-2 text-sm dark:border-slate-800 dark:bg-slate-800/60">
                <span className="font-medium text-slate-700 dark:text-slate-200">
                  {orgsCochees.size} organisation(s), {personnesCochees.size} personne(s) cochée(s)
                </span>
                <AjouterAUneListe
                  companyIds={[...orgsCochees]}
                  contactIds={[...personnesCochees]}
                  onAjoute={() => {
                    setOrgsCochees(new Set());
                    setPersonnesCochees(new Set());
                  }}
                />
              </div>
            ) : null}
            <ul className="divide-y divide-slate-100 dark:divide-slate-800">
              {rows.map((company) => (
                <li key={company.id} className="px-4 py-3">
                  <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <input
                      type="checkbox"
                      className="self-center"
                      aria-label={`Cocher l’organisation ${company.denomination ?? company.id}`}
                      checked={orgsCochees.has(company.id)}
                      onChange={() => setOrgsCochees((e) => basculer(e, company.id))}
                    />
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

                  {/* D25-011 — `?.` sur une donnee d'API, pas sur un type.
                      `types.ts` declare `contacts` et `tags` obligatoires, mais
                      c'est une promesse de COMPILATION sur une reponse HTTP que
                      personne ne valide a l'execution : une clef absente jette,
                      et emporte l'ecran entier. Mesure du 2026-08-22 : cinq
                      lectures imbriquees sans garde sur la console. */}
                  {(company.contacts?.length ?? 0) > 0 && (
                    <div className="mt-1 flex flex-wrap gap-x-3 text-xs text-slate-600 dark:text-slate-300">
                      {company.contacts.slice(0, 3).map((contact) => (
                        <span key={contact.id} className="inline-flex items-center gap-1">
                          <input
                            type="checkbox"
                            aria-label={`Cocher ${contact.first_name ?? ''} ${contact.last_name}`}
                            checked={personnesCochees.has(contact.id)}
                            onChange={() => setPersonnesCochees((e) => basculer(e, contact.id))}
                          />
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

                  {/* D25-011 — meme raison qu'au-dessus. */}
                  {(company.tags?.length ?? 0) > 0 && (
                    <div className="mt-1 flex flex-wrap gap-1">
                      {company.tags.slice(0, 3).map((slug) => (
                        <span
                          key={slug}
                          className="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300"
                        >
                          {tagLabel(slug)}
                        </span>
                      ))}
                      {(company.tags?.length ?? 0) > 3 && (
                        <span className="text-xs text-slate-400">+{(company.tags?.length ?? 0) - 3}</span>
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
