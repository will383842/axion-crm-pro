// Tranches d'effectif salarié INSEE (code `trancheEffectifsUniteLegale` → libellé lisible).
// Partagé entre la liste (filtre) et les lignes (affichage).

export const EFFECTIF_OPTIONS = [
  { value: "", label: "Tous effectifs" },
  { value: "00", label: "0 salarié" },
  { value: "01", label: "1 à 2 salariés" },
  { value: "02", label: "3 à 5 salariés" },
  { value: "03", label: "6 à 9 salariés" },
  { value: "11", label: "10 à 19 salariés" },
  { value: "12", label: "20 à 49 salariés" },
  { value: "21", label: "50 à 99 salariés" },
  { value: "22", label: "100 à 199 salariés" },
  { value: "31", label: "200 à 249 salariés" },
  { value: "32", label: "250 à 499 salariés" },
  { value: "41", label: "500 à 999 salariés" },
  { value: "42", label: "1 000 à 1 999 salariés" },
  { value: "51", label: "2 000 à 4 999 salariés" },
  { value: "52", label: "5 000 à 9 999 salariés" },
  { value: "53", label: "10 000 salariés et +" },
];

export const EFFECTIF_LABELS: Record<string, string> = Object.fromEntries(
  EFFECTIF_OPTIONS.filter((o) => o.value).map((o) => [o.value, o.label]),
);

/** Libellé lisible d'une tranche d'effectif INSEE (ex. "12" → "20 à 49 salariés"). */
export function effectifLabel(code?: string | null): string {
  if (!code) return "—";
  return EFFECTIF_LABELS[code] ?? "—";
}
