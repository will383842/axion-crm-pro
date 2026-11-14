/** DM2-INF — mises en forme françaises partagées par les pages d'infrastructure. */
import { isAxiosError } from 'axios';
import type { EtatFeu, PolitiqueDmarc, Boite } from './api';

const FUSEAU = 'Europe/Paris';

/** « 26 janv. » */
export function dateCourte(iso: string): string {
  const d = /^\d{4}-\d{2}-\d{2}$/.test(iso) ? new Date(`${iso}T12:00:00Z`) : new Date(iso);
  return new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short', timeZone: FUSEAU }).format(d);
}

/** « 12 janv. 09:30 » */
export function dateHeure(iso: string): string {
  const d = new Date(iso);
  const jour = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short', timeZone: FUSEAU }).format(d);
  const heure = new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit', timeZone: FUSEAU }).format(d);
  return `${jour} ${heure}`;
}

export function nombre(n: number): string {
  return new Intl.NumberFormat('fr-FR').format(n);
}

export function pourcent(n: number): string {
  return `${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 }).format(n)} %`;
}

export const LIBELLE_FEU: Record<EtatFeu, { mot: string; ton: 'success' | 'warning' | 'danger' | 'info' | 'neutral' }> = {
  vert: { mot: 'Bon', ton: 'success' },
  ambre: { mot: 'Attention', ton: 'warning' },
  rouge: { mot: 'Arrêté', ton: 'danger' },
  bleu: { mot: 'En montée', ton: 'info' },
  gris: { mot: 'Inconnu', ton: 'neutral' },
};

export const LIBELLE_STATUT: Record<Boite['statut'], string> = {
  prete: 'Prête',
  ralentie: 'Ralentie',
  en_chauffe: 'En chauffe',
  suspendue: 'Suspendue',
  inconnu: 'État inconnu',
};

export const LIBELLE_DMARC: Record<PolitiqueDmarc, string> = {
  none: 'aucune',
  quarantine: 'quarantaine',
  reject: 'rejet',
};

/** « passer à quarantaine le 26 janv. » */
export function phraseDmarc(prochaine: { politique: PolitiqueDmarc; le: string } | null): string {
  if (!prochaine) return 'DMARC au plus strict';
  return `passer à ${LIBELLE_DMARC[prochaine.politique]} le ${dateCourte(prochaine.le)}`;
}

/** Le message français d'une erreur de l'API (corps `Erreur`), sinon un texte de repli. */
export function messageErreur(erreur: unknown, repli = 'Le geste n’a pas pu être fait. Rien n’a été modifié. Réessayez dans un instant.'): string {
  if (isAxiosError(erreur)) {
    const message = (erreur.response?.data as { message?: unknown } | undefined)?.message;
    if (typeof message === 'string' && message !== '') return message;
  }
  return repli;
}
