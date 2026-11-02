/** Mises en forme françaises des écrans de conformité (fuseau de Paris). */
import type { components } from '@/features/envoi/api/commun';

type Source = components['schemas']['SourceDesinscription'];

const DATE_COURTE = new Intl.DateTimeFormat('fr-FR', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'Europe/Paris' });
const HEURE = new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Paris' });

export function dateCourte(iso: string): string {
  return DATE_COURTE.format(new Date(iso));
}

export function heure(iso: string): string {
  return HEURE.format(new Date(iso));
}

export function dateEtHeure(iso: string): string {
  return `${dateCourte(iso)}, ${heure(iso)}`;
}

export const LIBELLE_SOURCE: Record<Source, string> = {
  lien: 'Lien de désinscription',
  reponse_stop: 'Réponse « STOP »',
  plainte: 'Plainte (signalé comme indésirable)',
  manuel: 'Demande RGPD',
};

export const LIBELLE_PORTEE = {
  adresse: 'Adresse',
  organisation: 'Toute l’organisation',
  domaine: 'Domaine entier',
} as const;

/** Adresse masquée : première lettre puis « *** » (page publique). */
export function masquerAdresse(adresse: string): string {
  const [local, domaine] = adresse.split('@');
  return `${(local ?? '').slice(0, 1)}***@${domaine ?? ''}`;
}
