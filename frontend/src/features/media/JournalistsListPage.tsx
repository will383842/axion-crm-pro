import { useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Button, Card, EmptyState, KpiCard, PageHeader, SearchInput } from "@/components/ui";
import { api } from "@/lib/api";
import { toast } from "sonner";

interface JournalistItem {
  id: number;
  first_name: string | null;
  last_name: string | null;
  role: string | null;
  beat: string | null;
  email: string | null;
  phone: string | null;
  opt_out: boolean;
  media?: { id: number; name: string } | null;
}

interface JournalistsResponse {
  data: JournalistItem[];
  meta: { total: number; last_page: number };
}

export function JournalistsListPage() {
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState("");
  const [exporting, setExporting] = useState(false);

  async function exportCsv() {
    setExporting(true);
    try {
      const params = new URLSearchParams(search ? { "filter[last_name]": search } : {});
      const r = await api.get<Blob>(`/journalists/export?${params.toString()}`, { responseType: "blob" });
      const url = URL.createObjectURL(r.data);
      const a = document.createElement("a");
      a.href = url;
      a.download = `journalistes-${new Date().toISOString().slice(0, 10)}.csv`;
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

  const { data, isLoading } = useQuery<JournalistsResponse>({
    queryKey: ["journalists", page, search],
    queryFn: async () => {
      const params = new URLSearchParams({
        page: String(page),
        per_page: "100",
        include: "media",
        ...(search ? { "filter[last_name]": search } : {}),
      });
      const r = await api.get<JournalistsResponse>(`/journalists?${params.toString()}`);
      return r.data;
    },
    placeholderData: (prev) => prev,
  });

  const rows = useMemo(() => data?.data ?? [], [data]);
  const total = data?.meta.total ?? 0;
  const lastPage = data?.meta.last_page ?? 1;

  return (
    <div className="px-6 py-6">
      <PageHeader
        title="Journalistes"
        subtitle={
          <>
            Contacts rédaction ·{" "}
            <span className="font-semibold text-slate-700 tabular-nums dark:text-slate-200">
              {total.toLocaleString("fr-FR")}
            </span>{" "}
            journalistes
          </>
        }
        actions={
          <Button variant="secondary" size="md" onClick={() => void exportCsv()} disabled={exporting}>
            {exporting ? "Export…" : "Exporter CSV"}
          </Button>
        }
      />

      <div className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <KpiCard tone="sky" label="Total" value={total.toLocaleString("fr-FR")} sublabel={`Page ${page}`} />
        <KpiCard tone="violet" label="Avec email" value={`${rows.filter((j) => j.email).length}`} sublabel="sur la page" />
        <KpiCard tone="amber" label="Opt-out" value={`${rows.filter((j) => j.opt_out).length}`} sublabel="opposition RGPD" />
      </div>

      <div className="mb-4 flex items-center gap-2">
        <SearchInput value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder="Rechercher par nom…" className="w-72" />
      </div>

      {isLoading ? (
        <Card className="p-10 text-center text-sm text-slate-500">Chargement…</Card>
      ) : rows.length === 0 ? (
        <EmptyState
          icon="🎙️"
          title="Aucun journaliste"
          description="La base des journalistes se remplira quand l'extraction des rédactions (Phase 3, RGPD-encadrée) sera lancée."
        />
      ) : (
        <Card padding="none" className="overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[760px] text-sm">
              <thead>
                <tr className="border-b border-slate-200 bg-slate-50/80 text-[11px] font-semibold tracking-wider text-slate-600 uppercase dark:border-slate-800 dark:bg-slate-900/80 dark:text-slate-400">
                  <th className="px-4 py-3 text-left">Nom</th>
                  <th className="px-4 py-3 text-left">Média</th>
                  <th className="px-4 py-3 text-left">Rôle</th>
                  <th className="px-4 py-3 text-left">Rubrique</th>
                  <th className="px-4 py-3 text-left">Email</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((j) => (
                  <tr key={j.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50/60 dark:border-slate-800 dark:hover:bg-slate-800/40">
                    <td className="px-4 py-2.5 font-medium text-slate-900 dark:text-white">
                      {[j.first_name, j.last_name].filter(Boolean).join(" ") || "—"}
                    </td>
                    <td className="px-4 py-2.5 text-slate-500 dark:text-slate-400">{j.media?.name ?? "—"}</td>
                    <td className="px-4 py-2.5 text-slate-500 dark:text-slate-400">{j.role ?? "—"}</td>
                    <td className="px-4 py-2.5 text-slate-500 dark:text-slate-400">{j.beat ?? "—"}</td>
                    <td className="px-4 py-2.5 text-slate-500 dark:text-slate-400">{j.opt_out ? "opt-out" : j.email ?? "—"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      <div className="mt-4 flex items-center justify-between">
        <span className="text-xs text-slate-500">Page {page} / {lastPage}</span>
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
