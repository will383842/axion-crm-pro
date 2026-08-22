import { useRef, useState, useMemo } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link } from "@tanstack/react-router";
import { useVirtualizer } from "@tanstack/react-virtual";
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
  TableScroll,
} from "@/components/ui";
import { api } from "@/lib/api";
import { useAntiRebond } from "@/hooks/useAntiRebond";
import {
  versOptions,
  type ReferentielsGeo,
  CONFIANCE_EMAIL_OPTIONS,
  COUNTRY_OPTIONS,
  ELIGIBILITE_OPTIONS,
  NATURE_OPTIONS,
} from "@/lib/prospection-referentiels";
import { toast } from "sonner";
import { CompanyRow, COMPANY_ROW_GRID, type CompanyRowData } from "./components/CompanyRow";
import { EFFECTIF_OPTIONS } from "./effectif";
import { Pagination } from "./components/Pagination";

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
  { value: "", label: "Toutes tailles" },
  { value: "artisan", label: "Artisan" },
  { value: "tpe", label: "TPE" },
  { value: "pme", label: "PME" },
  { value: "eti", label: "ETI" },
  { value: "grande_entreprise", label: "Grande entreprise" },
];

const QUALITY_OPTIONS = [
  { value: "", label: "Toutes qualités" },
  { value: "complete", label: "🟢 Complète (≥ 90)" },
  { value: "partielle", label: "🟡 Partielle (50-89)" },
  { value: "basique", label: "🔴 Basique (< 50)" },
];

const PRIORITY_OPTIONS = [
  { value: "", label: "Toutes priorités" },
  { value: "haute", label: "Haute" },
  { value: "moyenne", label: "Moyenne" },
  { value: "basse", label: "Basse" },
  { value: "gelee", label: "Gelée" },
];

const PROSPECTION_TABS = [
  { value: "", label: "Tous" },
  { value: "ready_for_outreach", label: "Prospectables" },
  { value: "partial_email", label: "Partiels" },
  { value: "pending", label: "Pending" },
  { value: "archived_no_email", label: "Archivés" },
];

// Prospection internationale : sans ces deux filtres, les fiches étrangères
// restent noyées dans les 4,29 M de fiches françaises et aucune campagne ne
// peut les viser. « Tous pays » laisse le comportement historique inchangé.
const SECTOR_OPTIONS = [
  { value: "", label: "Tous secteurs" },
  { value: "it_saas", label: "IT / SaaS" },
  { value: "btp", label: "BTP" },
  { value: "sante", label: "Santé" },
  { value: "commerce", label: "Commerce" },
  { value: "services_pro", label: "Services pro" },
  { value: "finance_assurance", label: "Finance / Assurance" },
  { value: "industrie", label: "Industrie" },
  { value: "hotellerie_restauration", label: "Hôtellerie / Restauration" },
  { value: "transport", label: "Transport" },
  { value: "agro_alimentaire", label: "Agro-alimentaire" },
  { value: "immobilier", label: "Immobilier" },
  { value: "enseignement", label: "Enseignement / Formation" },
  { value: "services_personnels", label: "Services aux particuliers" },
  { value: "arts_loisirs", label: "Arts / Loisirs / Sport" },
  { value: "autre", label: "Autre" },
];

interface Filter {
  size: string;
  effectif: string;
  priority: string;
  search: string;
  naf: string;
  quality: string;
  // Sprint Pipeline 360°
  prospection_status: string;
  department_code: string;
  region_code: string;
  sector_main: string;
  country_code: string;
  best_email_confidence: string;
  eligible_campagne: string;
  entity_nature: string;
  tag: string;
  cree_apres: string;
  cree_avant: string;
}

const EMPTY_FILTER: Filter = {
  size: "",
  effectif: "",
  priority: "",
  search: "",
  naf: "",
  quality: "",
  prospection_status: "",
  department_code: "",
  region_code: "",
  sector_main: "",
  country_code: "",
  best_email_confidence: "",
  eligible_campagne: "",
  entity_nature: "",
  tag: "",
  cree_apres: "",
  cree_avant: "",
};

export function CompaniesListPage() {
  // Référentiel géographique servi par l'API : 102 départements et 18 régions
  // recopiés dans le frontend seraient 120 occasions de diverger de la base.
  // `staleTime` long : ces référentiels changent au rythme des réformes
  // territoriales, pas à celui des ouvertures d'écran.
  const geo = useQuery<ReferentielsGeo>({
    queryKey: ["referentiels", "geo"],
    queryFn: async () => (await api.get<ReferentielsGeo>("/referentiels/geo")).data,
    staleTime: 60 * 60 * 1000,
  });

  // Sélection multiple. Un `Set` d'identifiants VISIBLES : on n'agit jamais
  // sur « tout ce qui correspond au filtre » — sur 4,29 M de fiches, une case
  // cochée par mégarde deviendrait irréversible.
  const queryClient = useQueryClient();
  const [selection, setSelection] = useState<Set<number>>(new Set());
  const [tagAction, setTagAction] = useState("");
  const [messageMasse, setMessageMasse] = useState<string | null>(null);

  const actionDeMasse = useMutation({
    mutationFn: async ({ tag, action }: { tag: string; action: "add" | "remove" }) => {
      const { data } = await api.post<{ modifiees: number; ignorees: number }>(
        "/companies/tags/bulk",
        { ids: [...selection], tag, action },
      );
      return data;
    },
    onSuccess: (resultat) => {
      // On ANNONCE le compte réel, y compris les lignes écartées : une action
      // qui dit « fait » en ayant ignoré la moitié de la sélection est pire
      // qu'une erreur franche.
      const ignorees = resultat.ignorees > 0 ? `, ${resultat.ignorees} ignorée(s)` : "";
      setMessageMasse(`${resultat.modifiees} fiche(s) modifiée(s)${ignorees}.`);
      setSelection(new Set());
      void queryClient.invalidateQueries({ queryKey: ["companies"] });
    },
    onError: (erreur: unknown) => {
      // Le serveur explique POURQUOI il refuse (tag verrouillé, tag inconnu) :
      // on relaie son message plutôt qu'un « échec » qui n'apprend rien.
      const axiosErreur = erreur as { response?: { data?: { message?: string } } };
      setMessageMasse(axiosErreur.response?.data?.message ?? "L'action de masse a échoué.");
    },
  });

  const basculerSelection = (id: number) => {
    setSelection((actuelle) => {
      const suivante = new Set(actuelle);
      if (suivante.has(id)) suivante.delete(id);
      else suivante.add(id);
      return suivante;
    });
  };

  const optionsRegions = versOptions(geo.data?.regions ?? [], "Toutes régions");
  const optionsDepartements = versOptions(geo.data?.departments ?? [], "Tous départements");

  const [page, setPage] = useState(1);
  const [filter, setFilter] = useState<Filter>(EMPTY_FILTER);
  const [exporting, setExporting] = useState(false);

  // G42-010 — les TROIS champs de SAISIE LIBRE sont différés de 300 ms avant
  // d'atteindre la requête. Les listes déroulantes et les dates NE LE SONT
  // PAS : un choix dans un menu est un geste unique, le retarder se verrait.
  //
  // Mesure du 2026-08-20 (`tests/perf/recherche-anti-rebond.test.tsx`) : taper
  // « boulangerie » (11 caractères) lançait 11 requêtes `GET /companies`,
  // chacune un `LIKE` sur 4,29 M de fiches. Après : 1.
  //
  // ⚠️ Le `value` des champs reste `filter.*`, la valeur IMMÉDIATE : la lettre
  // s'affiche sans attendre. Seule la requête patiente.
  const rechercheDifferee = useAntiRebond(filter.search);
  const nafDiffere = useAntiRebond(filter.naf);
  const tagDiffere = useAntiRebond(filter.tag);
  const filtreInterroge = useMemo<Filter>(
    () => ({ ...filter, search: rechercheDifferee, naf: nafDiffere, tag: tagDiffere }),
    [filter, rechercheDifferee, nafDiffere, tagDiffere],
  );

  // Construit les mêmes params de filtre que la liste (hors pagination).
  //
  // ⚠️ Volontairement sur `filter` (immédiat) et NON sur `filtreInterroge` :
  // l'export est déclenché par un clic délibéré, et ce qu'il doit reproduire
  // est ce que l'opérateur LIT dans ses champs. Fenêtre de divergence avec la
  // liste affichée : 300 ms au plus.
  function filterParams(): URLSearchParams {
    return new URLSearchParams({
      ...(filter.size ? { "filter[size_category]": filter.size } : {}),
      ...(filter.effectif ? { "filter[effectif]": filter.effectif } : {}),
      ...(filter.priority ? { "filter[priority]": filter.priority } : {}),
      ...(filter.search ? { "filter[denomination]": filter.search } : {}),
      ...(filter.naf ? { "filter[naf]": filter.naf } : {}),
      ...(filter.quality ? { "filter[quality]": filter.quality } : {}),
      ...(filter.prospection_status
        ? { "filter[prospection_status]": filter.prospection_status }
        : {}),
      ...(filter.department_code ? { "filter[department_code]": filter.department_code } : {}),
      ...(filter.region_code ? { "filter[region_code]": filter.region_code } : {}),
      ...(filter.sector_main ? { "filter[sector_main]": filter.sector_main } : {}),
      ...(filter.country_code ? { "filter[country_code]": filter.country_code } : {}),
      ...(filter.best_email_confidence
        ? { "filter[best_email_confidence]": filter.best_email_confidence }
        : {}),
      ...(filter.eligible_campagne
        ? { "filter[eligible_campagne]": filter.eligible_campagne }
        : {}),
      ...(filter.region_code ? { "filter[region_code]": filter.region_code } : {}),
      ...(filter.tag ? { "filter[tag]": filter.tag } : {}),
      ...(filter.cree_apres ? { "filter[cree_apres]": filter.cree_apres } : {}),
      ...(filter.cree_avant ? { "filter[cree_avant]": filter.cree_avant } : {}),
      ...(filter.entity_nature ? { "filter[entity_nature]": filter.entity_nature } : {}),
    });
  }

  // Export CSV de la liste filtrée (streamé côté serveur) → transfert/emailing.
  async function exportCsv() {
    setExporting(true);
    try {
      const r = await api.get<Blob>(`/companies/export?${filterParams().toString()}`, {
        responseType: "blob",
      });
      const url = URL.createObjectURL(r.data);
      const a = document.createElement("a");
      a.href = url;
      a.download = `entreprises-${new Date().toISOString().slice(0, 10)}.csv`;
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

  const { data, isLoading } = useQuery<CompaniesResponse>({
    // ⚠️ `filtreInterroge`, PAS `filter` : c'est ici que se joue G42-010. La
    // clé porte la valeur DIFFÉRÉE, donc elle ne change pas à chaque touche.
    queryKey: ["companies", page, filtreInterroge],
    queryFn: async () => {
      const f = filtreInterroge;
      const params = new URLSearchParams({
        page: String(page),
        per_page: "100",
        ...(f.size ? { "filter[size_category]": f.size } : {}),
        ...(f.effectif ? { "filter[effectif]": f.effectif } : {}),
        ...(f.priority ? { "filter[priority]": f.priority } : {}),
        ...(f.search ? { "filter[denomination]": f.search } : {}),
        ...(f.naf ? { "filter[naf]": f.naf } : {}),
        ...(f.quality ? { "filter[quality]": f.quality } : {}),
        ...(f.prospection_status
          ? { "filter[prospection_status]": f.prospection_status }
          : {}),
        ...(f.department_code ? { "filter[department_code]": f.department_code } : {}),
        ...(f.region_code ? { "filter[region_code]": f.region_code } : {}),
        ...(f.sector_main ? { "filter[sector_main]": f.sector_main } : {}),
        ...(f.country_code ? { "filter[country_code]": f.country_code } : {}),
        ...(f.best_email_confidence
          ? { "filter[best_email_confidence]": f.best_email_confidence }
          : {}),
        ...(f.eligible_campagne
          ? { "filter[eligible_campagne]": f.eligible_campagne }
          : {}),
        ...(f.tag ? { "filter[tag]": f.tag } : {}),
        ...(f.cree_apres ? { "filter[cree_apres]": f.cree_apres } : {}),
        ...(f.cree_avant ? { "filter[cree_avant]": f.cree_avant } : {}),
        ...(f.entity_nature ? { "filter[entity_nature]": f.entity_nature } : {}),
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
      const k = c.size_category ?? "inconnue";
      acc[k] = (acc[k] ?? 0) + 1;
      return acc;
    }, {});
    const topSize = Object.entries(bySize).sort((a, b) => b[1] - a[1])[0];
    const topSizeLabel = topSize ? topSize[0].toUpperCase() : "—";
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
      topNaf: topNaf ? topNaf[0] : "—",
      topNafCount: topNaf ? topNaf[1] : 0,
    };
  }, [rows, total]);

  const setFilterAndReset = (next: Partial<Filter>) => {
    setFilter((f) => ({ ...f, ...next }));
    setPage(1);
  };

  const hasActiveFilter =
    filter.search ||
    filter.size ||
    filter.effectif ||
    filter.priority ||
    filter.naf ||
    filter.quality ||
    filter.prospection_status ||
    filter.department_code ||
    filter.region_code ||
    filter.sector_main ||
    filter.country_code ||
    filter.best_email_confidence ||
    filter.eligible_campagne ||
    filter.tag ||
    filter.cree_apres ||
    filter.cree_avant ||
    filter.entity_nature;

  const activeFilterCount = [
    filter.search,
    filter.size,
    filter.effectif,
    filter.priority,
    filter.naf,
    filter.quality,
    filter.prospection_status,
    filter.department_code,
    filter.region_code,
    filter.sector_main,
    filter.country_code,
    filter.best_email_confidence,
    filter.eligible_campagne,
    filter.tag,
    filter.cree_apres,
    filter.cree_avant,
    filter.entity_nature,
  ].filter(Boolean).length;

  return (
    <div className="px-6 py-6">
      <PageHeader
        title="Entreprises"
        subtitle={
          <>
            Pipeline de prospection ·{" "}
            <span className="font-semibold text-slate-700 tabular-nums dark:text-slate-200">
              {(total ?? 0).toLocaleString("fr-FR")}
            </span>{" "}
            entreprises actives
          </>
        }
        actions={
          <div className="flex items-center gap-2">
            <Button variant="secondary" size="md" iconLeft={<UploadIcon />}>
              Importer
            </Button>
            <Button
              variant="secondary"
              size="md"
              iconLeft={<DownloadIcon />}
              onClick={() => void exportCsv()}
              disabled={exporting}
            >
              {exporting ? "Export…" : "Exporter"}
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
          value={(total ?? 0).toLocaleString("fr-FR")}
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
          sublabel={kpis.topNafCount ? `${kpis.topNafCount} sociétés` : "—"}
        />
      </div>

      {/* Prospection status tabs */}
      <div className="mb-3 flex flex-wrap gap-2 border-b border-slate-200 dark:border-slate-800">
        {PROSPECTION_TABS.map((tab) => {
          const active = filter.prospection_status === tab.value;
          return (
            <button
              key={tab.value || "all"}
              onClick={() => setFilterAndReset({ prospection_status: tab.value })}
              className={cn(
                "border-b-2 px-3 py-2 text-sm font-medium transition",
                active
                  ? "border-slate-900 text-slate-900 dark:border-white dark:text-white"
                  : "border-transparent text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white",
              )}
              type="button"
            >
              {tab.label}
            </button>
          );
        })}
      </div>

      {/* Toolbar */}
      <Toolbar
        left={
          <>
            <SearchInput
              label="Rechercher une entreprise"
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
              value={filter.effectif}
              onChange={(v) => setFilterAndReset({ effectif: v })}
              options={EFFECTIF_OPTIONS}
              ariaLabel="Filtre effectif (nombre de salariés)"
            />
            <FilterSelect
              value={filter.sector_main}
              onChange={(v) => setFilterAndReset({ sector_main: v })}
              options={SECTOR_OPTIONS}
              ariaLabel="Filtre secteur"
            />
            <FilterSelect
              value={filter.country_code}
              onChange={(v) => setFilterAndReset({ country_code: v })}
              options={COUNTRY_OPTIONS}
              ariaLabel="Filtre pays d'immatriculation"
            />
            {/* « Prêt pour une campagne » : la définition CALCULÉE
                (`EligibiliteCampagne` côté serveur), pas un bac figé — une
                fiche sort d'elle-même de la liste le jour où l'adresse
                s'oppose. Le PALIER, lui, cible : A = adresse sur le domaine
                du site (165 587 fiches), c'est par là qu'une campagne
                commence, pas par les 255 290 d'un bloc. */}
            <FilterSelect
              value={filter.eligible_campagne}
              onChange={(v) => setFilterAndReset({ eligible_campagne: v })}
              options={ELIGIBILITE_OPTIONS}
              ariaLabel="Filtre prêt pour une campagne"
            />
            <FilterSelect
              value={filter.best_email_confidence}
              onChange={(v) => setFilterAndReset({ best_email_confidence: v })}
              options={CONFIANCE_EMAIL_OPTIONS}
              ariaLabel="Filtre qualité de l'adresse e-mail"
            />
            <FilterSelect
              value={filter.entity_nature}
              onChange={(v) => setFilterAndReset({ entity_nature: v })}
              options={NATURE_OPTIONS}
              ariaLabel="Filtre nature d'entité"
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
            {/* Liste plutôt que saisie libre : taper « 075 » ou « 7 5 » rendait
                une liste vide qui se lit comme « aucun résultat », sans jamais
                dire que le code était faux. */}
            <FilterSelect
              value={filter.department_code}
              onChange={(v) => setFilterAndReset({ department_code: v })}
              options={optionsDepartements}
              ariaLabel="Filtre département"
            />
            {/* `region_code` était DÉCLARÉ dans l'état sans aucun contrôle :
                un filtre qu'on ne peut pas régler est du code mort. */}
            <FilterSelect
              value={filter.region_code}
              onChange={(v) => setFilterAndReset({ region_code: v })}
              options={optionsRegions}
              ariaLabel="Filtre région"
            />
            <input
              type="text"
              value={filter.naf}
              onChange={(e) => setFilterAndReset({ naf: e.target.value })}
              placeholder="Code NAF…"
              aria-label="Filtre NAF"
              className="h-9 w-28 rounded-lg bg-white px-3 font-mono text-xs text-slate-900 ring-1 ring-slate-200 transition placeholder:text-slate-400 focus:ring-2 focus:ring-slate-300 focus:outline-none dark:bg-slate-900 dark:text-white dark:ring-slate-700 dark:focus:ring-slate-600"
            />
            {/* Retrouver un SEGMENT constitué (campagne, import, sélection) :
                l'API l'acceptait déjà, rien ne permettait de le demander. */}
            <input
              type="text"
              value={filter.tag}
              onChange={(e) => setFilterAndReset({ tag: e.target.value })}
              placeholder="Tag (implantation-ro…)"
              aria-label="Filtre tag"
              className="h-9 w-44 rounded-lg bg-white px-3 text-xs text-slate-900 ring-1 ring-slate-200 transition placeholder:text-slate-400 focus:ring-2 focus:ring-slate-300 focus:outline-none dark:bg-slate-900 dark:text-white dark:ring-slate-700 dark:focus:ring-slate-600"
            />
            {/* « Ce qui est arrivé depuis lundi » — la question la plus
                fréquente, impossible à poser jusqu'ici. */}
            <input
              type="date"
              value={filter.cree_apres}
              onChange={(e) => setFilterAndReset({ cree_apres: e.target.value })}
              aria-label="Fiches créées après le"
              className="h-9 rounded-lg bg-white px-3 text-xs text-slate-900 ring-1 ring-slate-200 transition focus:ring-2 focus:ring-slate-300 focus:outline-none dark:bg-slate-900 dark:text-white dark:ring-slate-700 dark:focus:ring-slate-600"
            />
            <input
              type="date"
              value={filter.cree_avant}
              onChange={(e) => setFilterAndReset({ cree_avant: e.target.value })}
              aria-label="Fiches créées avant le"
              className="h-9 rounded-lg bg-white px-3 text-xs text-slate-900 ring-1 ring-slate-200 transition focus:ring-2 focus:ring-slate-300 focus:outline-none dark:bg-slate-900 dark:text-white dark:ring-slate-700 dark:focus:ring-slate-600"
            />
          </>
        }
        right={
          hasActiveFilter ? (
            <div className="flex items-center gap-2">
              <span className="text-xs text-slate-500">
                {activeFilterCount} filtre{activeFilterCount > 1 ? "s" : ""} actif
                {activeFilterCount > 1 ? "s" : ""}
              </span>
              <Button
                variant="ghost"
                size="sm"
                onClick={() => {
                  setFilter(EMPTY_FILTER);
                  setPage(1);
                }}
              >
                Réinitialiser
              </Button>
            </div>
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
              ? "Aucune entreprise ne correspond à ces filtres. Réinitialise pour voir plus de résultats."
              : "Lance un scraping depuis la carte de couverture France pour découvrir des entreprises."
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
          {/* Barre d'action de masse — n'apparaît QUE s'il y a une sélection.
              Toujours visible, elle inviterait à cliquer sans savoir sur quoi. */}
          {selection.size > 0 && (
            <div className="flex flex-wrap items-center gap-2 border-b border-slate-200 bg-brand-50/60 px-4 py-2 text-sm dark:border-slate-800 dark:bg-slate-800/60">
              <span className="font-medium text-slate-700 dark:text-slate-200">
                {selection.size} sélectionnée{selection.size > 1 ? "s" : ""}
              </span>
              <input
                type="text"
                value={tagAction}
                onChange={(e) => setTagAction(e.target.value)}
                placeholder="Tag existant (campagne-ro…)"
                aria-label="Tag à poser ou retirer"
                className="h-8 w-56 rounded-lg bg-white px-3 text-xs text-slate-900 ring-1 ring-slate-200 focus:ring-2 focus:ring-slate-300 focus:outline-none dark:bg-slate-900 dark:text-white dark:ring-slate-700"
              />
              <Button
                size="sm"
                disabled={tagAction.trim() === "" || actionDeMasse.isPending}
                onClick={() => actionDeMasse.mutate({ tag: tagAction.trim(), action: "add" })}
              >
                Poser le tag
              </Button>
              <Button
                size="sm"
                variant="ghost"
                disabled={tagAction.trim() === "" || actionDeMasse.isPending}
                onClick={() => actionDeMasse.mutate({ tag: tagAction.trim(), action: "remove" })}
              >
                Retirer
              </Button>
              <Button size="sm" variant="ghost" onClick={() => setSelection(new Set())}>
                Annuler la sélection
              </Button>
              {messageMasse !== null && (
                <span role="status" className="text-xs text-slate-600 dark:text-slate-300">
                  {messageMasse}
                </span>
              )}
            </div>
          )}
          {/* D30-002 — conteneur a defilement horizontal. Sans lui, les 746 px de
              largeur minimale de ce tableau (32+110+90+110+140+100+36 de colonnes
              fixes, 8 gouttieres de 12, 2x16 de rembourrage) etaient coupes net par
              le `overflow-hidden` de la Card. L en-tete ET le corps virtualise sont
              dedans : sinon ils defileraient separement et ne seraient plus alignes. */}
          <TableScroll template={GRID}>

          {/* Sticky header — must share GRID with CompanyRow */}
          <div
            role="row"
            className={cn(
              "sticky top-0 z-10 grid items-center gap-3 border-b border-slate-200 bg-slate-50/80 px-4 py-3 text-[11px] font-semibold tracking-wider text-slate-600 uppercase backdrop-blur",
              "dark:border-slate-800 dark:bg-slate-900/80 dark:text-slate-400",
            )}
            style={{ gridTemplateColumns: GRID }}
          >
            {/* Tout cocher ne porte que sur la PAGE affichée : « toutes les
                fiches du filtre » se compterait en millions et ne pourrait pas
                être annulé à la main. */}
            <div className="flex items-center justify-center">
              <input
                type="checkbox"
                checked={rows.length > 0 && rows.every((r) => selection.has(r.id))}
                onChange={(e) =>
                  setSelection(e.target.checked ? new Set(rows.map((r) => r.id)) : new Set())
                }
                aria-label="Sélectionner toutes les fiches affichées"
                className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600"
              />
            </div>
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
            <div style={{ height: rowVirtualizer.getTotalSize(), position: "relative" }}>
              {rowVirtualizer.getVirtualItems().map((vrow) => {
                const c = rows[vrow.index];
                if (!c) return null;
                return (
                  <div
                    key={c.id}
                    aria-rowindex={vrow.index + 1}
                    style={{
                      position: "absolute",
                      top: 0,
                      left: 0,
                      width: "100%",
                      transform: `translateY(${vrow.start}px)`,
                      height: `${vrow.size}px`,
                    }}
                  >
                    <CompanyRow
                      company={c}
                      selectionnee={selection.has(c.id)}
                      onBasculerSelection={basculerSelection}
                    />
                  </div>
                );
              })}
            </div>
          </div>
          </TableScroll>
        </Card>
      )}

      <Pagination page={page} lastPage={lastPage} total={total} onChange={setPage} />
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
      className="h-9 rounded-lg bg-white px-2 pr-7 text-sm text-slate-900 ring-1 ring-slate-200 transition focus:ring-2 focus:ring-slate-300 focus:outline-none dark:bg-slate-900 dark:text-white dark:ring-slate-700 dark:focus:ring-slate-600"
    >
      {options.map((o) => (
        <option key={o.value} value={o.value}>
          {o.label}
        </option>
      ))}
    </select>
  );
}

function UploadIcon() {
  return (
    <svg
      viewBox="0 0 20 20"
      className="h-3.5 w-3.5"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      aria-hidden
    >
      <path d="M10 14V4M5 9l5-5 5 5M4 16h12" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

function DownloadIcon() {
  return (
    <svg
      viewBox="0 0 20 20"
      className="h-3.5 w-3.5"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      aria-hidden
    >
      <path d="M10 4v10M5 9l5 5 5-5M4 16h12" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}
