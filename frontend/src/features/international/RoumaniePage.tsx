/**
 * PROSPECTION ROUMANIE — vue dédiée au vivier « présence française en
 * Roumanie » (campagne 2026-08-15).
 *
 * Pourquoi une page à part plutôt qu'un filtre de la liste Entreprises :
 * 546 fiches noyées dans 4,29 M de fiches françaises sont introuvables en
 * pratique. Une entrée de menu + des sous-onglets par NATURE (entreprises,
 * associations, chambres de commerce…) rendent le vivier utilisable pour
 * lancer une campagne e-mail ciblée.
 *
 * La page ne fait que PRÉ-FILTRER l'API existante (`filter[country_code]`,
 * `filter[entity_nature]`) : aucune requête nouvelle, aucun endpoint dédié,
 * donc rien à maintenir en double avec la liste Entreprises.
 */
import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { useState } from 'react';

import { PageHeader } from '@/components/ui/PageHeader';
import { api } from '@/lib/api';

type NatureTab = {
  value: string;
  label: string;
};

/**
 * Sous-onglets. `''` = tout le pays. L'ordre suit le volume réel du vivier
 * (entreprises d'abord), pas l'ordre alphabétique.
 */
const NATURE_TABS: NatureTab[] = [
  { value: '', label: 'Tout' },
  { value: 'entreprise', label: 'Entreprises' },
  { value: 'association', label: 'Associations' },
  { value: 'cabinet', label: 'Cabinets' },
  { value: 'enseignement', label: 'Enseignement' },
  { value: 'institution', label: 'Institutions' },
  { value: 'cci', label: 'Chambres de commerce' },
  { value: 'media', label: 'Médias' },
];

type CompanyRow = {
  id: number;
  denomination: string | null;
  city: string | null;
  website: string | null;
  phone: string | null;
  email_generic: string | null;
  entity_nature: string | null;
  prospection_status: string | null;
  siren: string | null;
};

type ListResponse = {
  data: CompanyRow[];
  meta?: { total?: number };
};

export function RoumaniePage() {
  const [nature, setNature] = useState('');
  const [onlyContactable, setOnlyContactable] = useState(false);
  const [page, setPage] = useState(1);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['roumanie', nature, onlyContactable, page],
    queryFn: async () => {
      const params = new URLSearchParams({
        'filter[country_code]': 'RO',
        page: String(page),
        per_page: '50',
        ...(nature ? { 'filter[entity_nature]': nature } : {}),
        // `ready_for_outreach` est le statut que le CRM pose lui-même dès
        // qu'une fiche a un canal e-mail exploitable : c'est LA liste de
        // départ d'une campagne, par opposition aux fiches sans adresse.
        ...(onlyContactable ? { 'filter[prospection_status]': 'ready_for_outreach' } : {}),
      });

      return (await api.get<ListResponse>(`/companies?${params.toString()}`)).data;
    },
  });

  function selectNature(value: string) {
    setNature(value);
    // Sans ce reset, passer d'un onglet volumineux à un onglet court laisse
    // l'utilisateur sur une page 7 vide, qui se lit comme « aucun résultat ».
    setPage(1);
  }

  function toggleContactable(checked: boolean) {
    setOnlyContactable(checked);
    setPage(1);
  }

  const rows = data?.data ?? [];
  const total = data?.meta?.total ?? rows.length;

  return (
    <div className="px-6 py-6">
      <PageHeader
        title="Présence française — Roumanie"
        subtitle="Entreprises françaises implantées, entités locales et organismes francophones. Collecte : annuaire public CCIFER + DG Trésor."
      />

      <div className="mb-4 flex flex-wrap gap-2" role="tablist" aria-label="Nature d'entité">
        {NATURE_TABS.map((tab) => (
          <button
            key={tab.value || 'all'}
            type="button"
            role="tab"
            aria-selected={nature === tab.value}
            onClick={() => selectNature(tab.value)}
            className={
              nature === tab.value
                ? 'rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white'
                : 'rounded-md bg-slate-100 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-200'
            }
          >
            {tab.label}
          </button>
        ))}
      </div>

      <label className="mb-4 flex w-fit items-center gap-2 text-sm text-slate-700">
        <input
          type="checkbox"
          checked={onlyContactable}
          onChange={(e) => toggleContactable(e.target.checked)}
          className="h-4 w-4 rounded border-slate-300"
        />
        Contactables uniquement (avec e-mail)
      </label>

      {isLoading && <p className="text-sm text-slate-500">Chargement…</p>}
      {isError && <p className="text-sm text-red-600">Impossible de charger le vivier Roumanie.</p>}

      {!isLoading && !isError && (
        <>
          <p className="mb-3 text-sm text-slate-600">
            {total} fiche{total > 1 ? 's' : ''}
          </p>

          <div className="overflow-x-auto rounded-lg border border-slate-200">
            <table className="min-w-full text-sm">
              <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
                <tr>
                  <th className="px-3 py-2">Dénomination</th>
                  <th className="px-3 py-2">Nature</th>
                  <th className="px-3 py-2">Ville</th>
                  <th className="px-3 py-2">E-mail</th>
                  <th className="px-3 py-2">Téléphone</th>
                  <th className="px-3 py-2">Site</th>
                  <th className="px-3 py-2">Statut</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id} className="border-t border-slate-100">
                    <td className="px-3 py-2">
                      <Link
                        to="/companies/$companyId"
                        params={{ companyId: String(row.id) }}
                        className="text-sky-700 hover:underline"
                      >
                        {row.denomination ?? '—'}
                      </Link>
                    </td>
                    <td className="px-3 py-2 text-slate-600">{row.entity_nature ?? '—'}</td>
                    <td className="px-3 py-2 text-slate-600">{row.city ?? '—'}</td>
                    <td className="px-3 py-2 text-slate-600">{row.email_generic ?? '—'}</td>
                    <td className="px-3 py-2 text-slate-600">{row.phone ?? '—'}</td>
                    <td className="px-3 py-2">
                      {row.website ? (
                        <a
                          href={row.website}
                          target="_blank"
                          rel="noreferrer noopener"
                          className="text-sky-700 hover:underline"
                        >
                          Voir
                        </a>
                      ) : (
                        '—'
                      )}
                    </td>
                    <td className="px-3 py-2">
                      {row.prospection_status === 'ready_for_outreach' ? (
                        <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800">
                          Contactable
                        </span>
                      ) : (
                        <span className="text-xs text-slate-400">Sans e-mail</span>
                      )}
                    </td>
                  </tr>
                ))}
                {rows.length === 0 && (
                  <tr>
                    <td colSpan={7} className="px-3 py-6 text-center text-slate-500">
                      Aucune fiche pour cette nature.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>

          <div className="mt-4 flex items-center gap-2">
            <button
              type="button"
              disabled={page <= 1}
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              className="rounded-md border border-slate-300 px-3 py-1.5 text-sm disabled:opacity-40"
            >
              Précédent
            </button>
            <span className="text-sm text-slate-600">Page {page}</span>
            <button
              type="button"
              disabled={rows.length < 50}
              onClick={() => setPage((p) => p + 1)}
              className="rounded-md border border-slate-300 px-3 py-1.5 text-sm disabled:opacity-40"
            >
              Suivant
            </button>
          </div>
        </>
      )}
    </div>
  );
}
