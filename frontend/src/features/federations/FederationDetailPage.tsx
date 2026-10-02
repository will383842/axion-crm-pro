/**
 * FICHE D'UNE FÉDÉRATION (chantier 3, 2026-09-29) — son classement,
 * l'arborescence (têtes de réseau au-dessus, antennes en dessous), ses
 * contacts, ses événements, et la démarche « partenariat » de Will avec son
 * historique daté.
 *
 * Les coordonnées arrivent déjà MASQUÉES par le serveur pour un compte sans
 * droit de les voir ; la note de démarche, elle, n'arrive pas du tout.
 */
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link, useParams } from "@tanstack/react-router";
import { useEffect, useState, type ReactNode } from "react";
import { toast } from "sonner";

import { PageHeader } from "@/components/ui/PageHeader";
import { Pastille } from "@/features/evenements/Pastille";
import {
  INTERVENTIONS,
  PARTICIPATIONS,
  jour,
  lienSur,
  quand,
  tonIntervention,
} from "@/features/evenements/libelles";
import { api } from "@/lib/api";

import type { FederationResume } from "./FederationsPage";
import {
  CERTITUDE,
  CONTACTABILITE,
  ETAPES_PARTENARIAT,
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
} from "./libelles";

type Noeud = { id: number; denomination: string | null; niveau: string | null };

type Fiche = FederationResume & {
  siren: string | null;
  /** Organisme sans SIREN (section, conseil départemental d'ordre) : sa clé d'import. */
  identifiant: string | null;
  naf: string | null;
  legal_form: string | null;
  effectif_range: string | null;
  address: string | null;
  postcode: string | null;
  sector_main: string | null;
  website: string | null;
  linkedin_url: string | null;
  phone: string | null;
  email_generic: string | null;
  contact_form_url: string | null;
  nom_developpe: string | null;
  date_creation: string | null;
  nb_etablissements: number | null;
  origine_classement: string | null;
  partenariat_note: string | null;
  email_generic_verification: { type: string | null; verifie_le: string | null } | null;
  canaux: {
    emails: { email: string; type: string | null; domaine_verifie: boolean | null; verifie_le: string | null }[];
    telephones: { phone: string }[];
    sites: string[];
    linkedin: string[];
  };
  ascendants: Noeud[];
  antennes: (Noeud & { city: string | null; department_code: string | null })[];
  contacts: {
    id: number;
    first_name: string | null;
    last_name: string;
    role: string | null;
    email: string | null;
    phone: string | null;
    linkedin_url: string | null;
    informe: boolean;
  }[];
  evenements: {
    id: number;
    nom: string;
    type: string;
    date_debut: string | null;
    date_fin: string | null;
    recurrence: string | null;
    ville: string | null;
    participation: string;
    intervention: string;
  }[];
  historique: { id: number; kind: string; occurred_at: string }[];
};

type Demarche = {
  partenariat: string;
  partenariat_relance_at: string | null;
  partenariat_note: string | null;
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
    <a href={sur} target="_blank" rel="noreferrer noopener" className="text-sky-700 hover:underline">
      {children}
    </a>
  );
}

function VersFiche({ noeud }: { noeud: { id: number; denomination: string | null } }) {
  return (
    <Link
      to="/federations/$companyId"
      params={{ companyId: String(noeud.id) }}
      className="text-sky-700 hover:underline"
    >
      {noeud.denomination ?? `Organisme #${noeud.id}`}
    </Link>
  );
}

export function FederationDetailPage() {
  const { companyId } = useParams({ strict: false });
  const qc = useQueryClient();

  const { data: f, isLoading, isError } = useQuery({
    queryKey: ["federation", companyId],
    enabled: Boolean(companyId),
    queryFn: async () => (await api.get<Fiche>(`/federations/${companyId}`)).data,
  });

  const [form, setForm] = useState<Demarche | null>(null);
  useEffect(() => {
    if (f) {
      setForm({
        partenariat: f.partenariat,
        partenariat_relance_at: f.partenariat_relance_at ? f.partenariat_relance_at.slice(0, 10) : null,
        partenariat_note: f.partenariat_note,
      });
    }
  }, [f]);

  const enregistrer = useMutation({
    mutationFn: async (d: Demarche) => {
      // La note n'est envoyée QUE si elle a été modifiée : un compte qui ne voit
      // pas les coordonnées la reçoit vide, et la renverrait vide — il
      // effacerait une note qu'il n'a jamais lue (le serveur l'ignore aussi).
      const corps: Partial<Demarche> = {
        partenariat: d.partenariat,
        partenariat_relance_at: d.partenariat_relance_at,
      };
      if (f && d.partenariat_note !== f.partenariat_note) corps.partenariat_note = d.partenariat_note;
      return (await api.patch<Fiche>(`/federations/${companyId}/demarche`, corps)).data;
    },
    onSuccess: (fiche) => {
      qc.setQueryData(["federation", companyId], fiche);
      void qc.invalidateQueries({ queryKey: ["federations"] });
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
  if (isError || !f) return <p className="text-sm text-red-600">Organisme introuvable.</p>;

  // La racine d'abord : « FFB › FFB Auvergne-Rhône-Alpes › (cette fiche) ».
  const chaine = [...f.ascendants].reverse();

  return (
    <div>
      <p className="mb-2 text-sm">
        <Link to="/federations" className="text-sky-700 hover:underline">
          ← Toutes les fédérations
        </Link>
      </p>
      <PageHeader
        title={f.denomination ?? `Organisme #${f.id}`}
        subtitle={`${libelle(FAMILLES, f.famille)} · ${libelle(NIVEAUX, f.niveau)}${f.city ? ` · ${f.city}` : ""}`}
      />

      <div className="grid gap-6 lg:grid-cols-3">
        <div className="space-y-6 lg:col-span-2">
          <section aria-labelledby="fed-classement" className="rounded-lg border border-slate-200 p-4">
            <h2 id="fed-classement" className="mb-2 text-sm font-semibold text-slate-500 uppercase">
              L'organisme
            </h2>
            <dl>
              {f.sigle ? <Ligne label="Sigle">{f.sigle}</Ligne> : null}
              {f.nom_developpe ? <Ligne label="Nom développé">{f.nom_developpe}</Ligne> : null}
              <Ligne label="Famille">{libelle(FAMILLES, f.famille)}</Ligne>
              <Ligne label="Niveau">{libelle(NIVEAUX, f.niveau)}</Ligne>
              <Ligne label="Secteurs représentés">{libelles(SECTEURS_REPRESENTES, f.secteurs)}</Ligne>
              <Ligne label="Taille des adhérents">{libelles(TAILLES_ADHERENTS, f.tailles_adherents)}</Ligne>
              <Ligne label="Pertinence">
                <Pastille ton={tonPertinence(f.pertinence)}>{libelle(PERTINENCE, f.pertinence)}</Pastille>
              </Ligne>
              <Ligne label="Certitude du classement">{libelle(CERTITUDE, f.certitude)}</Ligne>
              <Ligne label="Contactabilité">{libelle(CONTACTABILITE, f.contactabilite)}</Ligne>
              <Ligne label="Où">
                {[f.address, f.department_code, f.region_code ? libelle(REGIONS_FR, f.region_code) : null]
                  .filter(Boolean)
                  .join(" · ") || "—"}
              </Ligne>
              {f.siren === null && f.identifiant ? (
                <Ligne label="Identifiant (sans SIREN)">{f.identifiant}</Ligne>
              ) : (
                <Ligne label="SIREN">{f.siren ?? "—"}</Ligne>
              )}
              <Ligne label="Création">{jour(f.date_creation)}</Ligne>
              <Ligne label="Site">
                <Lien href={f.website}>Ouvrir</Lien>
              </Ligne>
              <Ligne label="LinkedIn">
                <Lien href={f.linkedin_url}>Ouvrir</Lien>
              </Ligne>
              <Ligne label="Formulaire">
                <Lien href={f.contact_form_url}>Ouvrir</Lien>
              </Ligne>
              <Ligne label="E-mail générique">
                {f.email_generic ?? "—"}
                {f.email_generic && f.email_generic_verification?.verifie_le ? (
                  <span className="ml-2 text-xs text-slate-500">
                    domaine vérifié le {jour(f.email_generic_verification.verifie_le)}
                  </span>
                ) : null}
              </Ligne>
              <Ligne label="Téléphone">{f.phone ?? "—"}</Ligne>
            </dl>
            <p className="mt-2 text-sm">
              <Link
                to="/companies/$companyId"
                params={{ companyId: String(f.id) }}
                className="text-sky-700 hover:underline"
              >
                Voir la fiche entreprise complète
              </Link>
            </p>
          </section>

          {f.canaux.emails.length + f.canaux.telephones.length + f.canaux.sites.length + f.canaux.linkedin.length > 0 ? (
            <section aria-labelledby="fed-canaux" className="rounded-lg border border-slate-200 p-4">
              <h2 id="fed-canaux" className="mb-2 text-sm font-semibold text-slate-500 uppercase">
                Autres coordonnées
              </h2>
              <ul className="space-y-1 text-sm">
                {f.canaux.emails.map((e) => (
                  <li key={`e-${e.email}`}>
                    {e.email}
                    <span className="ml-2 text-xs text-slate-500">
                      {e.type === "nominatif" ? "nominative" : e.type === "generique" ? "générique" : "type inconnu"}
                      {e.verifie_le ? ` · domaine vérifié le ${jour(e.verifie_le)}` : ""}
                    </span>
                  </li>
                ))}
                {f.canaux.telephones.map((t) => (
                  <li key={`t-${t.phone}`}>{t.phone}</li>
                ))}
                {f.canaux.sites.map((u) => (
                  <li key={`s-${u}`}>
                    <Lien href={u}>{u}</Lien>
                  </li>
                ))}
                {f.canaux.linkedin.map((u) => (
                  <li key={`l-${u}`}>
                    <Lien href={u}>{u}</Lien>
                  </li>
                ))}
              </ul>
            </section>
          ) : null}

          <section aria-labelledby="fed-arbre" className="rounded-lg border border-slate-200 p-4">
            <h2 id="fed-arbre" className="mb-2 text-sm font-semibold text-slate-500 uppercase">
              Réseau
            </h2>
            <nav aria-label="Têtes de réseau" className="mb-3 text-sm">
              {chaine.length === 0 ? (
                <span className="text-slate-500">Pas de tête de réseau connue.</span>
              ) : (
                <ol className="flex flex-wrap items-center gap-1">
                  {chaine.map((n) => (
                    <li key={n.id} className="flex items-center gap-1">
                      <VersFiche noeud={n} />
                      <span aria-hidden="true">›</span>
                    </li>
                  ))}
                  <li className="font-medium text-slate-800">{f.denomination}</li>
                </ol>
              )}
            </nav>
            <h3 className="mb-1 text-xs font-semibold text-slate-500 uppercase">
              Antennes ({f.nb_antennes})
            </h3>
            {f.antennes.length === 0 ? (
              <p className="text-sm text-slate-500">Aucune antenne rattachée.</p>
            ) : (
              <ul className="divide-y divide-slate-100 text-sm">
                {f.antennes.map((a) => (
                  <li key={a.id} className="py-1.5">
                    <VersFiche noeud={a} />
                    <span className="ml-2 text-slate-500">
                      {libelle(NIVEAUX, a.niveau)}
                      {a.city ? ` · ${a.city}` : ""}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </section>

          <section aria-labelledby="fed-contacts" className="rounded-lg border border-slate-200 p-4">
            <h2 id="fed-contacts" className="mb-2 text-sm font-semibold text-slate-500 uppercase">
              Contacts
            </h2>
            {f.contacts.length === 0 ? (
              <p className="text-sm text-slate-500">Aucune personne connue.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="min-w-full text-sm">
                  <thead className="text-left text-xs text-slate-500 uppercase">
                    <tr>
                      <th className="py-1 pr-3">Nom</th>
                      <th className="py-1 pr-3">Fonction</th>
                      <th className="py-1 pr-3">E-mail</th>
                      <th className="py-1 pr-3">LinkedIn</th>
                      <th className="py-1 pr-3">Informé (art. 14)</th>
                    </tr>
                  </thead>
                  <tbody>
                    {f.contacts.map((c) => (
                      <tr key={c.id} className="border-t border-slate-100">
                        <td className="py-1.5 pr-3">{[c.first_name, c.last_name].filter(Boolean).join(" ")}</td>
                        <td className="py-1.5 pr-3 text-slate-600">{c.role ?? "—"}</td>
                        <td className="py-1.5 pr-3 text-slate-600">{c.email ?? "—"}</td>
                        <td className="py-1.5 pr-3">
                          <Lien href={c.linkedin_url}>Profil</Lien>
                        </td>
                        <td className="py-1.5 pr-3 text-slate-600">
                          {c.informe ? "Oui" : "Non — mention au premier message"}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>

          <section aria-labelledby="fed-evenements" className="rounded-lg border border-slate-200 p-4">
            <h2 id="fed-evenements" className="mb-2 text-sm font-semibold text-slate-500 uppercase">
              Événements
            </h2>
            {f.evenements.length === 0 ? (
              <p className="text-sm text-slate-500">Aucun événement relié.</p>
            ) : (
              <ul className="divide-y divide-slate-100">
                {f.evenements.map((e) => (
                  <li key={e.id} className="py-2 text-sm">
                    <Link
                      to="/evenements/$eventId"
                      params={{ eventId: String(e.id) }}
                      className="font-medium text-sky-700 hover:underline"
                    >
                      {e.nom}
                    </Link>
                    <div className="text-xs text-slate-500">
                      {quand(e)}
                      {e.ville ? ` · ${e.ville}` : ""}
                    </div>
                    <div className="mt-1 flex flex-wrap gap-1">
                      <Pastille ton="gris">{libelle(PARTICIPATIONS, e.participation)}</Pastille>
                      <Pastille ton={tonIntervention(e.intervention)}>
                        {libelle(INTERVENTIONS, e.intervention)}
                      </Pastille>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </div>

        <aside className="space-y-6">
          <section aria-labelledby="fed-demarche" className="rounded-lg border border-slate-200 p-4">
            <h2 id="fed-demarche" className="mb-3 text-sm font-semibold text-slate-500 uppercase">
              Partenariat
            </h2>
            <div className="mb-3">
              <Pastille ton={tonPartenariat(f.partenariat)}>{libelle(PARTENARIATS, f.partenariat)}</Pastille>
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
                  Étape
                  <select
                    value={form.partenariat}
                    onChange={(ev) => setForm({ ...form, partenariat: ev.target.value })}
                    className="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm"
                  >
                    {PARTENARIATS.map((o) => (
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
                    value={form.partenariat_relance_at ?? ""}
                    onChange={(ev) => setForm({ ...form, partenariat_relance_at: ev.target.value || null })}
                    className="rounded-md border border-slate-300 px-2 py-1.5 text-sm"
                  />
                </label>
                <label className="flex flex-col gap-1 text-xs font-medium text-slate-600">
                  Note (jamais de coordonnées : elles vont sur la fiche contact)
                  <textarea
                    value={form.partenariat_note ?? ""}
                    onChange={(ev) => setForm({ ...form, partenariat_note: ev.target.value || null })}
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

          <section aria-labelledby="fed-historique" className="rounded-lg border border-slate-200 p-4">
            <h2 id="fed-historique" className="mb-2 text-sm font-semibold text-slate-500 uppercase">
              Historique
            </h2>
            {f.historique.length === 0 ? (
              <p className="text-sm text-slate-500">Aucune étape franchie pour l'instant.</p>
            ) : (
              <ol className="space-y-1.5 text-sm">
                {f.historique.map((h) => (
                  <li key={h.id} className="flex justify-between gap-2">
                    <span className="text-slate-800">{ETAPES_PARTENARIAT[h.kind] ?? h.kind}</span>
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
