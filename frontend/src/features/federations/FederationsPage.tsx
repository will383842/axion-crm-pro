/**
 * FÉDÉRATIONS ET ORGANISATIONS PROFESSIONNELLES — la liste filtrable
 * (chantier 3, 2026-09-29).
 *
 * `GET /federations` : fédérations, confédérations, ordres, chambres,
 * syndicats, associations de métiers… Filtres : famille, niveau, secteur
 * REPRÉSENTÉ, région, département, taille des adhérents, pertinence,
 * contactabilité, démarche « partenariat », événement à venir, recherche.
 * Aucune coordonnée ici : elles vivent sur la fiche, masquées selon le rôle.
 */
import { useQuery } from "@tanstack/react-query";
import { Link } from "@tanstack/react-router";
import { useState } from "react";

import { PageHeader } from "@/components/ui/PageHeader";
import { Pastille } from "@/features/evenements/Pastille";
import { api } from "@/lib/api";

import {
  CONTACTABILITE,
  EVENEMENT_A_VENIR,
  FAMILLES,
  NIVEAUX,
  PARTENARIATS,
  PERTINENCE,
  REGIONS_FR,
  SECTEURS_REPRESENTES,
  TAILLES_ADHERENTS,
  libelle,
  libelles,
  tonPartenariat,
  tonPertinence,
  type Option,
} from "./libelles";

export type FederationResume = {
  id: number;
  denomination: string | null;
  sigle: string | null;
  entity_nature: string | null;
  city: string | null;
  department_code: string | null;
  region_code: string | null;
  famille: string;
  niveau: string;
  secteurs: string[];
  tailles_adherents: string[];
  pertinence: string;
  contactabilite: string;
  certitude: string | null;
  partenariat: string;
  partenariat_relance_at: string | null;
  tete: { id: number; denomination: string | null } | null;
  nb_antennes: number;
  evenement_a_venir: boolean;
};

type Liste = {
  data: FederationResume[];
  meta: { total: number; page: number; per_page: number };
};

const PAR_PAGE = 50;

type Filtres = {
  famille: string;
  niveau: string;
  secteur: string;
  region: string;
  departement: string;
  taille_adherents: string;
  pertinence: string;
  contactabilite: string;
  partenariat: string;
  evenement_a_venir: string;
  q: string;
};

const VIDES: Filtres = {
  famille: "",
  niveau: "",
  secteur: "",
  region: "",
  departement: "",
  taille_adherents: "",
  pertinence: "",
  contactabilite: "",
  partenariat: "",
  evenement_a_venir: "",
  q: "",
};

function Choix(props: {
  label: string;
  value: string;
  options: Option[];
  tous: string;
  onChange: (v: string) => void;
}) {
  return (
    <label className="flex flex-col gap-1 text-xs font-medium text-slate-600">
      {props.label}
      <select
        value={props.value}
        onChange={(e) => props.onChange(e.target.value)}
        className="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm text-slate-800"
      >
        <option value="">{props.tous}</option>
        {props.options.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
    </label>
  );
}

export function FederationsPage() {
  const [filtres, setFiltres] = useState<Filtres>(VIDES);
  const [page, setPage] = useState(1);

  const { data, isLoading, isError } = useQuery({
    queryKey: ["federations", filtres, page],
    queryFn: async () => {
      const params = new URLSearchParams({ page: String(page), per_page: String(PAR_PAGE) });
      for (const [cle, valeur] of Object.entries(filtres)) {
        if (valeur) params.set(cle, valeur);
      }
      return (await api.get<Liste>(`/federations?${params.toString()}`)).data;
    },
  });

  // Tout changement de filtre ramène en page 1 : sinon on reste sur une page
  // vide qui se lit comme « aucun résultat ».
  const poser = (cle: keyof Filtres) => (v: string) => {
    setFiltres((f) => ({ ...f, [cle]: v }));
    setPage(1);
  };

  const rows = data?.data ?? [];
  const total = data?.meta.total ?? 0;
  const pages = Math.max(1, Math.ceil(total / PAR_PAGE));

  return (
    <div className="px-6 py-6">
      <PageHeader
        title="Fédérations et organisations professionnelles"
        subtitle="Fédérations, ordres, chambres, syndicats, associations de métiers : qui représente qui, où, et où en est le partenariat."
      />

      <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4 lg:grid-cols-6">
        <Choix label="Famille" value={filtres.famille} options={FAMILLES} tous="Toutes" onChange={poser("famille")} />
        <Choix label="Niveau" value={filtres.niveau} options={NIVEAUX} tous="Tous" onChange={poser("niveau")} />
        <Choix
          label="Secteur représenté"
          value={filtres.secteur}
          options={SECTEURS_REPRESENTES}
          tous="Tous"
          onChange={poser("secteur")}
        />
        <Choix label="Région" value={filtres.region} options={REGIONS_FR} tous="Toutes" onChange={poser("region")} />
        <label className="flex flex-col gap-1 text-xs font-medium text-slate-600">
          Département
          <input
            type="text"
            inputMode="text"
            maxLength={3}
            value={filtres.departement}
            onChange={(e) => poser("departement")(e.target.value.trim().toUpperCase())}
            placeholder="69, 2A, 974"
            className="rounded-md border border-slate-300 px-2 py-1.5 text-sm"
          />
        </label>
        <Choix
          label="Taille des adhérents"
          value={filtres.taille_adherents}
          options={TAILLES_ADHERENTS}
          tous="Toutes"
          onChange={poser("taille_adherents")}
        />
        <Choix
          label="Pertinence"
          value={filtres.pertinence}
          options={PERTINENCE}
          tous="Toutes"
          onChange={poser("pertinence")}
        />
        <Choix
          label="Contactabilité"
          value={filtres.contactabilite}
          options={CONTACTABILITE}
          tous="Toutes"
          onChange={poser("contactabilite")}
        />
        <Choix
          label="Partenariat"
          value={filtres.partenariat}
          options={PARTENARIATS}
          tous="Tous"
          onChange={poser("partenariat")}
        />
        <Choix
          label="Événement à venir"
          value={filtres.evenement_a_venir}
          options={EVENEMENT_A_VENIR}
          tous="Peu importe"
          onChange={poser("evenement_a_venir")}
        />
        <label className="col-span-2 flex flex-col gap-1 text-xs font-medium text-slate-600">
          Recherche
          <input
            type="search"
            value={filtres.q}
            onChange={(e) => poser("q")(e.target.value)}
            placeholder="Nom, sigle, ville"
            className="rounded-md border border-slate-300 px-2 py-1.5 text-sm"
          />
        </label>
      </div>

      {isLoading && <p className="text-sm text-slate-500">Chargement…</p>}
      {isError && <p className="text-sm text-red-600">Impossible de charger les fédérations.</p>}
      {!isLoading && !isError && (
        <>
          <p className="mb-3 text-sm text-slate-600">
            {total} organisme{total > 1 ? "s" : ""}
          </p>
          <div className="overflow-x-auto rounded-lg border border-slate-200">
            <table className="min-w-full text-sm">
              <thead className="bg-slate-50 text-left text-xs text-slate-500 uppercase">
                <tr>
                  <th className="px-3 py-2">Organisme</th>
                  <th className="px-3 py-2">Famille</th>
                  <th className="px-3 py-2">Niveau</th>
                  <th className="px-3 py-2">Secteurs représentés</th>
                  <th className="px-3 py-2">Où</th>
                  <th className="px-3 py-2">Tête de réseau</th>
                  <th className="px-3 py-2">Pertinence</th>
                  <th className="px-3 py-2">Contact</th>
                  <th className="px-3 py-2">Partenariat</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((f) => (
                  <tr key={f.id} className="border-t border-slate-100 align-top">
                    <td className="px-3 py-2">
                      <Link
                        to="/federations/$companyId"
                        params={{ companyId: String(f.id) }}
                        className="font-medium text-sky-700 hover:underline"
                      >
                        {f.denomination ?? `Organisme #${f.id}`}
                      </Link>
                      {f.sigle ? <div className="text-xs text-slate-500">{f.sigle}</div> : null}
                      {f.nb_antennes > 0 ? (
                        <div className="text-xs text-slate-500">
                          {f.nb_antennes} antenne{f.nb_antennes > 1 ? "s" : ""}
                        </div>
                      ) : null}
                      {f.evenement_a_venir ? (
                        <div className="text-xs text-emerald-700">Événement à venir</div>
                      ) : null}
                    </td>
                    <td className="px-3 py-2 text-slate-600">{libelle(FAMILLES, f.famille)}</td>
                    <td className="px-3 py-2 text-slate-600">{libelle(NIVEAUX, f.niveau)}</td>
                    <td className="px-3 py-2 text-slate-600">{libelles(SECTEURS_REPRESENTES, f.secteurs)}</td>
                    <td className="px-3 py-2 text-slate-600">
                      {f.city ?? "—"}
                      {f.department_code ? (
                        <div className="text-xs text-slate-500">
                          {f.department_code}
                          {f.region_code ? ` · ${libelle(REGIONS_FR, f.region_code)}` : ""}
                        </div>
                      ) : null}
                    </td>
                    <td className="px-3 py-2">
                      {f.tete ? (
                        <Link
                          to="/federations/$companyId"
                          params={{ companyId: String(f.tete.id) }}
                          className="text-sky-700 hover:underline"
                        >
                          {f.tete.denomination ?? `Organisme #${f.tete.id}`}
                        </Link>
                      ) : (
                        <span className="text-slate-400">—</span>
                      )}
                    </td>
                    <td className="px-3 py-2">
                      <Pastille ton={tonPertinence(f.pertinence)}>{libelle(PERTINENCE, f.pertinence)}</Pastille>
                    </td>
                    <td className="px-3 py-2 text-slate-600">{libelle(CONTACTABILITE, f.contactabilite)}</td>
                    <td className="px-3 py-2">
                      <Pastille ton={tonPartenariat(f.partenariat)}>{libelle(PARTENARIATS, f.partenariat)}</Pastille>
                    </td>
                  </tr>
                ))}
                {rows.length === 0 && (
                  <tr>
                    <td colSpan={9} className="px-3 py-6 text-center text-slate-500">
                      Aucun organisme pour ces filtres.
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
              onClick={() => setPage(page - 1)}
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
              onClick={() => setPage(page + 1)}
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
