import { useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Link } from "@tanstack/react-router";
import { Button, Card, EmptyState, KpiCard, PageHeader, SearchInput, cn } from "@/components/ui";
import { api } from "@/lib/api";
import { toast } from "sonner";

export interface MediaItem {
  id: number;
  name: string;
  media_type: string | null;
  periodicity: string | null;
  editorial_theme: string | null;
  diffusion_zone: string | null;
  department_code: string | null;
  city: string | null;
  publisher: string | null;
  website: string | null;
  email: string | null;
  phone: string | null;
  cppap_number: string | null;
  enrich_status: string | null;
}

interface MediaResponse {
  data: MediaItem[];
  meta: { total: number; last_page: number; current_page?: number; per_page?: number };
}

export const MEDIA_TYPE_OPTIONS = [
  { value: "", label: "Tous types" },
  { value: "presse_journal", label: "📰 Journal" },
  { value: "presse_revue", label: "📓 Revue / périodique" },
  { value: "presse_autre", label: "🗞️ Autre presse" },
  { value: "radio", label: "📻 Radio" },
  { value: "tv", label: "📺 Télévision" },
  { value: "tv_emission", label: "🎬 Émission TV" },
  { value: "agence_presse", label: "🛰️ Agence de presse" },
  { value: "portail_web", label: "🌐 Portail / site info" },
  { value: "blog", label: "✍️ Blog" },
  { value: "production_audiovisuelle", label: "🎥 Production audiovisuelle" },
];

const PERIODICITY_OPTIONS = [
  { value: "", label: "Toute périodicité" },
  { value: "quotidien", label: "Quotidien" },
  { value: "hebdomadaire", label: "Hebdomadaire" },
  { value: "mensuel", label: "Mensuel" },
  { value: "bimensuel", label: "Bimensuel" },
  { value: "trimestriel", label: "Trimestriel" },
];

const SITE_OPTIONS = [
  { value: "", label: "Site : tous" },
  { value: "true", label: "Avec site web" },
  { value: "false", label: "Sans site web" },
];

interface Filter {
  search: string;
  media_type: string;
  periodicity: string;
  department_code: string;
  has_website: string;
}

const EMPTY_FILTER: Filter = { search: "", media_type: "", periodicity: "", department_code: "", has_website: "" };

function typeLabel(v: string | null): string {
  return MEDIA_TYPE_OPTIONS.find((o) => o.value === v)?.label ?? v ?? "—";
}

export function MediaListPage() {
  const [page, setPage] = useState(1);
  const [filter, setFilter] = useState<Filter>(EMPTY_FILTER);
  const [exporting, setExporting] = useState(false);

  function filterParams(): Record<string, string> {
    return {
      ...(filter.search ? { "filter[name]": filter.search } : {}),
      ...(filter.media_type ? { "filter[media_type]": filter.media_type } : {}),
      ...(filter.periodicity ? { "filter[periodicity]": filter.periodicity } : {}),
      ...(filter.department_code ? { "filter[department_code]": filter.department_code } : {}),
      ...(filter.has_website ? { "filter[has_website]": filter.has_website } : {}),
    };
  }

  async function exportCsv() {
    setExporting(true);
    try {
      const params = new URLSearchParams(filterParams());
      const r = await api.get<Blob>(`/media/export?${params.toString()}`, { responseType: "blob" });
      const url = URL.createObjectURL(r.data);
      const a = document.createElement("a");
      a.href = url;
      a.download = `medias-${new Date().toISOString().slice(0, 10)}.csv`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
      toast.success("Export CSV téléchargé");
    } catch {
      toast.error("Erreur lors de l'export");
    } finally {
      setExporting(false);
    }
  }

  const { data, isLoading } = useQuery<MediaResponse>({
    queryKey: ["media", page, filter],
    queryFn: async () => {
      const params = new URLSearchParams({ page: String(page), per_page: "100", ...filterParams() });
      const r = await api.get<MediaResponse>(`/media?${params.toString()}`);
      return r.data;
    },
    placeholderData: (prev) => prev,
  });

  const rows = useMemo(() => data?.data ?? [], [data]);
  const total = data?.meta.total ?? 0;
  const lastPage = data?.meta.last_page ?? 1;

  const kpis = useMemo(() => {
    const count = rows.length;
    const withSite = rows.filter((m) => m.website).length;
    const withEmail = rows.filter((m) => m.email).length;
    const byType = rows.reduce<Record<string, number>>((acc, m) => {
      const k = m.media_type ?? "inconnu";
      acc[k] = (acc[k] ?? 0) + 1;
      return acc;
    }, {});
    const topType = Object.entries(byType).sort((a, b) => b[1] - a[1])[0];
    return {
      sitePct: count > 0 ? Math.round((withSite / count) * 100) : 0,
      emailPct: count > 0 ? Math.round((withEmail / count) * 100) : 0,
      topType: topType ? typeLabel(topType[0]) : "—",
    };
  }, [rows]);

  const setFilterAndReset = (next: Partial<Filter>) => {
    setFilter((f) => ({ ...f, ...next }));
    setPage(1);
  };
  const hasActiveFilter =
    filter.search || filter.media_type || filter.periodicity || filter.department_code || filter.has_website;

  return (
    <div className="px-6 py-6">
      <PageHeader
        title="Médias"
        subtitle={
          <>
            Base médias & presse ·{" "}
            <span className="font-semibold text-slate-700 tabular-nums dark:text-slate-200">
              {total.toLocaleString("fr-FR")}
            </span>{" "}
            médias
          </>
        }
        actions={
          <Button variant="secondary" size="md" onClick={() => void exportCsv()} disabled={exporting}>
            {exporting ? "Export…" : "Exporter CSV"}
          </Button>
        }
      />

      <div className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <KpiCard tone="sky" label="Total" value={total.toLocaleString("fr-FR")} sublabel={`Page ${page} · ${rows.length} affichés`} />
        <KpiCard tone="violet" label="Avec site web" value={`${kpis.sitePct}%`} sublabel="de la page" progress={kpis.sitePct} />
        <KpiCard tone="emerald" label="Avec email" value={`${kpis.emailPct}%`} sublabel="email rédaction" progress={kpis.emailPct} />
        <KpiCard tone="amber" label="Top type" value={kpis.topType} sublabel="de la page" />
      </div>

      <div className="mb-4 flex flex-wrap items-center gap-2">
        <SearchInput
          value={filter.search}
          onChange={(v) => setFilterAndReset({ search: v })}
          placeholder="Rechercher un média…"
          className="w-72"
        />
        <Select value={filter.media_type} onChange={(v) => setFilterAndReset({ media_type: v })} options={MEDIA_TYPE_OPTIONS} ariaLabel="Filtre type" />
        <Select value={filter.periodicity} onChange={(v) => setFilterAndReset({ periodicity: v })} options={PERIODICITY_OPTIONS} ariaLabel="Filtre périodicité" />
        <Select value={filter.has_website} onChange={(v) => setFilterAndReset({ has_website: v })} options={SITE_OPTIONS} ariaLabel="Filtre site web" />
        <input
          type="text"
          value={filter.department_code}
          onChange={(e) => setFilterAndReset({ department_code: e.target.value.toUpperCase().slice(0, 3) })}
          placeholder="Dept (75…)"
          aria-label="Filtre département"
          className="h-9 w-24 rounded-lg bg-white px-3 font-mono text-xs text-slate-900 ring-1 ring-slate-200 transition placeholder:text-slate-400 focus:ring-2 focus:ring-slate-300 focus:outline-none dark:bg-slate-900 dark:text-white dark:ring-slate-700 dark:focus:ring-slate-600"
        />
        {hasActiveFilter ? (
          <Button variant="ghost" size="sm" onClick={() => { setFilter(EMPTY_FILTER); setPage(1); }}>
            Réinitialiser
          </Button>
        ) : null}
      </div>

      {isLoading ? (
        <Card className="p-10 text-center text-sm text-slate-500">Chargement…</Card>
      ) : rows.length === 0 ? (
        <EmptyState
          icon="📰"
          title="Aucun média"
          description={
            hasActiveFilter
              ? "Aucun média ne correspond à ces filtres."
              : "Lance l'extraction des médias (media:extract-from-companies) pour peupler la base."
          }
        />
      ) : (
        <Card padding="none" className="overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[860px] text-sm">
              <thead>
                <tr className="border-b border-slate-200 bg-slate-50/80 text-[11px] font-semibold tracking-wider text-slate-600 uppercase dark:border-slate-800 dark:bg-slate-900/80 dark:text-slate-400">
                  <th className="px-4 py-3 text-left">Média</th>
                  <th className="px-4 py-3 text-left">Type</th>
                  <th className="px-4 py-3 text-left">Périodicité</th>
                  <th className="px-4 py-3 text-left">Dept</th>
                  <th className="px-4 py-3 text-left">Ville</th>
                  <th className="px-4 py-3 text-left">Site</th>
                  <th className="px-4 py-3 text-left">Email</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((m) => (
                  <tr key={m.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50/60 dark:border-slate-800 dark:hover:bg-slate-800/40">
                    <td className="px-4 py-2.5">
                      <Link to="/media/$mediaId" params={{ mediaId: String(m.id) }} className="font-medium text-slate-900 hover:text-brand-600 dark:text-white">
                        {m.name}
                      </Link>
                    </td>
                    <td className="px-4 py-2.5 text-slate-600 dark:text-slate-300">{typeLabel(m.media_type)}</td>
                    <td className="px-4 py-2.5 text-slate-500 dark:text-slate-400">{m.periodicity ?? "—"}</td>
                    <td className="px-4 py-2.5 font-mono text-xs text-slate-500">{m.department_code ?? "—"}</td>
                    <td className="px-4 py-2.5 text-slate-500 dark:text-slate-400">{m.city ?? "—"}</td>
                    <td className="px-4 py-2.5">
                      {m.website ? (
                        <a href={m.website} target="_blank" rel="noreferrer" className="text-brand-600 hover:underline dark:text-brand-400">
                          {m.website.replace(/^https?:\/\//, "").slice(0, 28)}
                        </a>
                      ) : (
                        <span className="text-slate-400">—</span>
                      )}
                    </td>
                    <td className="px-4 py-2.5 text-slate-500 dark:text-slate-400">{m.email ?? "—"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      <div className="mt-4 flex items-center justify-between">
        <span className="text-xs text-slate-500">
          Page {page} / {lastPage} · {total.toLocaleString("fr-FR")} médias
        </span>
        <div className="flex gap-2">
          <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))}>
            ← Précédent
          </Button>
          <Button variant="secondary" size="sm" disabled={page >= lastPage} onClick={() => setPage((p) => Math.min(lastPage, p + 1))}>
            Suivant →
          </Button>
        </div>
      </div>
    </div>
  );
}

function Select({
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
      className={cn(
        "h-9 rounded-lg bg-white px-2 pr-7 text-sm text-slate-900 ring-1 ring-slate-200 transition focus:ring-2 focus:ring-slate-300 focus:outline-none",
        "dark:bg-slate-900 dark:text-white dark:ring-slate-700 dark:focus:ring-slate-600",
      )}
    >
      {options.map((o) => (
        <option key={o.value} value={o.value}>
          {o.label}
        </option>
      ))}
    </select>
  );
}
