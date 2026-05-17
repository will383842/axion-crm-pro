import { useRef, useState, useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { useVirtualizer } from '@tanstack/react-virtual';
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
  postcode?: string | null;
  quality_score?: number | null;
  priority?: string | null;
  discovery_source?: string | null;
  enriched_at?: string | null;
}

const ROW_HEIGHT = 56;

export function CompaniesListPage() {
  const [page, setPage] = useState(1);
  const [filter, setFilter] = useState({ size: '', priority: '', search: '', naf: '' });

  const { data, isLoading } = useQuery({
    queryKey: ['companies', page, filter],
    queryFn: async () => {
      const params = new URLSearchParams({
        page: String(page),
        per_page: '100',
        ...(filter.size ? { 'filter[size_category]': filter.size } : {}),
        ...(filter.priority ? { 'filter[priority]': filter.priority } : {}),
        ...(filter.search ? { 'filter[denomination]': filter.search } : {}),
        ...(filter.naf ? { 'filter[naf]': filter.naf } : {}),
      });
      const r = await api.get<{ data: Company[]; meta: { total: number; last_page: number } }>(
        `/companies?${params.toString()}`,
      );
      return r.data;
    },
    placeholderData: (prev) => prev,
  });

  const rows = useMemo(() => data?.data ?? [], [data]);

  const parentRef = useRef<HTMLDivElement | null>(null);
  const rowVirtualizer = useVirtualizer({
    count: rows.length,
    getScrollElement: () => parentRef.current,
    estimateSize: () => ROW_HEIGHT,
    overscan: 8,
  });

  return (
    <PageShell
      title="Entreprises"
      subtitle={`${data?.meta.total ?? '…'} entreprises dans ce workspace · page ${page}/${data?.meta.last_page ?? '?'}`}
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
          onChange={(e) => { setFilter({ ...filter, search: e.target.value }); setPage(1); }}
          placeholder="Dénomination…"
          aria-label="Recherche dénomination"
          className="flex-1 min-w-[200px] rounded-md border border-slate-300 px-3 py-1.5 text-sm"
        />
        <input
          type="text"
          value={filter.naf}
          onChange={(e) => { setFilter({ ...filter, naf: e.target.value }); setPage(1); }}
          placeholder="Code NAF…"
          aria-label="Filtre NAF"
          className="w-32 rounded-md border border-slate-300 px-3 py-1.5 text-sm font-mono"
        />
        <select
          value={filter.size}
          onChange={(e) => { setFilter({ ...filter, size: e.target.value }); setPage(1); }}
          aria-label="Filtre taille"
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
          onChange={(e) => { setFilter({ ...filter, priority: e.target.value }); setPage(1); }}
          aria-label="Filtre priorité"
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
      ) : rows.length === 0 ? (
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
          <div
            role="row"
            className="sticky top-0 z-10 grid gap-2 border-b border-slate-200 bg-slate-50 px-4 py-3 text-xs font-medium uppercase tracking-wide text-slate-600"
            style={{ gridTemplateColumns: '2fr 100px 100px 110px 130px 1.2fr 110px' }}
          >
            <div>Dénomination</div>
            <div className="font-mono">SIREN</div>
            <div>NAF</div>
            <div>Taille</div>
            <div>Qualité</div>
            <div>Ville</div>
            <div>Enrichi</div>
          </div>

          <div ref={parentRef} className="h-[600px] overflow-auto" role="rowgroup" aria-rowcount={rows.length}>
            <div style={{ height: rowVirtualizer.getTotalSize(), position: 'relative' }}>
              {rowVirtualizer.getVirtualItems().map((vrow) => {
                const c = rows[vrow.index];
                if (!c) return null;
                return (
                  <div
                    key={c.id}
                    role="row"
                    aria-rowindex={vrow.index + 1}
                    className="grid gap-2 border-b border-slate-100 px-4 py-3 text-sm hover:bg-slate-50"
                    style={{
                      gridTemplateColumns: '2fr 100px 100px 110px 130px 1.2fr 110px',
                      position: 'absolute',
                      top: 0,
                      left: 0,
                      width: '100%',
                      transform: `translateY(${vrow.start}px)`,
                      height: `${vrow.size}px`,
                    }}
                  >
                    <div className="truncate">
                      <Link
                        to="/companies/$companyId"
                        params={{ companyId: String(c.id) }}
                        className="font-medium text-brand-700 hover:underline"
                      >
                        {c.denomination ?? '—'}
                      </Link>
                    </div>
                    <div className="font-mono text-xs text-slate-600">{c.siren}</div>
                    <div className="text-slate-700">{c.naf ?? '—'}</div>
                    <div><SizeCategoryBadge size={c.size_category} /></div>
                    <div><QualityBadge score={c.quality_score ?? undefined} /></div>
                    <div className="truncate text-slate-600">
                      {c.city ?? '—'} {c.postcode ? <span className="text-xs text-slate-400">({c.postcode})</span> : null}
                    </div>
                    <div className="text-xs text-slate-500">
                      {c.enriched_at ? new Date(c.enriched_at).toLocaleDateString('fr-FR') : '—'}
                    </div>
                  </div>
                );
              })}
            </div>
          </div>
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
          <span className="text-slate-600">
            Page {page} / {data.meta.last_page} · {data.meta.total} au total
          </span>
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
