import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { PageShell } from '@/components/ui/PageShell';
import { QualityBadge } from '@/components/ui/QualityBadge';
import { SizeCategoryBadge } from '@/components/ui/SizeCategoryBadge';
import { CompaniesTableSkeleton } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { api } from '@/lib/api';

interface Company {
  id: number;
  siren: string;
  denomination?: string | null;
  naf?: string | null;
  size_category?: string | null;
  city?: string | null;
  quality_score?: number | null;
  priority?: string | null;
}

export function CompaniesListPage() {
  const [page, setPage] = useState(1);
  const [filter, setFilter] = useState({ size: '', priority: '', search: '' });

  const { data, isLoading } = useQuery({
    queryKey: ['companies', page, filter],
    queryFn: async () => {
      const params = new URLSearchParams({
        page: String(page),
        per_page: '50',
        ...(filter.size ? { 'filter[size_category]': filter.size } : {}),
        ...(filter.priority ? { 'filter[priority]': filter.priority } : {}),
        ...(filter.search ? { 'filter[denomination]': filter.search } : {}),
      });
      const r = await api.get<{ data: Company[]; meta: { total: number; last_page: number } }>(
        `/companies?${params.toString()}`,
      );
      return r.data;
    },
    placeholderData: (prev) => prev,
  });

  return (
    <PageShell
      title="Entreprises"
      subtitle={`${data?.meta.total ?? '…'} entreprises scrapées dans ce workspace`}
      actions={
        <Link to="/coverage" className="rounded-md bg-brand-600 px-3 py-1.5 text-sm text-white hover:bg-brand-700">
          Lancer scraping
        </Link>
      }
    >
      <div className="mb-4 flex flex-wrap gap-2">
        <input
          type="search"
          value={filter.search}
          onChange={(e) => setFilter({ ...filter, search: e.target.value })}
          placeholder="Rechercher dénomination…"
          className="flex-1 min-w-[200px] rounded-md border border-slate-300 px-3 py-1.5 text-sm"
        />
        <select
          value={filter.size}
          onChange={(e) => setFilter({ ...filter, size: e.target.value })}
          className="rounded-md border border-slate-300 px-3 py-1.5 text-sm"
        >
          <option value="">Toutes tailles</option>
          <option value="artisan">Artisan</option>
          <option value="tpe">TPE</option>
          <option value="pme">PME</option>
          <option value="eti">ETI</option>
          <option value="grande_entreprise">Grande</option>
        </select>
        <select
          value={filter.priority}
          onChange={(e) => setFilter({ ...filter, priority: e.target.value })}
          className="rounded-md border border-slate-300 px-3 py-1.5 text-sm"
        >
          <option value="">Toutes priorités</option>
          <option value="haute">Haute</option>
          <option value="moyenne">Moyenne</option>
          <option value="basse">Basse</option>
          <option value="gelee">Gelée</option>
        </select>
      </div>

      {isLoading ? (
        <CompaniesTableSkeleton />
      ) : (data?.data ?? []).length === 0 ? (
        <EmptyState
          icon="🏢"
          title="Aucune entreprise"
          description="Lance un scraping depuis la carte de couverture France pour découvrir des entreprises."
          action={
            <Link to="/coverage" className="rounded-md bg-brand-600 px-3 py-1.5 text-sm text-white">
              Aller à la couverture →
            </Link>
          }
        />
      ) : (
        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
          <table className="min-w-full divide-y divide-slate-200 text-sm">
            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-600">
              <tr>
                <th className="px-4 py-3 font-medium">Dénomination</th>
                <th className="px-4 py-3 font-medium">SIREN</th>
                <th className="px-4 py-3 font-medium">NAF</th>
                <th className="px-4 py-3 font-medium">Taille</th>
                <th className="px-4 py-3 font-medium">Qualité</th>
                <th className="px-4 py-3 font-medium">Ville</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {data!.data.map((c) => (
                <tr key={c.id} className="hover:bg-slate-50">
                  <td className="px-4 py-3">
                    <Link to="/companies/$companyId" params={{ companyId: String(c.id) }} className="font-medium text-brand-700 hover:underline">
                      {c.denomination ?? '—'}
                    </Link>
                  </td>
                  <td className="px-4 py-3 font-mono text-xs">{c.siren}</td>
                  <td className="px-4 py-3">{c.naf ?? '—'}</td>
                  <td className="px-4 py-3"><SizeCategoryBadge size={c.size_category} /></td>
                  <td className="px-4 py-3"><QualityBadge score={c.quality_score ?? undefined} /></td>
                  <td className="px-4 py-3 text-slate-600">{c.city ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {data && data.meta.last_page > 1 ? (
        <div className="mt-4 flex items-center justify-between text-sm">
          <button
            disabled={page <= 1}
            onClick={() => setPage((p) => Math.max(1, p - 1))}
            className="rounded-md border border-slate-300 px-3 py-1 disabled:opacity-50"
          >
            Précédent
          </button>
          <span className="text-slate-600">Page {page} / {data.meta.last_page}</span>
          <button
            disabled={page >= data.meta.last_page}
            onClick={() => setPage((p) => p + 1)}
            className="rounded-md border border-slate-300 px-3 py-1 disabled:opacity-50"
          >
            Suivant
          </button>
        </div>
      ) : null}
    </PageShell>
  );
}
