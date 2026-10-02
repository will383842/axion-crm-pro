/**
 * FICHE D'UN ÉVÉNEMENT — ce qu'on sait de l'événement, ses organisateurs, et
 * la démarche de Will (participation, intervention, relance, note) avec son
 * historique daté.
 *
 * Les coordonnées des personnes ne s'affichent pas ici : elles vivent sur la
 * fiche de l'organisateur, qui applique déjà le masquage selon le rôle.
 */
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link, useParams } from "@tanstack/react-router";
import { useEffect, useState, type ReactNode } from "react";
import { toast } from "sonner";

import { PageHeader } from "@/components/ui/PageHeader";
import { api } from "@/lib/api";

import type { EvenementResume } from "./EvenementsPage";
import { Pastille } from "./Pastille";
import {
  APPELS,
  ETAPES,
  INTERVENTIONS,
  NATURES,
  PARTICIPATIONS,
  REGIONS,
  TYPES,
  libelle,
  quand,
  jour,
  lienSur,
  tonIntervention,
} from "./libelles";

type Organisateur = {
  id: number;
  denomination: string | null;
  entity_nature: string | null;
  city: string | null;
  website: string | null;
  contact_form_url: string | null;
};

type Fiche = Omit<EvenementResume, "organisateurs"> & {
  lieu: string | null;
  public_vise: string | null;
  taille: string | null;
  prix: string | null;
  lien_evenement: string | null;
  lien_inscription: string | null;
  appel_intervenants_limite: string | null;
  source_url: string | null;
  notes: string | null;
  demarche_note: string | null;
  organisateurs: Organisateur[];
  historique: { id: number; kind: string; occurred_at: string }[];
};

type Demarche = {
  participation: string;
  intervention: string;
  prochaine_relance_at: string | null;
  demarche_note: string | null;
};

function Ligne({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="grid grid-cols-3 gap-2 py-1.5 text-sm">
      <dt className="text-slate-500">{label}</dt>
      <dd className="col-span-2 text-slate-800">{children}</dd>
    </div>
  );
}

function Lien({ href, children }: { href: string | null; children: ReactNode }) {
  const sur = lienSur(href);
  if (!sur) return <>—</>;
  return (
    <a
      href={sur}
      target="_blank"
      rel="noreferrer noopener"
      className="text-sky-700 hover:underline"
    >
      {children}
    </a>
  );
}

export function EvenementDetailPage() {
  const { eventId } = useParams({ strict: false });
  const qc = useQueryClient();

  const {
    data: e,
    isLoading,
    isError,
  } = useQuery({
    queryKey: ["evenement", eventId],
    enabled: Boolean(eventId),
    queryFn: async () => (await api.get<Fiche>(`/evenements/${eventId}`)).data,
  });

  const [form, setForm] = useState<Demarche | null>(null);
  useEffect(() => {
    if (e) {
      setForm({
        participation: e.participation,
        intervention: e.intervention,
        prochaine_relance_at: e.prochaine_relance_at ? e.prochaine_relance_at.slice(0, 10) : null,
        demarche_note: e.demarche_note,
      });
    }
  }, [e]);

  const enregistrer = useMutation({
    mutationFn: async (d: Demarche) =>
      (await api.patch<Fiche>(`/evenements/${eventId}/demarche`, d)).data,
    onSuccess: (fiche) => {
      qc.setQueryData(["evenement", eventId], fiche);
      void qc.invalidateQueries({ queryKey: ["evenements"] });
      void qc.invalidateQueries({ queryKey: ["evenements-entreprise"] });
      toast.success("Démarche enregistrée");
    },
    onError: (err: unknown) => {
      const statut = (err as { response?: { status?: number } }).response?.status;
      toast.error(
        statut === 403
          ? "Votre compte n'a pas le droit de modifier la démarche."
          : "La démarche n'a pas été enregistrée.",
      );
    },
  });

  if (isLoading) return <p className="text-sm text-slate-500">Chargement…</p>;
  if (isError || !e)
    return <p className="text-sm text-red-600">Événement introuvable.</p>;

  return (
    <div>
      <p className="mb-2 text-sm">
        <Link to="/evenements" className="text-sky-700 hover:underline">
          ← Tous les événements
        </Link>
      </p>
      <PageHeader
        title={e.nom}
        subtitle={`${libelle(TYPES, e.type)} · ${quand(e)}${e.ville ? ` · ${e.ville}` : ""}`}
      />

      <div className="grid gap-6 lg:grid-cols-3">
        <div className="space-y-6 lg:col-span-2">
          <section className="rounded-lg border border-slate-200 p-4">
            <h2 className="mb-2 text-sm font-semibold text-slate-500 uppercase">L'événement</h2>
            <dl>
              <Ligne label="Quand">
                {quand(e)}
                {e.heure ? ` · ${e.heure}` : ""}
              </Ligne>
              <Ligne label="Où">
                {[e.lieu, e.ville, e.departement_code, e.region ? libelle(REGIONS, e.region) : null]
                  .filter(Boolean)
                  .join(" · ") || "—"}
              </Ligne>
              <Ligne label="Public">{e.public_vise ?? "—"}</Ligne>
              <Ligne label="Taille">{e.taille ?? "—"}</Ligne>
              <Ligne label="Prix">{e.prix ?? "—"}</Ligne>
              <Ligne label="Appel à intervenants">
                {libelle(APPELS, e.appel_intervenants)}
                {e.appel_intervenants_limite
                  ? ` · limite ${new Date(`${e.appel_intervenants_limite}T12:00:00`).toLocaleDateString("fr-FR")}`
                  : ""}
              </Ligne>
              <Ligne label="Page de l'événement">
                <Lien href={e.lien_evenement}>Ouvrir</Lien>
              </Ligne>
              <Ligne label="Inscription">
                <Lien href={e.lien_inscription}>S'inscrire</Lien>
              </Ligne>
              <Ligne label="Vérifié">
                {e.verifie ? "Oui" : "Non — à vérifier avant tout contact"}
              </Ligne>
              {e.notes ? <Ligne label="Notes du sourcing">{e.notes}</Ligne> : null}
            </dl>
          </section>

          <section className="rounded-lg border border-slate-200 p-4">
            <h2 className="mb-2 text-sm font-semibold text-slate-500 uppercase">Organisateurs</h2>
            {e.organisateurs.length === 0 ? (
              <p className="text-sm text-slate-500">Aucun organisateur relié.</p>
            ) : (
              <ul className="divide-y divide-slate-100">
                {e.organisateurs.map((o) => (
                  <li key={o.id} className="py-2 text-sm">
                    <Link
                      to="/companies/$companyId"
                      params={{ companyId: String(o.id) }}
                      className="font-medium text-sky-700 hover:underline"
                    >
                      {o.denomination ?? `Organisateur #${o.id}`}
                    </Link>
                    <span className="ml-2 text-slate-500">
                      {libelle(NATURES, o.entity_nature)}
                      {o.city ? ` · ${o.city}` : ""}
                    </span>
                    <div className="mt-1 flex gap-4 text-xs">
                      {o.website ? <Lien href={o.website}>Site</Lien> : null}
                      {o.contact_form_url ? (
                        <Lien href={o.contact_form_url}>Formulaire de contact</Lien>
                      ) : null}
                      <span className="text-slate-500">
                        Contacts : sur la fiche de l'organisateur
                      </span>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </div>

        <aside className="space-y-6">
          <section className="rounded-lg border border-slate-200 p-4">
            <h2 className="mb-3 text-sm font-semibold text-slate-500 uppercase">Ma démarche</h2>
            <div className="mb-3 flex gap-2">
              <Pastille ton="gris">{libelle(PARTICIPATIONS, e.participation)}</Pastille>
              <Pastille ton={tonIntervention(e.intervention)}>
                {libelle(INTERVENTIONS, e.intervention)}
              </Pastille>
            </div>
            {form && (
              <form
                className="space-y-3"
                onSubmit={(ev) => {
                  ev.preventDefault();
                  enregistrer.mutate(form);
                }}
              >
                <label className="flex flex-col gap-1 text-xs font-medium text-slate-600">
                  Ma participation
                  <select
                    value={form.participation}
                    onChange={(ev) => setForm({ ...form, participation: ev.target.value })}
                    className="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm"
                  >
                    {PARTICIPATIONS.map((o) => (
                      <option key={o.value} value={o.value}>
                        {o.label}
                      </option>
                    ))}
                  </select>
                </label>
                <label className="flex flex-col gap-1 text-xs font-medium text-slate-600">
                  Intervention
                  <select
                    value={form.intervention}
                    onChange={(ev) => setForm({ ...form, intervention: ev.target.value })}
                    className="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm"
                  >
                    {INTERVENTIONS.map((o) => (
                      <option key={o.value} value={o.value}>
                        {o.label}
                      </option>
                    ))}
                  </select>
                </label>
                <label className="flex flex-col gap-1 text-xs font-medium text-slate-600">
                  Prochaine relance
                  <input
                    type="date"
                    value={form.prochaine_relance_at ?? ""}
                    onChange={(ev) =>
                      setForm({ ...form, prochaine_relance_at: ev.target.value || null })
                    }
                    className="rounded-md border border-slate-300 px-2 py-1.5 text-sm"
                  />
                </label>
                <label className="flex flex-col gap-1 text-xs font-medium text-slate-600">
                  Note (jamais de coordonnées : elles vont sur la fiche contact)
                  <textarea
                    value={form.demarche_note ?? ""}
                    onChange={(ev) => setForm({ ...form, demarche_note: ev.target.value || null })}
                    rows={4}
                    maxLength={5000}
                    className="rounded-md border border-slate-300 px-2 py-1.5 text-sm"
                  />
                </label>
                <button
                  type="submit"
                  disabled={enregistrer.isPending}
                  className="w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white disabled:opacity-50"
                >
                  {enregistrer.isPending ? "Enregistrement…" : "Enregistrer"}
                </button>
              </form>
            )}
          </section>

          <section className="rounded-lg border border-slate-200 p-4">
            <h2 className="mb-2 text-sm font-semibold text-slate-500 uppercase">Historique</h2>
            {e.historique.length === 0 ? (
              <p className="text-sm text-slate-500">Aucune étape franchie pour l'instant.</p>
            ) : (
              <ol className="space-y-1.5 text-sm">
                {e.historique.map((h) => (
                  <li key={h.id} className="flex justify-between gap-2">
                    <span className="text-slate-800">{ETAPES[h.kind] ?? h.kind}</span>
                    <span className="text-slate-500">{jour(h.occurred_at)}</span>
                  </li>
                ))}
              </ol>
            )}
          </section>
        </aside>
      </div>
    </div>
  );
}
