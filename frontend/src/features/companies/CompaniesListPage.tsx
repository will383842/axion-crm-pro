import { useEffect, useId, useRef, useState, useMemo } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link, useNavigate, useSearch } from "@tanstack/react-router";
import { useVirtualizer } from "@tanstack/react-virtual";
import { Building2 } from "lucide-react";
import {
  Button,
  Card,
  Champ,
  CompaniesTableSkeleton,
  EmptyState,
  KpiCard,
  PageHeader,
  QueryErrorState,
  SearchInput,
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
  JOIGNABILITE_OPTIONS,
  NATURE_OPTIONS,
  SECTEUR_OPTIONS,
  TAILLE_OPTIONS,
} from "@/lib/prospection-referentiels";
import { indicateursEntreprises, type StatsBase } from "./indicateurs";
import { toast } from "sonner";
import { CompanyRow, COMPANY_ROW_GRID, type CompanyRowData } from "./components/CompanyRow";
import { EFFECTIF_OPTIONS } from "./effectif";
import {
  EMPTY_FILTER,
  LONGUEUR_MAX_ETIQUETTE,
  LONGUEUR_MAX_NAF,
  LONGUEUR_MAX_RECHERCHE,
  PRIORITY_OPTIONS,
  PROSPECTION_TABS,
  QUALITY_OPTIONS,
  filtreDepuisRecherche,
  nafApplicable,
  rechercheDepuisFiltre,
  type Filter,
} from "./filtresUrl";
import { Pagination } from "./components/Pagination";
import { FERMEES_INCLURE, FERMEES_SEULES } from "./fermees";
import { AjouterAUneListe } from "@/features/listes/AjouterAUneListe";

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

// Tailles, secteurs et natures : le référentiel unique, GÉNÉRÉ depuis le
// serveur (`@/lib/referentiels.generated`). Les listes recopiées qui vivaient
// ici (« artisan », « grande_entreprise », 15 secteurs d'une seule des deux
// listes du serveur) ont disparu le 2026-09-28.

// Qualité, priorité, onglets de prospection, état des filtres et lecture de
// l'adresse : `./filtresUrl.ts` (lot 4, 2026-10-02).

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

  // Lot 4 — l'état des filtres NAÎT de l'adresse (`?quality=basique`,
  // `?department_code=69`…), revalidée ici même si la route l'a déjà fait :
  // l'écran ne suppose rien de qui l'a monté.
  const navigate = useNavigate();
  const rechercheUrl = useSearch({ strict: false });
  const filtreUrl = useMemo(
    () => filtreDepuisRecherche(rechercheUrl as Record<string, unknown>),
    [rechercheUrl],
  );
  const cleUrl = JSON.stringify(filtreUrl);

  const [page, setPage] = useState(1);
  const [filter, setFilter] = useState<Filter>(filtreUrl);
  // Le dernier état IMPOSÉ d'un bloc (adresse, « Effacer les filtres »). Tant
  // que `filter` est cet objet-là, personne n'a tapé depuis : la requête prend
  // les valeurs IMMÉDIATES. Sans cela, l'anti-rebond rendait encore 300 ms
  // l'ancienne recherche (« boul »), qui repartait au serveur et dans l'adresse.
  const [filtreImpose, setFiltreImpose] = useState<Filter>(filtreUrl);
  const [exporting, setExporting] = useState(false);
  const [plusDeFiltres, setPlusDeFiltres] = useState(false);
  const idPanneau = useId();

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
  const filtreInterroge = useMemo<Filter>(() => {
    // NAF : `nafApplicable` décide pour la requête, l'export ET l'adresse —
    // jamais un filtre appliqué qui disparaîtrait au rechargement.
    if (filter === filtreImpose) return { ...filter, naf: nafApplicable(filter.naf) };
    return { ...filter, search: rechercheDifferee, naf: nafApplicable(nafDiffere), tag: tagDiffere };
  }, [filter, filtreImpose, rechercheDifferee, nafDiffere, tagDiffere]);

  // ── Adresse ⇄ filtres ────────────────────────────────────────────────
  // `derniereCleConnue` est la version des filtres que l'adresse porte (ou va
  // porter). Elle évite la boucle : l'écran écrit l'adresse, l'adresse change,
  // l'écran ne la relit PAS comme un ordre venu d'ailleurs.
  const derniereCleConnue = useRef(cleUrl);

  // Filtres → adresse. On écrit la valeur DIFFÉRÉE (300 ms pour les saisies
  // libres) et en remplacement : pas une entrée d'historique par lettre.
  useEffect(() => {
    const recherche = rechercheDepuisFiltre(filtreInterroge);
    const cle = JSON.stringify(filtreDepuisRecherche(recherche));
    if (cle === derniereCleConnue.current) return;
    derniereCleConnue.current = cle;
    void navigate({ to: "/companies", search: recherche, replace: true });
  }, [filtreInterroge, navigate]);

  // Adresse → filtres, quand elle change d'AILLEURS (lien du tableau de bord
  // ou du menu suivi alors que l'écran est déjà ouvert).
  useEffect(() => {
    if (cleUrl === derniereCleConnue.current) return;
    derniereCleConnue.current = cleUrl;
    setFilter(filtreUrl);
    setFiltreImpose(filtreUrl);
    setPage(1);
  }, [cleUrl, filtreUrl]);

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
      ...(nafApplicable(filter.naf) ? { "filter[naf]": nafApplicable(filter.naf) } : {}),
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
      ...(filter.joignabilite ? { "filter[joignabilite]": filter.joignabilite } : {}),
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

  const { data, isLoading, error, refetch } = useQuery<CompaniesResponse>({
    // ⚠️ `filtreInterroge`, PAS `filter` : c'est ici que se joue G42-010. La
    // clé porte la valeur DIFFÉRÉE, donc elle ne change pas à chaque touche.
    queryKey: ["companies", page, filtreInterroge],
    queryFn: async () => {
      const f = filtreInterroge;
      const params = new URLSearchParams({
        page: String(page),
        per_page: "100",
        // Lot 3 — seulement les colonnes de la grille (345 Ko → ~30 Ko / 100 lignes).
        vue: "liste",
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
        ...(f.joignabilite ? { "filter[joignabilite]": f.joignabilite } : {}),
        // Fermées selon l'INSEE : masquées par le serveur sans ce paramètre.
        ...(f.fermees ? { fermees: f.fermees } : {}),
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

  // Lot 3 (2026-10-02) — les indicateurs portent sur TOUTE la base (serveur,
  // `/companies/stats`, mis en cache), plus sur les 100 lignes affichées :
  // « Enrichies 100 % » venait d'une page triée par score, toute enrichie.
  const { data: statsBase } = useQuery({
    queryKey: ["companies-stats"],
    queryFn: async () => (await api.get<StatsBase>("/companies/stats")).data,
    staleTime: 5 * 60 * 1000,
  });
  const kpis = useMemo(() => indicateursEntreprises(statsBase), [statsBase]);

  const setFilterAndReset = (next: Partial<Filter>) => {
    setFilter((f) => ({ ...f, ...next }));
    setPage(1);
  };

  // Lot 4 (audit P2-4/5) — quatre filtres principaux toujours visibles ; les
  // autres derrière « Plus de filtres ». Les onglets de prospection comptent
  // comme un filtre actif (« Effacer les filtres » les remet sur « Tous »).
  const clesActives = (Object.keys(filter) as Array<keyof Filter>).filter((k) => filter[k] !== "");
  const activeFilterCount = clesActives.length;
  const hasActiveFilter = activeFilterCount > 0;
  const cachesActifs = clesActives.filter((k) => FILTRES_SECONDAIRES.includes(k)).length;
  // Un filtre caché actif garde le panneau ouvert : on ne filtre jamais en
  // cachant le réglage qui explique la liste.
  const panneauOuvert = plusDeFiltres || cachesActifs > 0;

  return (
    <div>
      <PageHeader
        title="Entreprises"
        subtitle={
          <>
            <span className="font-semibold text-slate-700 tabular-nums dark:text-slate-200">
              {(total ?? 0).toLocaleString("fr-FR")}
            </span>{" "}
            {filter.fermees === FERMEES_SEULES
              ? "entreprises fermées"
              : filter.fermees === FERMEES_INCLURE
                ? "entreprises, fermées comprises"
                : "entreprises actives"}
          </>
        }
        actions={
          <div className="flex items-center gap-2">
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
              Récupérer des entreprises
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
          value={kpis.enrichies}
          sublabel={[kpis.enrichiesSous, hasActiveFilter ? 'toute la base, hors filtres' : null, kpis.fraicheur].filter(Boolean).join(' · ')}
          {...(kpis.enrichiesPct !== null ? { progress: kpis.enrichiesPct } : {})}
        />
        <KpiCard
          tone="emerald"
          label="Taille la plus fréquente"
          value={kpis.taille}
          sublabel={hasActiveFilter ? `${kpis.tailleSous} · toute la base` : kpis.tailleSous}
          {...(kpis.taillePct !== null ? { progress: kpis.taillePct } : {})}
        />
        <KpiCard
          tone="amber"
          label="Secteur le plus fréquent"
          value={kpis.secteur}
          sublabel={hasActiveFilter ? `${kpis.secteurSous} · toute la base` : kpis.secteurSous}
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

      {/* Filtres — quatre principaux, les autres repliés (lot 4). */}
      <div className="mb-4 rounded-xl bg-white/70 p-3 ring-1 ring-slate-200/60">
        <div className="flex flex-wrap items-end gap-3">
          <Champ label="Recherche" htmlFor={null}>
            <SearchInput
              label="Rechercher une entreprise"
              value={filter.search}
              onChange={(v) => setFilterAndReset({ search: v.slice(0, LONGUEUR_MAX_RECHERCHE) })}
              placeholder="Rechercher une entreprise…"
              className="w-72"
            />
          </Champ>
          {/* Liste plutôt que saisie libre : taper « 075 » ou « 7 5 » rendait
              une liste vide qui se lit comme « aucun résultat », sans jamais
              dire que le code était faux. */}
          <FilterSelect
            label="Département"
            value={filter.department_code}
            onChange={(v) => setFilterAndReset({ department_code: v })}
            options={optionsDepartements}
          />
          <FilterSelect
            label="Taille"
            value={filter.size}
            onChange={(v) => setFilterAndReset({ size: v })}
            options={TAILLE_OPTIONS}
          />
          <FilterSelect
            label="Secteur d’activité"
            value={filter.sector_main}
            onChange={(v) => setFilterAndReset({ sector_main: v })}
            options={SECTEUR_OPTIONS}
          />
          {/* 04/10/2026 — les entreprises fermées selon l'INSEE sont masquées
              par défaut. Le réglage reste TOUJOURS visible : une liste ne doit
              jamais cacher quelque chose sans le dire. */}
          <Champ label="Entreprises fermées" htmlFor={null}>
            <div className="flex h-9 items-center gap-3">
              <label className="flex items-center gap-2 text-sm text-slate-700">
                <input
                  type="checkbox"
                  checked={filter.fermees !== ""}
                  onChange={(e) =>
                    setFilterAndReset({ fermees: e.target.checked ? FERMEES_INCLURE : "" })
                  }
                  className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                />
                Afficher les entreprises fermées
              </label>
              {filter.fermees !== "" ? (
                <label className="flex items-center gap-2 text-sm text-slate-700">
                  <input
                    type="checkbox"
                    checked={filter.fermees === FERMEES_SEULES}
                    onChange={(e) =>
                      setFilterAndReset({
                        fermees: e.target.checked ? FERMEES_SEULES : FERMEES_INCLURE,
                      })
                    }
                    className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                  />
                  Uniquement celles-ci
                </label>
              ) : null}
            </div>
          </Champ>
          <div className="flex flex-wrap items-center gap-2">
            <Button
              variant="secondary"
              size="md"
              aria-expanded={panneauOuvert}
              aria-controls={idPanneau}
              disabled={cachesActifs > 0}
              onClick={() => setPlusDeFiltres((o) => !o)}
            >
              {panneauOuvert && cachesActifs === 0
                ? "Moins de filtres"
                : `Plus de filtres (${FILTRES_SECONDAIRES.length})`}
              {cachesActifs > 0
                ? ` · ${cachesActifs} actif${cachesActifs > 1 ? "s" : ""}`
                : ""}
            </Button>
            {hasActiveFilter ? (
              <>
                <span className="text-xs text-slate-500">
                  {activeFilterCount} filtre{activeFilterCount > 1 ? "s" : ""} actif
                  {activeFilterCount > 1 ? "s" : ""}
                </span>
                <Button
                  variant="ghost"
                  size="md"
                  onClick={() => {
                    setFilter(EMPTY_FILTER);
                    setFiltreImpose(EMPTY_FILTER);
                    setPlusDeFiltres(false);
                    setPage(1);
                  }}
                >
                  Effacer les filtres
                </Button>
              </>
            ) : null}
          </div>
        </div>

        {panneauOuvert ? (
          <div
            id={idPanneau}
            className="mt-3 flex flex-wrap items-end gap-3 border-t border-slate-200 pt-3"
          >
            <FilterSelect
              label="Effectif"
              value={filter.effectif}
              onChange={(v) => setFilterAndReset({ effectif: v })}
              options={EFFECTIF_OPTIONS}
            />
            <FilterSelect
              label="Région"
              value={filter.region_code}
              onChange={(v) => setFilterAndReset({ region_code: v })}
              options={optionsRegions}
            />
            <FilterSelect
              label="Pays d’immatriculation"
              value={filter.country_code}
              onChange={(v) => setFilterAndReset({ country_code: v })}
              options={COUNTRY_OPTIONS}
            />
            {/* « Prêt pour une campagne » : la définition CALCULÉE
                (`EligibiliteCampagne` côté serveur), pas un bac figé — une
                fiche sort d'elle-même de la liste le jour où l'adresse
                s'oppose. Le PALIER, lui, cible : A = adresse sur le domaine
                du site (165 587 fiches), c'est par là qu'une campagne
                commence, pas par les 255 290 d'un bloc. */}
            <FilterSelect
              label="Prêtes pour une campagne"
              value={filter.eligible_campagne}
              onChange={(v) => setFilterAndReset({ eligible_campagne: v })}
              options={ELIGIBILITE_OPTIONS}
            />
            <FilterSelect
              label="Qualité de l’adresse e-mail"
              value={filter.best_email_confidence}
              onChange={(v) => setFilterAndReset({ best_email_confidence: v })}
              options={CONFIANCE_EMAIL_OPTIONS}
            />
            <FilterSelect
              label="Nature"
              value={filter.entity_nature}
              onChange={(v) => setFilterAndReset({ entity_nature: v })}
              options={NATURE_OPTIONS}
            />
            <FilterSelect
              label="Joignabilité"
              value={filter.joignabilite}
              onChange={(v) => setFilterAndReset({ joignabilite: v })}
              options={JOIGNABILITE_OPTIONS}
            />
            <FilterSelect
              label="Qualité de la fiche"
              value={filter.quality}
              onChange={(v) => setFilterAndReset({ quality: v })}
              options={QUALITY_OPTIONS}
            />
            <FilterSelect
              label="Priorité"
              value={filter.priority}
              onChange={(v) => setFilterAndReset({ priority: v })}
              options={PRIORITY_OPTIONS}
            />
            <Champ label="Code NAF" htmlFor={`${idPanneau}-naf`}>
              <input
                id={`${idPanneau}-naf`}
                type="text"
                value={filter.naf}
                maxLength={LONGUEUR_MAX_NAF}
                onChange={(e) => setFilterAndReset({ naf: e.target.value })}
                placeholder="68.31Z"
                className="h-9 w-28 rounded-lg bg-white px-3 font-mono text-sm text-slate-900 ring-1 ring-slate-200 transition placeholder:text-slate-400 focus:ring-2 focus:ring-slate-300 focus:outline-none"
              />
            </Champ>
            {/* Retrouver un SEGMENT constitué (campagne, import, sélection) :
                l'API l'acceptait déjà, rien ne permettait de le demander. */}
            <Champ label="Étiquette" htmlFor={`${idPanneau}-etiquette`}>
              <input
                id={`${idPanneau}-etiquette`}
                type="text"
                value={filter.tag}
                maxLength={LONGUEUR_MAX_ETIQUETTE}
                onChange={(e) => setFilterAndReset({ tag: e.target.value })}
                placeholder="implantation-ro…"
                className="h-9 w-44 rounded-lg bg-white px-3 text-sm text-slate-900 ring-1 ring-slate-200 transition placeholder:text-slate-400 focus:ring-2 focus:ring-slate-300 focus:outline-none"
              />
            </Champ>
            {/* « Ce qui est arrivé depuis lundi » — la question la plus
                fréquente, impossible à poser jusqu'ici. */}
            <Champ label="Fiches créées après le" htmlFor={`${idPanneau}-apres`}>
              <input
                id={`${idPanneau}-apres`}
                type="date"
                value={filter.cree_apres}
                onChange={(e) => setFilterAndReset({ cree_apres: e.target.value })}
                className="h-9 rounded-lg bg-white px-3 text-sm text-slate-900 ring-1 ring-slate-200 transition focus:ring-2 focus:ring-slate-300 focus:outline-none"
              />
            </Champ>
            <Champ label="Fiches créées avant le" htmlFor={`${idPanneau}-avant`}>
              <input
                id={`${idPanneau}-avant`}
                type="date"
                value={filter.cree_avant}
                onChange={(e) => setFilterAndReset({ cree_avant: e.target.value })}
                className="h-9 rounded-lg bg-white px-3 text-sm text-slate-900 ring-1 ring-slate-200 transition focus:ring-2 focus:ring-slate-300 focus:outline-none"
              />
            </Champ>
          </div>
        ) : null}
      </div>

      {/* P0-3 — une panne n'est jamais « Aucune entreprise ». */}
      {error !== null && data === undefined ? (
        <QueryErrorState error={error} contexte="la liste des entreprises" onRetry={() => void refetch()} />
      ) : isLoading ? (
        <CompaniesTableSkeleton />
      ) : rows.length === 0 ? (
        <EmptyState
          icon={<Building2 />}
          title="Aucune entreprise"
          description={
            hasActiveFilter
              ? "Aucun résultat avec ces filtres."
              : "Aucune entreprise pour l’instant. La carte de France permet d’en récupérer."
          }
          action={
            <Link
              to="/coverage"
              className="inline-flex h-9 items-center justify-center rounded-lg bg-gradient-to-b from-slate-900 to-slate-800 px-4 text-sm font-medium text-white"
            >
              Voir la carte
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
                placeholder="Étiquette existante (campagne-ro…)"
                aria-label="Étiquette à poser ou retirer"
                className="h-8 w-56 rounded-lg bg-white px-3 text-xs text-slate-900 ring-1 ring-slate-200 focus:ring-2 focus:ring-slate-300 focus:outline-none dark:bg-slate-900 dark:text-white dark:ring-slate-700"
              />
              <Button
                size="sm"
                disabled={tagAction.trim() === "" || actionDeMasse.isPending}
                onClick={() => actionDeMasse.mutate({ tag: tagAction.trim(), action: "add" })}
              >
                Poser l’étiquette
              </Button>
              <Button
                size="sm"
                variant="ghost"
                disabled={tagAction.trim() === "" || actionDeMasse.isPending}
                onClick={() => actionDeMasse.mutate({ tag: tagAction.trim(), action: "remove" })}
              >
                Retirer
              </Button>
              {/* 2026-09-30 — les fiches cochées vont dans une liste manuelle. */}
              <AjouterAUneListe companyIds={[...selection]} onAjoute={() => setSelection(new Set())} />
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
              "sticky top-0 z-10 grid items-center gap-3 border-b border-slate-200 bg-slate-50/80 px-4 py-3 text-xs font-semibold tracking-wider text-slate-600 uppercase backdrop-blur",
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
            <div>Activité</div>
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

/**
 * Les filtres rangés derrière « Plus de filtres » : leur nombre s'affiche sur
 * le bouton, et un seul d'entre eux actif garde le panneau ouvert.
 */
const FILTRES_SECONDAIRES: ReadonlyArray<keyof Filter> = [
  "effectif",
  "region_code",
  "country_code",
  "eligible_campagne",
  "best_email_confidence",
  "entity_nature",
  "joignabilite",
  "quality",
  "priority",
  "naf",
  "tag",
  "cree_apres",
  "cree_avant",
];

function FilterSelect({
  label,
  value,
  onChange,
  options,
}: {
  label: string;
  value: string;
  onChange: (v: string) => void;
  options: Array<{ value: string; label: string }>;
}) {
  const id = useId();
  return (
    <Champ label={label} htmlFor={id}>
      <select
        id={id}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="h-9 max-w-[16rem] rounded-lg bg-white px-2 pr-7 text-sm text-slate-900 ring-1 ring-slate-200 transition focus:ring-2 focus:ring-slate-300 focus:outline-none"
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
