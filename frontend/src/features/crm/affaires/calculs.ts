import { estOuverte, type Affaire, type EtapeAffaire } from './etapes';

const EURO = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 });

/** « 28 100 € » ; « — » quand le montant n'est pas estimé. */
export function euros(montant: number | null): string {
  return montant === null ? '—' : EURO.format(montant);
}

export function totalColonne(affaires: readonly Affaire[], etape: EtapeAffaire): number {
  return affaires.filter((a) => a.etape === etape).reduce((s, a) => s + (a.montant ?? 0), 0);
}

export interface ResumeAffaires {
  ouvertes: number;
  enJeu: number;
  pondere: number;
  signatureCeMois: number;
  gagneesTrimestre: number;
}

/** Le premier jour du trimestre de `date` (AAAA-MM-JJ). */
function debutTrimestre(date: string): string {
  const mois = Number(date.slice(5, 7));
  const premier = Math.floor((mois - 1) / 3) * 3 + 1;
  return `${date.slice(0, 4)}-${String(premier).padStart(2, '0')}-01`;
}

export function resumer(affaires: readonly Affaire[], aujourdhui: string): ResumeAffaires {
  const ouvertes = affaires.filter((a) => estOuverte(a.etape));
  const mois = aujourdhui.slice(0, 7);
  const debut = debutTrimestre(aujourdhui);
  return {
    ouvertes: ouvertes.length,
    enJeu: ouvertes.reduce((s, a) => s + (a.montant ?? 0), 0),
    pondere: Math.round(ouvertes.reduce((s, a) => s + ((a.montant ?? 0) * a.chance) / 100, 0)),
    signatureCeMois: ouvertes.filter((a) => a.signature_prevue?.startsWith(mois) === true).length,
    gagneesTrimestre: affaires.filter((a) => a.etape === 'gagne' && a.signature_prevue !== null && a.signature_prevue >= debut && a.signature_prevue <= aujourdhui).length,
  };
}

const MOIS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'] as const;

/** « 22 janv. 2027 » depuis AAAA-MM-JJ, sans fuseau. */
export function dateLongue(date: string): string {
  return `${Number(date.slice(8, 10))} ${MOIS[Number(date.slice(5, 7)) - 1] ?? ''} ${date.slice(0, 4)}`;
}
