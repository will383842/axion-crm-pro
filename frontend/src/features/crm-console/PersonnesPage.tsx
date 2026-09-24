/**
 * ✉️ PERSONNES (LETTRE ET GUIDE) — lot L4-C.
 *
 * Les personnes arrivées par la lettre ou par le guide, SANS entreprise.
 * Écran délibérément distinct des audiences (`AudienceDetailPage`, qui liste
 * des entreprises) : une personne n'entre dans le hub de contacts et dans les
 * audiences qu'APRÈS avoir été rattachée à une entreprise.
 *
 * Segments : statut de la lettre, source, nature de l'adresse, rattachée ou
 * non. L'export suit le filtre ; les coordonnées n'en sortent en clair qu'avec
 * le droit « voir les coordonnées complètes », et une personne opposée ou dont
 * l'adresse est morte n'en sort jamais.
 */
import { useState, type ChangeEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { toast } from 'sonner';
import {
  Button,
  Card,
  EmptyState,
  KpiCard,
  PageHeader,
  QueryErrorState,
  SearchInput,
  Toolbar,
} from '@/components/ui';
import { api } from '@/lib/api';
import { useAntiRebond } from '@/hooks/useAntiRebond';
import { ConsoleGate, ConsoleListSkeleton } from './ConsoleGate';
import {
  NATURE_EMAIL_LABELS,
  SOURCE_PERSONNE_LABELS,
  STATUT_LETTRE_LABELS,
  type CursorResponse,
  type PersonneRow,
  type PersonnesCounts,
} from './types';

const SELECT_CLS =
  'h-11 rounded-lg bg-white px-3 text-xs text-slate-900 ring-1 ring-slate-200 transition focus:outline-none focus:ring-2 focus:ring-slate-300 dark:bg-slate-900 dark:text-white dark:ring-slate-700';

interface Filtres {
  statut_lettre: string;
  source: string;
  nature: string;
  rattachee: string;
}

const FILTRES_VIDES: Filtres = { statut_lettre: '', source: '', nature: '', rattachee: '' };

function formatDate(value: string | null): string {
  if (value === null) return '—';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString('fr-FR');
}

function parametres(filtres: Filtres, recherche: string): URLSearchParams {
  const params = new URLSearchParams();
  for (const cle of Object.keys(filtres) as Array<keyof Filtres>) {
    const valeur = filtres[cle];
    if (valeur !== '') params.set(cle, valeur);
  }
  if (recherche.trim().length > 0) params.set('q', recherche.trim());
  return params;
}

export function PersonnesPage() {
  return (
    <ConsoleGate>
      <PersonnesContent />
    </ConsoleGate>
  );
}

function PersonnesContent() {
  const [filtres, setFiltres] = useState<Filtres>(FILTRES_VIDES);
  const [search, setSearch] = useState('');
  const rechercheDifferee = useAntiRebond(search);
  const [exporting, setExporting] = useState(false);

  const counts = useQuery<PersonnesCounts>({
    queryKey: ['crm', 'personnes', 'counts'],
    queryFn: async () => (await api.get<PersonnesCounts>('/crm/personnes/counts')).data,
  });

  const list = useQuery<CursorResponse<PersonneRow>>({
    queryKey: ['crm', 'personnes', filtres, rechercheDifferee],
    queryFn: async () => {
      const params = parametres(filtres, rechercheDifferee);
      params.set('per_page', '50');
      return (await api.get<CursorResponse<PersonneRow>>(`/crm/personnes?${params.toString()}`)).data;
    },
    placeholderData: (previous) => previous,
  });

  async function exporter() {
    setExporting(true);
    try {
      const params = parametres(filtres, rechercheDifferee);
      const r = await api.get<Blob>(`/crm/personnes/export?${params.toString()}`, { responseType: 'blob' });
      const url = URL.createObjectURL(r.data);
      const a = document.createElement('a');
      a.href = url;
      a.download = `personnes-lettre-guide-${new Date().toISOString().slice(0, 10)}.csv`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
      toast.success('Export CSV téléchargé');
    } catch {
      toast.error('Export impossible : droit d’export requis.');
    } finally {
      setExporting(false);
    }
  }

  const echec =
    (list.error !== null && list.data === undefined) || (counts.error !== null && counts.data === undefined);
  const rows = list.data?.data ?? [];
  const c = counts.data;
  const sources = Object.keys(c?.by_source ?? {});

  const changer = (cle: keyof Filtres) => (e: ChangeEvent<HTMLSelectElement>) =>
    setFiltres((f) => ({ ...f, [cle]: e.target.value }));

  return (
    <div className="px-6 py-6">
      <PageHeader
        title="Personnes (lettre et guide)"
        subtitle="Abonnés à la lettre et demandeurs du guide, sans entreprise. Ils rejoignent le hub de contacts une fois rattachés."
      />

      {echec ? (
        <QueryErrorState
          error={list.error ?? counts.error}
          contexte="la liste des personnes"
          onRetry={() => {
            void list.refetch();
            void counts.refetch();
          }}
        />
      ) : (
        <>
          <div className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <KpiCard label="Abonnés à la lettre" value={c?.by_statut_lettre.abonne ?? 0} tone="sky" />
            <KpiCard label="Demandeurs du guide sans abonnement" value={c?.by_statut_lettre.aucun ?? 0} tone="violet" />
            <KpiCard label="À rattacher" value={c?.non_rattachees ?? 0} tone="amber" />
            <KpiCard label="Désabonnés" value={c?.by_statut_lettre.desabonne ?? 0} tone="slate" />
          </div>

          <Toolbar
            left={
              <>
                <SearchInput
                  label="Rechercher une personne"
                  value={search}
                  onChange={setSearch}
                  placeholder="Adresse, nom…"
                  className="w-64"
                />
                <select value={filtres.statut_lettre} onChange={changer('statut_lettre')} aria-label="Filtre statut de la lettre" className={SELECT_CLS}>
                  <option value="">Tous les statuts</option>
                  <option value="abonne">{STATUT_LETTRE_LABELS.abonne}</option>
                  <option value="desabonne">{STATUT_LETTRE_LABELS.desabonne}</option>
                  <option value="aucun">{STATUT_LETTRE_LABELS.aucun}</option>
                </select>
                <select value={filtres.source} onChange={changer('source')} aria-label="Filtre source" className={SELECT_CLS}>
                  <option value="">Toutes les sources</option>
                  {sources.map((s) => (
                    <option key={s} value={s}>
                      {SOURCE_PERSONNE_LABELS[s] ?? s}
                    </option>
                  ))}
                </select>
                <select value={filtres.nature} onChange={changer('nature')} aria-label="Filtre nature de l’adresse" className={SELECT_CLS}>
                  <option value="">Toutes les adresses</option>
                  <option value="pro">{NATURE_EMAIL_LABELS.pro}</option>
                  <option value="perso">{NATURE_EMAIL_LABELS.perso}</option>
                  <option value="inconnue">{NATURE_EMAIL_LABELS.inconnue}</option>
                </select>
                <select value={filtres.rattachee} onChange={changer('rattachee')} aria-label="Filtre rattachement" className={SELECT_CLS}>
                  <option value="">Rattachées ou non</option>
                  <option value="non">À rattacher</option>
                  <option value="oui">Rattachées à une entreprise</option>
                </select>
              </>
            }
            right={
              <Button variant="secondary" size="sm" disabled={exporting} onClick={() => void exporter()}>
                {exporting ? 'Export…' : 'Exporter le segment (CSV)'}
              </Button>
            }
          />

          <div className="mt-4">
            {list.isLoading || list.isPlaceholderData ? (
              <ConsoleListSkeleton />
            ) : rows.length === 0 ? (
              <EmptyState
                title="Aucune personne dans ce segment"
                description="Les personnes arrivent depuis le site (lettre confirmée, guide téléchargé) une fois le flux ouvert : rien à créer ici."
              />
            ) : (
              <Card padding="none" className="overflow-hidden">
                <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                  {rows.map((p) => (
                    <li key={p.id} className="px-4 py-3">
                      <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <Link
                          to="/console/lettre-et-guide/$personneId"
                          params={{ personneId: String(p.id) }}
                          className="text-sm font-semibold text-slate-900 underline decoration-dotted underline-offset-2 hover:text-brand-600 dark:text-white"
                        >
                          {[p.first_name, p.last_name].filter(Boolean).join(' ') || p.email || `Personne ${p.id}`}
                        </Link>
                        <span className="text-xs text-slate-500 dark:text-slate-400">
                          {STATUT_LETTRE_LABELS[p.statut_lettre ?? 'aucun']} ·{' '}
                          {SOURCE_PERSONNE_LABELS[p.premiere_source] ?? p.premiere_source} ·{' '}
                          {NATURE_EMAIL_LABELS[p.email_nature]}
                        </span>
                        {p.rattachee ? (
                          <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                            Rattachée · {p.entreprise ?? 'entreprise'}
                          </span>
                        ) : (
                          <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] text-amber-700 dark:bg-amber-950 dark:text-amber-300">
                            À rattacher
                          </span>
                        )}
                      </div>
                      <div className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        {p.email ?? '—'} · Dernière interaction : {formatDate(p.derniere_interaction_at)}
                        {p.purge_prevue_le !== null && <> · Purge prévue le {formatDate(p.purge_prevue_le)}</>}
                      </div>
                    </li>
                  ))}
                </ul>
              </Card>
            )}
          </div>
        </>
      )}
    </div>
  );
}
