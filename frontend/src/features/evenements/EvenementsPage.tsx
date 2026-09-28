/**
 * ÉVÉNEMENTS PROFESSIONNELS — la liste, les organisateurs, les relances.
 *
 * Trois onglets sur une seule page, parce qu'ils répondent à la même question
 * (« où aller, qui contacter, qui relancer ») :
 *  - Événements : `GET /evenements` avec ses filtres ;
 *  - Organisateurs : la liste Entreprises PRÉ-FILTRÉE sur le tag de provenance
 *    (même principe que l'onglet Roumanie : aucun endpoint en double) ;
 *  - Relances à faire : `GET /evenements?relance=a_faire`.
 */
import { useQuery } from "@tanstack/react-query";
import { Link } from "@tanstack/react-router";
import { useState } from "react";

import { PageHeader } from "@/components/ui/PageHeader";
import { api } from "@/lib/api";

import { Pastille } from "./Pastille";
import {
  APPELS,
  INTERVENTIONS,
  NATURES,
  PARTICIPATIONS,
  PERIODES,
  REGIONS,
  TAG_ORGANISATEURS,
  TYPES,
  libelle,
  quand,
  jour,
  lienSur,
  tonIntervention,
  type Option,
} from "./libelles";

type Organisateur = { id: number; denomination: string | null; entity_nature: string | null };

export type EvenementResume = {
  id: number;
  nom: string;
  type: string;
  date_debut: string | null;
  date_fin: string | null;
  recurrence: string | null;
  heure: string | null;
  ville: string | null;
  departement_code: string | null;
  region: string | null;
  appel_intervenants: string;
  verifie: boolean;
  participation: string;
  intervention: string;
  prochaine_relance_at: string | null;
  organisateurs: Organisateur[];
};

type ListeEvenements = {
  data: EvenementResume[];
  meta: { total: number; page: number; per_page: number };
};

type Onglet = "evenements" | "organisateurs" | "relances";

const PAR_PAGE = 50;

function Select(props: {
  label: string;
  value: string;
  options: Option[];
  onChange: (v: string) => void;
  tous?: string;
}) {
  return (
    <label className="flex flex-col gap-1 text-xs font-medium text-slate-600">
      {props.label}
      <select
        value={props.value}
        onChange={(e) => props.onChange(e.target.value)}
        className="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm text-slate-800"
      >
        {props.tous !== undefined && <option value="">{props.tous}</option>}
        {props.options.map((o) => (
          <option key={o.value || "tous"} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
    </label>
  );
}

function TableEvenements({ rows }: { rows: EvenementResume[] }) {
  return (
    <div className="overflow-x-auto rounded-lg border border-slate-200">
      <table className="min-w-full text-sm">
        <thead className="bg-slate-50 text-left text-xs text-slate-500 uppercase">
          <tr>
            <th className="px-3 py-2">Quand</th>
            <th className="px-3 py-2">Événement</th>
            <th className="px-3 py-2">Type</th>
            <th className="px-3 py-2">Où</th>
            <th className="px-3 py-2">Organisateur</th>
            <th className="px-3 py-2">Appel à intervenants</th>
            <th className="px-3 py-2">Ma participation</th>
            <th className="px-3 py-2">Intervention</th>
            <th className="px-3 py-2">Relance</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((e) => (
            <tr key={e.id} className="border-t border-slate-100 align-top">
              <td className="px-3 py-2 whitespace-nowrap text-slate-700">
                {quand(e)}
                {e.heure ? <div className="text-xs text-slate-500">{e.heure}</div> : null}
              </td>
              <td className="px-3 py-2">
                <Link
                  to="/evenements/$eventId"
                  params={{ eventId: String(e.id) }}
                  className="font-medium text-sky-700 hover:underline"
                >
                  {e.nom}
                </Link>
                {!e.verifie && <div className="text-xs text-amber-700">À vérifier</div>}
              </td>
              <td className="px-3 py-2 text-slate-600">{libelle(TYPES, e.type)}</td>
              <td className="px-3 py-2 text-slate-600">
                {e.ville ?? "—"}
                {e.region ? <div className="text-xs text-slate-500">{libelle(REGIONS, e.region)}</div> : null}
              </td>
              <td className="px-3 py-2">
                {e.organisateurs.length === 0 ? (
                  <span className="text-slate-400">—</span>
                ) : (
                  e.organisateurs.map((o) => (
                    <div key={o.id}>
                      <Link
                        to="/companies/$companyId"
                        params={{ companyId: String(o.id) }}
                        className="text-sky-700 hover:underline"
                      >
                        {o.denomination ?? `Organisateur #${o.id}`}
                      </Link>
                    </div>
                  ))
                )}
              </td>
              <td className="px-3 py-2">
                {e.appel_intervenants === "oui" ? (
                  <Pastille ton="bleu">Oui</Pastille>
                ) : (
                  libelle(APPELS, e.appel_intervenants)
                )}
              </td>
              <td className="px-3 py-2 text-slate-700">
                {libelle(PARTICIPATIONS, e.participation)}
              </td>
              <td className="px-3 py-2">
                <Pastille ton={tonIntervention(e.intervention)}>
                  {libelle(INTERVENTIONS, e.intervention)}
                </Pastille>
              </td>
              <td className="px-3 py-2 whitespace-nowrap text-slate-600">
                {jour(e.prochaine_relance_at)}
              </td>
            </tr>
          ))}
          {rows.length === 0 && (
            <tr>
              <td colSpan={9} className="px-3 py-6 text-center text-slate-500">
                Aucun événement pour ces filtres.
              </td>
            </tr>
          )}
        </tbody>
      </table>
    </div>
  );
}

function Pagination({
  page,
  total,
  onPage,
}: {
  page: number;
  total: number;
  onPage: (p: number) => void;
}) {
  const pages = Math.max(1, Math.ceil(total / PAR_PAGE));
  return (
    <div className="mt-4 flex items-center gap-2">
      <button
        type="button"
        disabled={page <= 1}
        onClick={() => onPage(page - 1)}
        className="rounded-md border border-slate-300 px-3 py-1.5 text-sm disabled:opacity-40"
      >
        Précédent
      </button>
      <span className="text-sm text-slate-600">
        Page {page} / {pages}
      </span>
      <button
        type="button"
        disabled={page >= pages}
        onClick={() => onPage(page + 1)}
        className="rounded-md border border-slate-300 px-3 py-1.5 text-sm disabled:opacity-40"
      >
        Suivant
      </button>
    </div>
  );
}

function OngletEvenements({ relancesSeulement }: { relancesSeulement: boolean }) {
  const [periode, setPeriode] = useState(relancesSeulement ? "" : "a_venir");
  const [region, setRegion] = useState("");
  const [type, setType] = useState("");
  const [participation, setParticipation] = useState("");
  const [intervention, setIntervention] = useState("");
  const [appel, setAppel] = useState("");
  const [q, setQ] = useState("");
  const [page, setPage] = useState(1);

  const filtres = {
    periode,
    region,
    type,
    participation,
    intervention,
    appel_intervenants: appel,
    q,
  };

  const { data, isLoading, isError } = useQuery({
    queryKey: ["evenements", relancesSeulement, filtres, page],
    queryFn: async () => {
      const params = new URLSearchParams({ page: String(page), per_page: String(PAR_PAGE) });
      for (const [cle, valeur] of Object.entries(filtres)) {
        if (valeur) params.set(cle, valeur);
      }
      if (relancesSeulement) params.set("relance", "a_faire");
      return (await api.get<ListeEvenements>(`/evenements?${params.toString()}`)).data;
    },
  });

  // Tout changement de filtre ramène en page 1 : sinon on reste sur une page
  // vide qui se lit comme « aucun résultat ».
  const avec = (setter: (v: string) => void) => (v: string) => {
    setter(v);
    setPage(1);
  };

  const rows = data?.data ?? [];
  const total = data?.meta.total ?? 0;

  return (
    <>
      <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4 lg:grid-cols-7">
        {!relancesSeulement && (
          <Select label="Période" value={periode} options={PERIODES} onChange={avec(setPeriode)} />
        )}
        <Select label="Région" value={region} options={REGIONS} onChange={avec(setRegion)} />
        <Select
          label="Type"
          value={type}
          options={TYPES}
          onChange={avec(setType)}
          tous="Tous types"
        />
        <Select
          label="Ma participation"
          value={participation}
          options={PARTICIPATIONS}
          onChange={avec(setParticipation)}
          tous="Toutes"
        />
        <Select
          label="Intervention"
          value={intervention}
          options={INTERVENTIONS}
          onChange={avec(setIntervention)}
          tous="Toutes"
        />
        <Select
          label="Appel à intervenants"
          value={appel}
          options={APPELS}
          onChange={avec(setAppel)}
          tous="Tous"
        />
        <label className="flex flex-col gap-1 text-xs font-medium text-slate-600">
          Recherche
          <input
            type="search"
            value={q}
            onChange={(e) => avec(setQ)(e.target.value)}
            placeholder="Nom, ville, organisateur"
            className="rounded-md border border-slate-300 px-2 py-1.5 text-sm"
          />
        </label>
      </div>

      {isLoading && <p className="text-sm text-slate-500">Chargement…</p>}
      {isError && <p className="text-sm text-red-600">Impossible de charger les événements.</p>}
      {!isLoading && !isError && (
        <>
          <p className="mb-3 text-sm text-slate-600">
            {total} événement{total > 1 ? "s" : ""}
          </p>
          <TableEvenements rows={rows} />
          <Pagination page={page} total={total} onPage={setPage} />
        </>
      )}
    </>
  );
}

type Entreprise = {
  id: number;
  denomination: string | null;
  city: string | null;
  entity_nature: string | null;
  email_generic: string | null;
  website: string | null;
};

function OngletOrganisateurs() {
  const [nature, setNature] = useState("");
  const [page, setPage] = useState(1);

  const { data, isLoading, isError } = useQuery({
    queryKey: ["organisateurs-evenements", nature, page],
    queryFn: async () => {
      const params = new URLSearchParams({
        "filter[tag]": TAG_ORGANISATEURS,
        page: String(page),
        per_page: String(PAR_PAGE),
        ...(nature ? { "filter[entity_nature]": nature } : {}),
      });
      return (
        await api.get<{ data: Entreprise[]; meta?: { total?: number } }>(
          `/companies?${params.toString()}`,
        )
      ).data;
    },
  });

  const rows = data?.data ?? [];
  const total = data?.meta?.total ?? rows.length;

  return (
    <>
      <div
        className="mb-4 flex flex-wrap gap-2"
        role="tablist"
        aria-label="Nature de l'organisateur"
      >
        {NATURES.map((n) => (
          <button
            key={n.value || "tous"}
            type="button"
            role="tab"
            aria-selected={nature === n.value}
            onClick={() => {
              setNature(n.value);
              setPage(1);
            }}
            className={
              nature === n.value
                ? "rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white"
                : "rounded-md bg-slate-100 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-200"
            }
          >
            {n.label}
          </button>
        ))}
      </div>

      {isLoading && <p className="text-sm text-slate-500">Chargement…</p>}
      {isError && <p className="text-sm text-red-600">Impossible de charger les organisateurs.</p>}
      {!isLoading && !isError && (
        <>
          <p className="mb-3 text-sm text-slate-600">
            {total} organisateur{total > 1 ? "s" : ""}
          </p>
          <div className="overflow-x-auto rounded-lg border border-slate-200">
            <table className="min-w-full text-sm">
              <thead className="bg-slate-50 text-left text-xs text-slate-500 uppercase">
                <tr>
                  <th className="px-3 py-2">Organisateur</th>
                  <th className="px-3 py-2">Nature</th>
                  <th className="px-3 py-2">Ville</th>
                  <th className="px-3 py-2">E-mail</th>
                  <th className="px-3 py-2">Site</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.id} className="border-t border-slate-100">
                    <td className="px-3 py-2">
                      <Link
                        to="/companies/$companyId"
                        params={{ companyId: String(r.id) }}
                        className="text-sky-700 hover:underline"
                      >
                        {r.denomination ?? `Organisateur #${r.id}`}
                      </Link>
                    </td>
                    <td className="px-3 py-2 text-slate-600">
                      {libelle(NATURES, r.entity_nature)}
                    </td>
                    <td className="px-3 py-2 text-slate-600">{r.city ?? "—"}</td>
                    <td className="px-3 py-2 text-slate-600">{r.email_generic ?? "—"}</td>
                    <td className="px-3 py-2">
                      {lienSur(r.website) ? (
                        <a
                          href={lienSur(r.website) ?? undefined}
                          target="_blank"
                          rel="noreferrer noopener"
                          className="text-sky-700 hover:underline"
                        >
                          Voir
                        </a>
                      ) : (
                        "—"
                      )}
                    </td>
                  </tr>
                ))}
                {rows.length === 0 && (
                  <tr>
                    <td colSpan={5} className="px-3 py-6 text-center text-slate-500">
                      Aucun organisateur pour cette nature.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
          <Pagination page={page} total={total} onPage={setPage} />
        </>
      )}
    </>
  );
}

const ONGLETS: { value: Onglet; label: string }[] = [
  { value: "evenements", label: "Événements" },
  { value: "organisateurs", label: "Organisateurs" },
  { value: "relances", label: "Relances à faire" },
];

export function EvenementsPage() {
  const [onglet, setOnglet] = useState<Onglet>("evenements");

  return (
    <div className="px-6 py-6">
      <PageHeader
        title="Événements professionnels"
        subtitle="Salons, clubs d'affaires, ateliers CCI : où aller, qui contacter pour proposer une intervention, qui relancer."
      />

      <div
        className="mb-5 flex flex-wrap gap-2 border-b border-slate-200"
        role="tablist"
        aria-label="Vue"
      >
        {ONGLETS.map((o) => (
          <button
            key={o.value}
            type="button"
            role="tab"
            aria-selected={onglet === o.value}
            onClick={() => setOnglet(o.value)}
            className={
              onglet === o.value
                ? "border-b-2 border-slate-900 px-3 py-2 text-sm font-medium text-slate-900"
                : "border-b-2 border-transparent px-3 py-2 text-sm font-medium text-slate-500 hover:text-slate-900"
            }
          >
            {o.label}
          </button>
        ))}
      </div>

      {onglet === "evenements" && <OngletEvenements key="evenements" relancesSeulement={false} />}
      {onglet === "organisateurs" && <OngletOrganisateurs />}
      {onglet === "relances" && <OngletEvenements key="relances" relancesSeulement />}
    </div>
  );
}
