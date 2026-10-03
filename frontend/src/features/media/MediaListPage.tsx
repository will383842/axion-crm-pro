import { Newspaper } from 'lucide-react';
import { useId, useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Link } from "@tanstack/react-router";
import { Button, Card, Champ, EmptyState, KpiCard, PageHeader, QueryErrorState, SearchInput, cn } from "@/components/ui";
import { api } from "@/lib/api";
import { useAntiRebond } from "@/hooks/useAntiRebond";
import { toast } from "sonner";
import { misAJour } from "@/lib/fraicheur";

export interface MediaItem {
  id: number;
  name: string;
  media_type: string | null;
  media_family: string | null;
  periodicity: string | null;
  editorial_theme: string | null;
  diffusion_zone: string | null;
  department_code: string | null;
  region_code: string | null;
  city: string | null;
  publisher: string | null;
  website: string | null;
  email: string | null;
  email_confidence: string | null;
  phone: string | null;
  cppap_number: string | null;
  arcom_id: string | null;
  enrich_status: string | null;
  /** Lot 3 — l'URL VÉRIFIÉE (`crm:presse:verifier-sites`), sinon null. */
  site_verifie?: string | null;
  /** Lot 3 — statut du contrôle du site (`verifie`, `a-confirmer`, `non-conforme`…). */
  site_statut?: string | null;
}

/** Réponse de `GET /media/stats` : indicateurs sur TOUTE la sélection filtrée. */
interface MediaStats {
  total: number;
  avec_site_fiable: number;
  avec_email: number;
  top_type: { media_type: string; n: number } | null;
  computed_at?: string | null;
}

interface MediaResponse {
  data: MediaItem[];
  meta: { total: number; last_page: number; current_page?: number; per_page?: number };
}

export const MEDIA_TYPE_OPTIONS = [
  { value: "", label: "Tous types" },
  { value: "presse_journal", label: "Journal" },
  { value: "presse_revue", label: "Revue / périodique" },
  { value: "presse_autre", label: "Autre presse" },
  { value: "radio", label: "Radio" },
  { value: "tv", label: "Télévision" },
  { value: "tv_emission", label: "Émission de télévision" },
  { value: "agence_presse", label: "Agence de presse" },
  { value: "portail_web", label: "Portail / site d’information" },
  { value: "blog", label: "Blog" },
  { value: "production_audiovisuelle", label: "Production audiovisuelle" },
];

const PERIODICITY_OPTIONS = [
  { value: "", label: "Toute périodicité" },
  { value: "quotidien", label: "Quotidien" },
  { value: "hebdomadaire", label: "Hebdomadaire" },
  { value: "mensuel", label: "Mensuel" },
  { value: "bimensuel", label: "Bimensuel" },
  { value: "trimestriel", label: "Trimestriel" },
];

// Lot 3 — « site fiable » = site VÉRIFIÉ, pas une colonne remplie : beaucoup
// de sites avaient été DEVINÉS depuis le nom (« agence.com », « paris.fr »).
const SITE_OPTIONS = [
  { value: "", label: "Site : tous" },
  { value: "true", label: "Avec site vérifié" },
  { value: "false", label: "Sans site vérifié" },
];

// Lot 3 — par DÉFAUT, les médias seulement : les ≈ 25 000 sociétés de
// production audiovisuelle (`production_audiovisuelle`) étaient mêlées aux
// médias et s'affichaient en premier. Elles restent accessibles ici.
export const FAMILLE_PAR_DEFAUT = "editorial";
const FAMILY_OPTIONS = [
  { value: "editorial", label: "Médias" },
  { value: "audiovisual_production", label: "Sociétés de production" },
  { value: "tous", label: "Médias et sociétés de production" },
];

const EMAIL_OPTIONS = [
  { value: "", label: "Email : tous" },
  { value: "true", label: "Avec email" },
  { value: "false", label: "Sans email" },
];

const CONFIDENCE_OPTIONS = [
  { value: "", label: "Confiance : toutes" },
  { value: "A", label: "A · email = domaine du site" },
  { value: "B", label: "B · domaine pro" },
  { value: "C", label: "C · boîte grand public" },
];

interface Filter {
  search: string;
  media_type: string;
  media_family: string;
  periodicity: string;
  department_code: string;
  region_code: string;
  has_website: string;
  has_email: string;
  email_confidence: string;
}

const EMPTY_FILTER: Filter = {
  search: "",
  media_type: "",
  media_family: FAMILLE_PAR_DEFAUT,
  periodicity: "",
  department_code: "",
  region_code: "",
  has_website: "",
  has_email: "",
  email_confidence: "",
};

function typeLabel(v: string | null): string {
  return MEDIA_TYPE_OPTIONS.find((o) => o.value === v)?.label ?? v ?? "—";
}

const CONFIDENCE_STYLE: Record<string, string> = {
  A: "bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300",
  B: "bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300",
  C: "bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300",
};

export function MediaListPage() {
  const [page, setPage] = useState(1);
  const [filter, setFilter] = useState<Filter>(EMPTY_FILTER);
  const [exporting, setExporting] = useState(false);
  const idDepartement = useId();

  // G42-010 — les DEUX champs de saisie libre de cet ecran (« Rechercher un
  // media » et le code departement) sont differes de 300 ms avant d'atteindre
  // la requete. Les listes deroulantes ne le sont PAS : un choix dans un menu
  // est un geste unique, le retarder se verrait.
  //
  // Mesure du 2026-08-20 sur `/companies`, meme cablage (voir
  // `tests/perf/recherche-anti-rebond.test.tsx`) : 11 caracteres tapes
  // lancaient 11 requetes serveur. Apres : 1.
  const rechercheDifferee = useAntiRebond(filter.search);
  const departementDiffere = useAntiRebond(filter.department_code);
  const filtreInterroge = useMemo<Filter>(
    () => ({ ...filter, search: rechercheDifferee, department_code: departementDiffere }),
    [filter, rechercheDifferee, departementDiffere],
  );

  // ⚠️ `source` explicite : la LISTE interroge avec le filtre differe, l'EXPORT
  // avec le filtre immediat. L'export est declenche par un clic delibere, et
  // ce qu'il doit reproduire est ce que l'operateur LIT dans ses champs.
  // Fenetre de divergence : 300 ms au plus.
  function filterParams(source: Filter): Record<string, string> {
    return {
      ...(source.search ? { "filter[name]": source.search } : {}),
      ...(source.media_type ? { "filter[media_type]": source.media_type } : {}),
      ...(source.media_family && source.media_family !== "tous" ? { "filter[media_family]": source.media_family } : {}),
      ...(source.periodicity ? { "filter[periodicity]": source.periodicity } : {}),
      ...(source.department_code ? { "filter[department_code]": source.department_code } : {}),
      ...(source.region_code ? { "filter[region_code]": source.region_code } : {}),
      ...(source.has_website ? { "filter[site_fiable]": source.has_website } : {}),
      ...(source.has_email ? { "filter[has_email]": source.has_email } : {}),
      ...(source.email_confidence ? { "filter[email_confidence]": source.email_confidence } : {}),
    };
  }

  async function exportCsv() {
    setExporting(true);
    try {
      const params = new URLSearchParams(filterParams(filter));
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

  const { data, isLoading, error, refetch } = useQuery<MediaResponse>({
    queryKey: ["media", page, filtreInterroge],
    queryFn: async () => {
      const params = new URLSearchParams({ page: String(page), per_page: "100", ...filterParams(filtreInterroge) });
      const r = await api.get<MediaResponse>(`/media?${params.toString()}`);
      return r.data;
    },
    placeholderData: (prev) => prev,
  });

  const rows = useMemo(() => data?.data ?? [], [data]);
  const total = data?.meta.total ?? 0;
  const lastPage = data?.meta.last_page ?? 1;

  // Lot 3 — indicateurs sur TOUTE la sélection (serveur), plus sur la page.
  const { data: stats } = useQuery<MediaStats>({
    queryKey: ["media-stats", filtreInterroge],
    queryFn: async () => {
      const params = new URLSearchParams(filterParams(filtreInterroge));
      return (await api.get<MediaStats>(`/media/stats?${params.toString()}`)).data;
    },
    staleTime: 5 * 60 * 1000,
  });
  const kpis = useMemo(() => {
    if (stats === undefined || stats.total === 0) {
      return { sitePct: null, emailPct: null, topType: "—", topTypeN: 0 };
    }
    return {
      sitePct: Math.round((stats.avec_site_fiable / stats.total) * 100),
      emailPct: Math.round((stats.avec_email / stats.total) * 100),
      topType: stats.top_type ? typeLabel(stats.top_type.media_type) : "—",
      topTypeN: stats.top_type?.n ?? 0,
    };
  }, [stats]);

  const setFilterAndReset = (next: Partial<Filter>) => {
    setFilter((f) => ({ ...f, ...next }));
    setPage(1);
  };
  const hasActiveFilter =
    filter.search ||
    filter.media_type ||
    filter.media_family !== FAMILLE_PAR_DEFAUT ||
    filter.periodicity ||
    filter.department_code ||
    filter.region_code ||
    filter.has_website ||
    filter.has_email ||
    filter.email_confidence;

  return (
    <div>
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
        <KpiCard
          tone="violet"
          label="Avec site vérifié"
          value={kpis.sitePct === null ? "—" : `${kpis.sitePct}%`}
          sublabel={stats ? `${stats.avec_site_fiable.toLocaleString("fr-FR")} sur ${stats.total.toLocaleString("fr-FR")}` : "calcul en cours"}
          {...(kpis.sitePct !== null ? { progress: kpis.sitePct } : {})}
        />
        <KpiCard
          tone="emerald"
          label="Avec email"
          value={kpis.emailPct === null ? "—" : `${kpis.emailPct}%`}
          sublabel={stats ? `${stats.avec_email.toLocaleString("fr-FR")} sur ${stats.total.toLocaleString("fr-FR")}` : "calcul en cours"}
          {...(kpis.emailPct !== null ? { progress: kpis.emailPct } : {})}
        />
        <KpiCard
          tone="amber"
          label="Type le plus fréquent"
          value={kpis.topType}
          sublabel={
            kpis.topTypeN > 0
              ? [`${kpis.topTypeN.toLocaleString("fr-FR")} médias`, misAJour(stats?.computed_at)].filter(Boolean).join(" · ")
              : "—"
          }
        />
      </div>

      <div className="mb-4 flex flex-wrap items-end gap-2">
        <Champ label="Recherche" htmlFor={null}>
          <SearchInput
            label="Rechercher un média"
            value={filter.search}
            onChange={(v) => setFilterAndReset({ search: v })}
            placeholder="Rechercher un média…"
            className="w-72"
          />
        </Champ>
        <Select value={filter.media_family} onChange={(v) => setFilterAndReset({ media_family: v })} options={FAMILY_OPTIONS} label="Famille" />
        <Select value={filter.media_type} onChange={(v) => setFilterAndReset({ media_type: v })} options={MEDIA_TYPE_OPTIONS} label="Type" />
        <Select value={filter.periodicity} onChange={(v) => setFilterAndReset({ periodicity: v })} options={PERIODICITY_OPTIONS} label="Périodicité" />
        <Select value={filter.has_website} onChange={(v) => setFilterAndReset({ has_website: v })} options={SITE_OPTIONS} label="Site web" />
        <Select value={filter.has_email} onChange={(v) => setFilterAndReset({ has_email: v })} options={EMAIL_OPTIONS} label="E-mail" />
        <Select value={filter.email_confidence} onChange={(v) => setFilterAndReset({ email_confidence: v })} options={CONFIDENCE_OPTIONS} label="Fiabilité de l’e-mail" />
        <Champ label="Département" htmlFor={idDepartement}>
          <input
            id={idDepartement}
            type="text"
            value={filter.department_code}
            onChange={(e) => setFilterAndReset({ department_code: e.target.value.toUpperCase().slice(0, 3) })}
            placeholder="ex. 75"
            className="h-9 w-24 rounded-lg bg-white px-3 font-mono text-xs text-slate-900 ring-1 ring-slate-200 transition placeholder:text-slate-400 focus:ring-2 focus:ring-slate-300 focus:outline-none dark:bg-slate-900 dark:text-white dark:ring-slate-700 dark:focus:ring-slate-600"
          />
        </Champ>
        {hasActiveFilter ? (
          <Button variant="ghost" size="sm" onClick={() => { setFilter(EMPTY_FILTER); setPage(1); }}>
            Réinitialiser
          </Button>
        ) : null}
      </div>

      {/* P0-3 — une panne n'est jamais une liste vide. */}
      {error !== null && data === undefined ? (
        <QueryErrorState error={error} contexte="la liste des médias" onRetry={() => void refetch()} />
      ) : isLoading ? (
        <Card className="p-10 text-center text-sm text-slate-500">Chargement…</Card>
      ) : rows.length === 0 ? (
        <EmptyState
          icon={<Newspaper />}
          title="Aucun média"
          description={
            hasActiveFilter
              ? "Aucun média ne correspond à ces filtres."
              : "Aucun média pour l’instant."
          }
        />
      ) : (
        <Card padding="none" className="overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[860px] text-sm">
              <thead>
                <tr className="border-b border-slate-200 bg-slate-50/80 text-xs font-semibold tracking-wider text-slate-600 uppercase dark:border-slate-800 dark:bg-slate-900/80 dark:text-slate-400">
                  <th className="px-4 py-3 text-left">Média</th>
                  <th className="px-4 py-3 text-left">Type</th>
                  <th className="px-4 py-3 text-left">Dept</th>
                  <th className="px-4 py-3 text-left">Ville</th>
                  <th className="px-4 py-3 text-left">Site</th>
                  <th className="px-4 py-3 text-left">Email</th>
                  <th className="px-4 py-3 text-left">Conf.</th>
                  <th className="px-4 py-3 text-left">Téléphone</th>
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
                    <td className="px-4 py-2.5 font-mono text-xs text-slate-500">{m.department_code ?? "—"}</td>
                    <td className="px-4 py-2.5 text-slate-500 dark:text-slate-400">{m.city ?? "—"}</td>
                    <td className="px-4 py-2.5">
                      <SiteMedia media={m} />
                    </td>
                    <td className="px-4 py-2.5 text-slate-500 dark:text-slate-400">{m.email ?? "—"}</td>
                    <td className="px-4 py-2.5">
                      {m.email_confidence ? (
                        <span className={cn("rounded px-1.5 py-0.5 text-xs font-semibold", CONFIDENCE_STYLE[m.email_confidence] ?? "")}>
                          {m.email_confidence}
                        </span>
                      ) : (
                        <span className="text-slate-400">—</span>
                      )}
                    </td>
                    <td className="px-4 py-2.5 font-mono text-xs text-slate-500 dark:text-slate-400">{m.phone ?? "—"}</td>
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
  label,
}: {
  value: string;
  onChange: (v: string) => void;
  options: Array<{ value: string; label: string }>;
  /** Étiquette VISIBLE au-dessus du filtre (audit UX lot 18). */
  label: string;
}) {
  const id = useId();
  return (
    <Champ label={label} htmlFor={id}>
      <select
        id={id}
        value={value}
        onChange={(e) => onChange(e.target.value)}
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
    </Champ>
  );
}

/**
 * Lot 3 — le site d'un média n'est montré comme LIEN que s'il est VÉRIFIÉ.
 * Un site non vérifié (deviné depuis le nom, jamais contrôlé, ou contrôlé et
 * non conforme) reste visible, grisé et marqué « non vérifié » — rien n'est
 * effacé.
 */
export function SiteMedia({ media }: { media: Pick<MediaItem, "website" | "site_verifie"> }) {
  if (media.site_verifie) {
    return (
      <a href={media.site_verifie} target="_blank" rel="noreferrer" className="text-brand-600 hover:underline dark:text-brand-400">
        {media.site_verifie.replace(/^https?:\/\//, "").slice(0, 28)}
      </a>
    );
  }
  if (media.website) {
    return (
      <span className="text-xs text-slate-400" title="Site deviné ou non confirmé : à vérifier avant usage">
        <span className="line-through">{media.website.replace(/^https?:\/\//, "").slice(0, 24)}</span> · non vérifié
      </span>
    );
  }
  return <span className="text-slate-400">—</span>;
}
