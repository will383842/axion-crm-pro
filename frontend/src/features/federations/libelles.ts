/**
 * Libellés des fédérations et organisations professionnelles (chantier 3,
 * 2026-09-29) — TOUS tirés du référentiel GÉNÉRÉ depuis le serveur
 * (`Taxonomy`, `crm:referentiels:generer-front`) : aucune liste recopiée à la
 * main. Une valeur inconnue s'affiche telle quelle plutôt que de disparaître.
 */
import {
  CERTITUDES,
  CONTACTABILITES,
  FAMILLES_FEDERATION,
  NIVEAUX_FEDERATION,
  PARTENARIATS as PARTENARIATS_REFERENTIEL,
  PERTINENCES,
  REGIONS,
  SECTEURS,
  TAILLES,
  type EntreeReferentiel,
} from "@/lib/referentiels.generated";

export type Option = { value: string; label: string };

function options(liste: readonly EntreeReferentiel[]): Option[] {
  return liste.map((e) => ({ value: e.code, label: e.libelle }));
}

export const FAMILLES = options(FAMILLES_FEDERATION);
export const NIVEAUX = options(NIVEAUX_FEDERATION);
export const CONTACTABILITE = options(CONTACTABILITES);
export const CERTITUDE = options(CERTITUDES);
export const PERTINENCE = options(PERTINENCES);
export const PARTENARIATS = options(PARTENARIATS_REFERENTIEL);
/** Secteurs qu'un organisme peut REPRÉSENTER : le référentiel, sans « Non classé ». */
export const SECTEURS_REPRESENTES = options(SECTEURS).filter((s) => s.value !== "non_classe");
export const TAILLES_ADHERENTS = options(TAILLES);
export const REGIONS_FR = options(REGIONS);

export const EVENEMENT_A_VENIR: Option[] = [
  { value: "1", label: "Avec un événement à venir" },
  { value: "0", label: "Sans événement à venir" },
];

/** Libellés des étapes d'historique du partenariat (activities.kind). */
export const ETAPES_PARTENARIAT: Record<string, string> = {
  partenariat_propose: "Partenariat proposé",
  partenariat_en_discussion: "Partenariat en discussion",
  partenariat_accepte: "Partenariat accepté",
  partenariat_refuse: "Partenariat refusé",
};

export function libelle(liste: Option[], value: string | null | undefined): string {
  if (!value) return "—";
  return liste.find((o) => o.value === value)?.label ?? value;
}

export function libelles(liste: Option[], valeurs: readonly string[]): string {
  return valeurs.length === 0 ? "—" : valeurs.map((v) => libelle(liste, v)).join(", ");
}

export type Ton = "gris" | "vert" | "ambre" | "rouge" | "bleu";

export function tonPertinence(v: string): Ton {
  if (v === "haute") return "vert";
  if (v === "moyenne") return "ambre";
  return "gris";
}

export function tonPartenariat(v: string): Ton {
  if (v === "accepte") return "vert";
  if (v === "propose" || v === "en_discussion") return "ambre";
  if (v === "refuse") return "rouge";
  return "gris";
}
