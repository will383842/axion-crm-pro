/**
 * DOUBLONS À VÉRIFIER (chantier 5, 2026-09-30).
 *
 * `GET /doublons` : les paires de fiches qui se ressemblent (même nom et même
 * code postal, même site…), la fiche qu'on GARDERAIT à gauche, celle qui
 * serait ABSORBÉE à droite. Deux gestes par paire :
 *  - « Fusionner » : la fiche absorbée part à la CORBEILLE (jamais supprimée),
 *    ses personnes, étiquettes, événements et démarches sont rattachés à la
 *    fiche gardée ; la fusion s'annule par `crm:doublons:fusionner --annuler` ;
 *  - « Ce ne sont pas des doublons » : la paire n'est plus jamais proposée.
 * Une adresse e-mail partagée ne fait JAMAIS une paire (cabinet comptable,
 * domiciliation) : l'en-tête ne fait que les compter.
 */
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link } from "@tanstack/react-router";
import { useState } from "react";
import { toast } from "sonner";

import { CheckCircle2 } from "lucide-react";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageHeader } from "@/components/ui/PageHeader";
import { api } from "@/lib/api";

export type FicheDoublon = {
  id: number;
  denomination: string | null;
  siren: string | null;
  identifiant: string | null;
  code_postal: string | null;
  ville: string | null;
  site: string | null;
  source: string | null;
  nb_contacts: number;
  protegee: boolean;
};

export type PaireDoublon = {
  id: number;
  motif: string;
  motif_libelle: string;
  score: number;
  fusion_auto: boolean;
  garde: FicheDoublon;
  absorbee: FicheDoublon;
};

type Liste = {
  data: PaireDoublon[];
  meta: {
    total: number;
    page: number;
    per_page: number;
    par_motif: Record<string, number>;
    adresses_partagees: Record<string, number>;
    motifs: Record<string, string>;
  };
};

const PAR_PAGE = 50;

const NATURES_ADRESSE: Record<string, string> = {
  cabinet_comptable: "cabinet comptable",
  domiciliation: "domiciliation",
  groupe: "groupe ou siège",
  inconnue: "nature inconnue",
};

function Fiche({ f, role }: { f: FicheDoublon; role: string }) {
  return (
    <div className="flex flex-col gap-0.5 text-sm">
      <span className="text-xs font-medium tracking-wide text-slate-500 uppercase">{role}</span>
      <Link
        to="/companies/$companyId"
        params={{ companyId: String(f.id) }}
        className="font-medium text-indigo-700 hover:underline"
      >
        {f.denomination ?? `Fiche ${f.id}`}
      </Link>
      <span className="text-slate-600">
        {f.siren
          ? `SIREN ${f.siren}`
          : f.identifiant
            ? `Identifiant ${f.identifiant}`
            : "Sans identifiant"}
      </span>
      <span className="text-slate-600">
        {[f.code_postal, f.ville].filter(Boolean).join(" ") || "Adresse inconnue"}
      </span>
      {f.site && <span className="truncate text-slate-500">{f.site}</span>}
      <span className="text-slate-500">
        {f.nb_contacts} personne{f.nb_contacts > 1 ? "s" : ""}
        {f.source ? ` · source ${f.source}` : ""}
        {f.protegee ? " · protégée" : ""}
      </span>
    </div>
  );
}

export function DoublonsPage() {
  const qc = useQueryClient();
  const [motif, setMotif] = useState("");
  const [page, setPage] = useState(1);

  const { data, isLoading, isError } = useQuery({
    queryKey: ["doublons", motif, page],
    queryFn: async () => {
      const params = new URLSearchParams({ page: String(page), per_page: String(PAR_PAGE) });
      if (motif) params.set("motif", motif);
      return (await api.get<Liste>(`/doublons?${params.toString()}`)).data;
    },
  });

  const erreur = (err: unknown, defaut: string) => {
    const reponse = (err as { response?: { status?: number; data?: { message?: string } } })
      .response;
    if (reponse?.status === 403) return "Votre compte n'a pas le droit de faire ce geste.";
    return reponse?.data?.message ?? defaut;
  };

  const fusionner = useMutation({
    mutationFn: async (p: PaireDoublon) =>
      (await api.post<{ fusion_id: number }>(`/doublons/${p.id}/fusionner`)).data,
    onSuccess: (r) => {
      void qc.invalidateQueries({ queryKey: ["doublons"] });
      toast.success(`Fiches fusionnées (fusion n° ${r.fusion_id}, annulable).`);
    },
    onError: (err: unknown) => toast.error(erreur(err, "La fusion n'a pas été faite.")),
  });

  const ignorer = useMutation({
    mutationFn: async (p: PaireDoublon) =>
      (await api.post<{ ok: boolean }>(`/doublons/${p.id}/ignorer`)).data,
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ["doublons"] });
      toast.success("Paire écartée : elle ne sera plus proposée.");
    },
    onError: (err: unknown) => toast.error(erreur(err, "La paire n'a pas été écartée.")),
  });

  const rows = data?.data ?? [];
  const total = data?.meta.total ?? 0;
  const pages = Math.max(1, Math.ceil(total / PAR_PAGE));
  const motifs = data?.meta.motifs ?? {};
  const adresses = Object.entries(data?.meta.adresses_partagees ?? {});

  return (
    <div>
      <PageHeader
        title="Doublons à vérifier"
        subtitle="Des fiches qui se ressemblent. Fusionner met la fiche de droite à la corbeille (jamais supprimée) et rattache tout à celle de gauche ; la fusion reste annulable."
      />

      {adresses.length > 0 && (
        <p className="mb-4 text-sm text-slate-600">
          Adresses e-mail partagées par plusieurs fiches (jamais fusionnées pour autant) :{" "}
          {adresses.map(([nature, n]) => `${n} ${NATURES_ADRESSE[nature] ?? nature}`).join(", ")}.
        </p>
      )}

      <label className="mb-4 flex max-w-md flex-col gap-1 text-xs font-medium text-slate-600">
        Motif
        <select
          value={motif}
          onChange={(e) => {
            setMotif(e.target.value);
            setPage(1);
          }}
          className="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm text-slate-800"
        >
          <option value="">Tous</option>
          {Object.entries(motifs).map(([valeur, libelle]) => (
            <option key={valeur} value={valeur}>
              {libelle} ({data?.meta.par_motif[valeur] ?? 0})
            </option>
          ))}
        </select>
      </label>

      {isLoading && <p className="text-sm text-slate-500">Chargement…</p>}
      {isError && (
        <p className="text-sm text-red-600">La file des doublons n'a pas pu être chargée.</p>
      )}
      {!isLoading && !isError && rows.length === 0 && (
        <EmptyState
          icon={<CheckCircle2 className="text-emerald-600" />}
          title="Aucun doublon à vérifier"
          description="Tout est en ordre : aucune paire de fiches n’attend votre décision."
        />
      )}

      <ul className="flex flex-col gap-3">
        {rows.map((p) => (
          <li
            key={p.id}
            className="rounded-lg border border-slate-200 bg-white p-4"
            data-testid={`paire-${p.id}`}
          >
            <p className="mb-3 text-sm font-medium text-slate-800">
              {p.motif_libelle}
              {p.fusion_auto && (
                <span className="ml-2 rounded bg-emerald-50 px-1.5 py-0.5 text-xs text-emerald-700">
                  preuve certaine
                </span>
              )}
            </p>
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
              <Fiche f={p.garde} role="Fiche gardée" />
              <Fiche f={p.absorbee} role="Fiche absorbée (corbeille)" />
            </div>
            <div className="mt-3 flex flex-wrap gap-2">
              <button
                type="button"
                disabled={fusionner.isPending || ignorer.isPending}
                onClick={() => {
                  if (
                    window.confirm(
                      `Fusionner « ${p.absorbee.denomination ?? p.absorbee.id} » dans « ${p.garde.denomination ?? p.garde.id} » ? La fiche absorbée ira à la corbeille ; tout sera rattaché à la fiche gardée.`,
                    )
                  ) {
                    fusionner.mutate(p);
                  }
                }}
                className="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
              >
                Fusionner
              </button>
              <button
                type="button"
                disabled={fusionner.isPending || ignorer.isPending}
                onClick={() => ignorer.mutate(p)}
                className="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50"
              >
                Ce ne sont pas des doublons
              </button>
            </div>
          </li>
        ))}
      </ul>

      {pages > 1 && (
        <div className="mt-4 flex items-center gap-3 text-sm">
          <button
            type="button"
            disabled={page <= 1}
            onClick={() => setPage((n) => n - 1)}
            className="rounded border px-2 py-1 disabled:opacity-50"
          >
            Précédente
          </button>
          <span>
            Page {page} sur {pages} — {total} paire{total > 1 ? "s" : ""}
          </span>
          <button
            type="button"
            disabled={page >= pages}
            onClick={() => setPage((n) => n + 1)}
            className="rounded border px-2 py-1 disabled:opacity-50"
          >
            Suivante
          </button>
        </div>
      )}
    </div>
  );
}
