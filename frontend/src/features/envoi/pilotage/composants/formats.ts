/** Formats français des écrans de pilotage (heure de Paris, nombres à la française). */
const nombre = new Intl.NumberFormat('fr-FR');
const pct = new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 0, maximumFractionDigits: 1 });
const dateHeure = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Paris' });
const heure = new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Paris' });

export const formaterNombre = (n: number): string => nombre.format(n);
export const formaterPourcent = (n: number): string => `${pct.format(n)} %`;
/** Part en pourcentage, « 0 % » si le dénominateur est nul. */
export const part = (a: number, b: number): number => (b === 0 ? 0 : (a / b) * 100);
export const formaterDateHeure = (iso: string): string => dateHeure.format(new Date(iso)).replace(',', '');
export const formaterHeure = (iso: string): string => heure.format(new Date(iso));
export const minuscule = (t: string): string => (t.length === 0 ? t : t.charAt(0).toLowerCase() + t.slice(1));

/** Échappe une cellule CSV (guillemets, séparateurs, formules de tableur). */
export function celluleCsv(v: string | number): string {
  const s = String(v);
  const sur = /^[=+\-@]/.test(s) ? `'${s}` : s;
  return /[;"\n]/.test(sur) ? `"${sur.replace(/"/g, '""')}"` : sur;
}

/** Télécharge un CSV (séparateur « ; », ouvert directement par Excel en français). Sans effet hors navigateur. */
export function telechargerCsv(nomFichier: string, lignes: ReadonlyArray<ReadonlyArray<string | number>>): void {
  if (typeof URL.createObjectURL !== 'function') return;
  const corps = `\uFEFF${lignes.map((l) => l.map(celluleCsv).join(';')).join('\r\n')}`;
  const url = URL.createObjectURL(new Blob([corps], { type: 'text/csv;charset=utf-8' }));
  const a = document.createElement('a');
  a.href = url;
  a.download = nomFichier;
  a.click();
  URL.revokeObjectURL(url);
}
