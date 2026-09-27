/**
 * Libellés des événements professionnels — MÊMES vocabulaires fermés que le
 * serveur (`Taxonomy::EVENEMENT_*`). Une valeur inconnue s'affiche telle quelle
 * plutôt que de disparaître.
 */
export type Option = { value: string; label: string };

export const TYPES: Option[] = [
  { value: "salon", label: "Salon" },
  { value: "conference", label: "Conférence" },
  { value: "atelier", label: "Atelier" },
  { value: "club-affaires", label: "Club d'affaires" },
  { value: "reseau-entrepreneurs", label: "Réseau d'entrepreneurs" },
  { value: "afterwork", label: "Afterwork" },
  { value: "petit-dejeuner", label: "Petit-déjeuner" },
  { value: "table-ronde", label: "Table ronde" },
  { value: "pitch", label: "Pitch" },
  { value: "remise-prix", label: "Remise de prix" },
  { value: "festival", label: "Festival" },
  { value: "cine-debat", label: "Ciné-débat" },
  { value: "autre", label: "Autre" },
];

export const PARTICIPATIONS: Option[] = [
  { value: "repere", label: "Repéré" },
  { value: "inscrit", label: "Inscrit" },
  { value: "rencontre", label: "Rencontré" },
];

export const INTERVENTIONS: Option[] = [
  { value: "aucune", label: "Pas encore proposée" },
  { value: "proposee", label: "Proposée" },
  { value: "acceptee", label: "Acceptée" },
  { value: "refusee", label: "Refusée" },
  { value: "realisee", label: "Réalisée" },
];

export const APPELS: Option[] = [
  { value: "oui", label: "Oui" },
  { value: "non", label: "Non" },
  { value: "inconnu", label: "Inconnu" },
];

export const PERIODES: Option[] = [
  { value: "a_venir", label: "À venir" },
  { value: "passes", label: "Passés" },
  { value: "sans_date", label: "Récurrents / sans date" },
  { value: "", label: "Tous" },
];

/** Natures d'organisateur (companies.entity_nature). */
export const NATURES: Option[] = [
  { value: "", label: "Tous" },
  { value: "cci", label: "Chambres de commerce" },
  { value: "reseau", label: "Réseaux et clubs d'affaires" },
  { value: "association", label: "Associations" },
  { value: "entreprise", label: "Entreprises" },
  { value: "institution", label: "Institutions" },
  { value: "enseignement", label: "Enseignement" },
  { value: "media", label: "Médias" },
];

/** Libellés des étapes d'historique (activities.kind). */
export const ETAPES: Record<string, string> = {
  evenement_repere: "Événement repéré",
  evenement_inscrit: "Inscrit",
  evenement_rencontre: "Rencontré",
  intervention_proposee: "Intervention proposée",
  intervention_acceptee: "Intervention acceptée",
  intervention_refusee: "Intervention refusée",
  intervention_realisee: "Intervention réalisée",
};

export function libelle(options: Option[], value: string | null | undefined): string {
  if (!value) return "—";
  return options.find((o) => o.value === value)?.label ?? value;
}

/** « 15/10/2026 », « du 15 au 17/10/2026 », ou la récurrence. */
export function quand(e: {
  date_debut: string | null;
  date_fin: string | null;
  recurrence: string | null;
}): string {
  const fr = (d: string) => new Date(`${d}T12:00:00`).toLocaleDateString("fr-FR");
  if (!e.date_debut) return e.recurrence ? e.recurrence : "Sans date";
  if (e.date_fin && e.date_fin !== e.date_debut)
    return `du ${fr(e.date_debut)} au ${fr(e.date_fin)}`;
  return fr(e.date_debut);
}

/** Tag de provenance des organisateurs (FichesProtegees côté serveur). */
export const TAG_ORGANISATEURS = "src:scraping-evenements-pro";

export type Ton = "gris" | "vert" | "ambre" | "rouge" | "bleu";

export function tonIntervention(v: string): Ton {
  if (v === "acceptee" || v === "realisee") return "vert";
  if (v === "proposee") return "ambre";
  if (v === "refusee") return "rouge";
  return "gris";
}
