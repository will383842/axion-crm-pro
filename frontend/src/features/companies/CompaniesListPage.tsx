import { useRef, useState, useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { useVirtualizer } from '@tanstack/react-virtual';
import {
  Button,
  Card,
  CompaniesTableSkeleton,
  EmptyState,
  KpiCard,
  PageHeader,
  SearchInput,
  Toolbar,
  cn,
} from '@/components/ui';
import { api } from '@/lib/api';
import { CompanyRow, COMPANY_ROW_GRID, type CompanyRowData } from './components/CompanyRow';
import { Pagination } from './components/Pagination';

type Company = CompanyRowData & {
  discovery_source?: string | null;
};

interface CompaniesResponse {
  data: Company[];
  meta: {
    total: number;
    last_page: number;
    current_page?: number;
    per_page?: number;
  };
}

const ROW_HEIGHT = 56;
const GRID = COMPANY_ROW_GRID;

const SIZE_OPTIONS = [
  { value: '', label: 'Toutes tailles' },
  { value: 'artisan', label: 'Artisan' },
  { value: 'tpe', label: 'TPE' },
  { value: 'pme', label: 'PME' },
  { value: 'eti', label: 'ETI' },
  { value: 'grande_entreprise', label: 'Grande entreprise' },
];

const QUALITY_OPTIONS = [
  { value: '', label: 'Toutes qualités' },
  { value: 'complete', label: '🟢 Complète (≥ 90)' },
  { value: 'partielle', label: '🟡 Partielle (50-89)' },
  { value: 'basique', label: '🔴 Basique (< 50)' },
];

const PRIORITY_OPTIONS = [
  { value: '', label: 'Toutes priorités' },
  { value: 'haute', label: 'Haute' },
  { value: 'moyenne', label: 'Moyenne' },
  { value: 'basse', label: 'Basse' },
  { value: 'gelee', label: 'Gelée' },
];

interface Filter {
  size: string;
  priority: string;
  search: string;
  naf: string;
  quality: string;
}

const EMPTY_FILTER: Filter = { size: '', priority: '', search: '', naf: '', quality: '' };

export function CompaniesListPage() {
  const [page, setPage] = useState(1);
  const [filter, setFilter] = useState<Filter>(EMPTY_FILTER);

  const { data, isLoading } = useQuery<CompaniesResponse>({
    queryKey: ['companies', page, filter],
    queryFn: async () => {
      const params = new URLSearchParams({
        page: String(page),
        per_page: '100',
        ...(filter.size ? { 'filter[size_category]': filter.size } : {}),
        ...(filter.priority ? { 'filter[priority]': filter.priority } : {}),
        ...(filter.search ? { 'filter[denomination]': filter.search } : {}),
        ...(filter.naf ? { 'filter[naf]': filter.naf } : {}),
        ...(filter.quality ? { 'filter[quality]': filter.quality } : {}),
      });
      const r = await api.get<CompaniesResponse>(`/companies?${params.toString()}`);
      return r.data;
    },
    placeholderData: (prev) => prev,
  });

  const rows = useMemo(() => data?.data ?? [], [data]);
  const total = data?.meta.total;
  const lastPage = data?.meta.last_page ?? 1;

  const parentRef = useRef<HTMLDivElement | null>(null);
  const rowVirtualizer = useVirtualizer({
    count: rows.length,
    getScrollElement: () => parentRef.current,
    estimateSize: () => ROW_HEIGHT,
    overscan: 8,
  });

  // KPI derivations (sample on the current page only — backend should expose
  // aggregated stats for full accuracy, but page-level values give a useful
  // signal in the meantime).
  const kpis = useMemo(() => {
    const list = rows;
    const count = list.length;
    const enriched = list.filter((c) => c.enriched_at).length;
    const enrichedPct = count > 0 ? Math.round((enriched / count) * 100) : 0;

    const bySize = list.reduce<Record<string, number>>((acc, c) => {
      const k = c.size_category ?? 'inconnue';
      acc[k] = (acc[k] ?? 0) + 1;
      return acc;
    }, {});
    const topSize = Object.entries(bySize).sort((a, b) => b[1] - a[1])[0];
    const topSizeLabel = topSize ? topSize[0].toUpperCase() : '—';
    const topSizePct = topSize && count > 0 ? Math.round((topSize[1] / count) * 100) : 0;

    const byNaf = list.reduce<Record<string, number>>((acc, c) => {
      if (!c.naf) return acc;
      acc[c.naf] = (acc[c.naf] ?? 0) + 1;
      return acc;
    }, {});
    const topNaf = Object.entries(byNaf).sort((a, b) => b[1] - a[1])[0];

    return {
      total: total ?? count,
      enrichedPct,
      topSizeLabel,
      topSizePct,
      topNaf: topNaf ? topNaf[0] : '—',
      topNafCount: topNaf ? topNaf[1] : 0,
    };
  }, [rows, total]);

  const setFilterAndReset = (next: Partial<Filter>) => {
    setFilter((f) => ({ ...f, ...next }));
    setPage(1);
  };

  const hasActiveFilter = filter.search || filter.size || filter.priority || filter.naf || filter.quality;

  return (
    <div className="px-6 py-6">
      <PageHeader
        title="Entreprises"
        subtitle={
          <>
            Pipeline de prospection · <span className="font-semibold tabular-nums text-slate-700 dark:text-slate-200">
              {(total ?? 0).toLocaleString('fr-FR')}
            </span> entreprises actives
          </>
        }
        actions={
          <div className="flex items-center gap-2">
            <Button variant="secondary" size="md" iconLeft={<UploadIcon />}>
              Importer
            </Button>
            <Button variant="secondary" size="md" iconLeft={<DownloadIcon />}>
              Exporter
            </Button>
            <Link
              to="/coverage"
              className="inline-flex h-9 items-center justify-center gap-2 rounded-lg bg-gradient-to-b from-slate-900 to-slate-800 px-4 text-sm font-medium text-white shadow-sm hover:from-slate-800 hover:to-slate-700 dark:from-white dark:to-slate-100 dark:text-slate-900"
            >
              Lancer scraping →
            </Link>
          </div>
        }
      />

      {/* KPI strip */}
      <div className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <KpiCard
          tone="sky"
          label="Total"
          value={(total ?? 0).toLocaleString('fr-FR')}
          sublabel={`Page ${page} · ${rows.length} affichées`}
        />
        <KpiCard
          tone="violet"
          label="Enrichies"
          value={`${kpis.enrichedPct}%`}
          sublabel="dont email + téléphone vérifiés"
          progress={kpis.enrichedPct}
        />
        <KpiCard
          tone="emerald"
          label="Top taille"
          value={kpis.topSizeLabel}
          sublabel={`${kpis.topSizePct}% de l'échantillon`}
          progress={kpis.topSizePct}
        />
        <KpiCard
          tone="amber"
          label="Top NAF"
          value={kpis.topNaf}
          sublabel={kpis.topNafCount ? `${kpis.topNafCount} sociétés` : '—'}
        />
      </div>

      {/* Toolbar */}
      <Toolbar
        left={
          <>
            <SearchInput
              value={filter.search}
              onChange={(v) => setFilterAndReset({ search: v })}
              placeholder="Rechercher une entreprise…"
              className="w-72"
            />
            <FilterSelect
              value={filter.size}
              onChange={(v) => setFilterAndReset({ size: v })}
              options={SIZE_OPTIONS}
              ariaLabel="Filtre taille"
            />
            <FilterSelect
              value={filter.quality}
              onChange={(v) => setFilterAndReset({ quality: v })}
              options={QUALITY_OPTIONS}
              ariaLabel="Filtre qualité"
            />
            <FilterSelect
              value={filter.priority}
              onChange={(v) => setFilterAndReset({ priority: v })}
              options={PRIORITY_OPTIONS}
              ariaLabel="Filtre priorité"
            />
            <input
              type="text"
              value={filter.naf}
              onChange={(e) => setFilterAndReset({ naf: e.target.value })}
              placeholder="Code NAF…"
              aria-label="Filtre NAF"
              className="h-9 w-28 rounded-lg bg-white px-3 font-mono text-xs text-slate-900 ring-1 ring-slate-200 transition placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:bg-slate-900 dark:text-white dark:ring-slate-700 dark:focus:ring-slate-600"
            />
          </>
        }
        right={
          hasActiveFilter ? (
            <Button variant="ghost" size="sm" onClick={() => { setFilter(EMPTY_FILTER); setPage(1); }}>
              Réinitialiser les filtres
            </Button>
          ) : null
        }
      />

      {isLoading ? (
        <CompaniesTableSkeleton />
      ) : rows.length === 0 ? (
        <EmptyState
          icon="🏢"
          title="Aucune entreprise"
          description={
            hasActiveFilter
              ? 'Aucune entreprise ne correspond à ces filtres. Réinitialise pour voir plus de résultats.'
              : 'Lance un scraping depuis la carte de couverture France pour découvrir des entreprises.'
          }
          action={
            <Link
              to="/coverage"
              className="inline-flex h-9 items-center justify-center rounded-lg bg-gradient-to-b from-slate-900 to-slate-800 px-4 text-sm font-medium text-white"
            >
              Aller à la couverture →
            </Link>
          }
        />
      ) : (
        <Card padding="none" className="overflow-hidden">
          {/* Sticky header — must share GRID with CompanyRow */}
          <div
            role="row"
            className={cn(
              'sticky top-0 z-10 grid items-center gap-3 border-b border-slate-200 bg-slate-50/80 px-4 py-3 text-[11px] font-semibold uppercase tracking-wider text-slate-600 backdrop-blur',
              'dark:border-slate-800 dark:bg-slate-900/80 dark:text-slate-400',
            )}
            style={{ gridTemplateColumns: GRID }}
          >
            <div>Entreprise</div>
            <div className="font-mono">SIREN</div>
            <div>NAF</div>
            <div>Taille</div>
            <div>Qualité</div>
            <div>Ville</div>
            <div>Enrichi</div>
            <div className="sr-only">Actions</div>
          </div>

          {/* Virtualised body — DO NOT remove the absolute positioning trick,
              it's what keeps perf flat with @tanstack/react-virtual. */}
          <div
            ref={parentRef}
            className="h-[600px] overflow-auto"
            role="rowgroup"
            aria-rowcount={rows.length}
          >
            <div style={{ height: rowVirtualizer.getTotalSize(), position: 'relative' }}>
              {rowVirtualizer.getVirtualItems().map((vrow) => {
                const c = rows[vrow.index];
                if (!c) return null;
                return (
                  <div
                    key={c.id}
                    aria-rowindex={vrow.index + 1}
                    style={{
                      position: 'absolute',
                      top: 0,
                      left: 0,
                      width: '100%',
                      transform: `translateY(${vrow.start}px)`,
                      height: `${vrow.size}px`,
                    }}
                  >
                    <CompanyRow company={c} />
                  </div>
                );
              })}
            </div>
          </div>
        </Card>
      )}

      <Pagination
        page={page}
        lastPage={lastPage}
        total={total}
        onChange={setPage}
      />
    </div>
  );
}

function FilterSelect({
  value,
  onChange,
  options,
  ariaLabel,
}: {
  value: string;
  onChange: (v: string) => void;
  options: Array<{ value: string; label: string }>;
  ariaLabel: string;
}) {
  return (
    <select
      value={value}
      onChange={(e) => onChange(e.target.value)}
      aria-label={ariaLabel}
      className="h-9 rounded-lg bg-white px-2 pr-7 text-sm text-slate-900 ring-1 ring-slate-200 transition focus:outline-none focus:ring-2 focus:ring-slate-300 dark:bg-slate-900 dark:text-white dark:ring-slate-700 dark:focus:ring-slate-600"
    >
      {options.map((o) => (
        <option key={o.value} value={o.value}>{o.label}</option>
      ))}
    </select>
  );
}

function UploadIcon() {
  return (
    <svg viewBox="0 0 20 20" className="h-3.5 w-3.5" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden>
      <path d="M10 14V4M5 9l5-5 5 5M4 16h12" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

function DownloadIcon() {
  return (
    <svg viewBox="0 0 20 20" className="h-3.5 w-3.5" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden>
      <path d="M10 4v10M5 9l5 5 5-5M4 16h12" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}
