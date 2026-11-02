/**
 * DM2-CAM — mots et formats des écrans de campagnes (tout en français, vouvoiement).
 * Les états gardent leurs pastilles de statut : la couleur du produit n'y entre jamais.
 */
import type { StatusTone } from '@/components/ui/StatusPill';
import type { EtatDestinataire, GroupeDestinataires, StatutCampagne } from '../api';

export const STATUT_LIBELLE: Record<StatutCampagne, string> = {
  brouillon: 'Brouillon',
  a_valider: 'À valider',
  programmee: 'Programmée',
  en_cours: 'En cours',
  en_pause: 'En pause',
  terminee: 'Terminée',
  arretee: 'Arrêtée',
};

export const STATUT_TON: Record<StatutCampagne, StatusTone> = {
  brouillon: 'neutral',
  a_valider: 'warning',
  programmee: 'info',
  en_cours: 'success',
  en_pause: 'warning',
  terminee: 'neutral',
  arretee: 'danger',
};

export const ETAT_LIBELLE: Record<EtatDestinataire, string> = {
  a_envoyer: 'En file',
  envoye: 'Parti',
  remis: 'Parti',
  rebond_mou: 'Refusé',
  rebond_dur: 'Refusé',
  a_repondu: 'A répondu',
  desinscrit: 'Désinscrit',
  plainte: 'Exclu',
  exclu: 'Exclu',
  relances_annulees: 'Arrêté pour l’entreprise',
};

export const ETAT_TON: Record<EtatDestinataire, StatusTone> = {
  a_envoyer: 'pending',
  envoye: 'info',
  remis: 'info',
  rebond_mou: 'warning',
  rebond_dur: 'danger',
  a_repondu: 'success',
  desinscrit: 'neutral',
  plainte: 'danger',
  exclu: 'neutral',
  relances_annulees: 'neutral',
};

export const GROUPE_LIBELLE: Record<GroupeDestinataires, string> = {
  en_file: 'En file',
  partis: 'Partis',
  refuses: 'Refusés',
  ont_repondu: 'Ont répondu',
  arretes: 'Arrêtés pour l’entreprise',
  exclus: 'Exclus',
};

const MOTIF_OUVERTURE: Record<string, string> = {
  protection_apple: 'protection de la vie privée d’Apple',
  relais_images: 'relais d’images',
  antivirus: 'antivirus',
  ouverture_immediate: 'ouverture dans la seconde',
};

export function libelleMotifAutomatique(raison: string | null): string {
  return raison !== null && MOTIF_OUVERTURE[raison] !== undefined ? MOTIF_OUVERTURE[raison] : 'relais automatique';
}

const FUSEAU = 'Europe/Paris';

/** « 12,5 % » : pourcentage à la française. */
export function pourcentage(valeur: number, decimales = 1): string {
  return `${valeur.toLocaleString('fr-FR', { minimumFractionDigits: 0, maximumFractionDigits: decimales })} %`;
}

export function nombre(n: number): string {
  return n.toLocaleString('fr-FR');
}

const heure = new Intl.DateTimeFormat('fr-FR', { timeZone: FUSEAU, hour: '2-digit', minute: '2-digit' });
const jourCourt = new Intl.DateTimeFormat('fr-FR', { timeZone: FUSEAU, weekday: 'short', day: 'numeric', month: 'short' });
const jourDate = new Intl.DateTimeFormat('en-CA', { timeZone: FUSEAU, year: 'numeric', month: '2-digit', day: '2-digit' });
const jourMois = new Intl.DateTimeFormat('fr-FR', { timeZone: FUSEAU, day: 'numeric', month: 'short' });

/** Jour AAAA-MM-JJ d'un instant, à l'heure de Paris. */
export function jourDe(iso: string): string {
  return jourDate.format(new Date(iso));
}

export function majuscule(texte: string): string {
  return texte.charAt(0).toUpperCase() + texte.slice(1);
}

/** « Aujourd’hui 14:00 », « Demain 09:00 » ou « Mer. 13 janv. 09:00 » (par rapport au `maintenant` du serveur). */
export function dateRelative(iso: string, maintenant: string): string {
  const jour = jourDe(iso);
  const auj = jourDe(maintenant);
  const demain = jourDate.format(new Date(new Date(`${auj}T12:00:00.000Z`).getTime() + 86_400_000));
  const h = heure.format(new Date(iso));
  if (jour === auj) return `Aujourd’hui ${h}`;
  if (jour === demain) return `Demain ${h}`;
  return `${majuscule(jourCourt.format(new Date(iso)))} ${h}`;
}

export function jourEtMois(iso: string): string {
  return jourMois.format(new Date(iso));
}

export function heureDe(iso: string): string {
  return heure.format(new Date(iso));
}

// ── Heure de Paris ⇄ instant ISO ──────────────────────────────────────────

const PARTIES = new Intl.DateTimeFormat('en-US', {
  timeZone: FUSEAU,
  hourCycle: 'h23',
  year: 'numeric',
  month: 'numeric',
  day: 'numeric',
  hour: 'numeric',
  minute: 'numeric',
  second: 'numeric',
});
const SEMAINE = new Intl.DateTimeFormat('en-US', { timeZone: FUSEAU, weekday: 'short' });

/** Écart de Paris avec UTC à cet instant, en minutes (60 l'hiver, 120 l'été). */
function ecartParis(t: number): number {
  const p = Object.fromEntries(PARTIES.formatToParts(new Date(t)).map((x) => [x.type, Number(x.value)])) as Record<string, number>;
  return Math.round((Date.UTC(p['year']!, p['month']! - 1, p['day'], p['hour'], p['minute'], p['second']) - t) / 60_000);
}

/** Instant ISO (UTC) d'un jour AAAA-MM-JJ et d'une heure « hh:mm » lus à Paris. */
export function parisVersIso(jour: string, hhmm: string): string {
  const [a, mo, j] = jour.split('-').map(Number) as [number, number, number];
  const [h, m] = hhmm.split(':').map(Number) as [number, number];
  const local = Date.UTC(a, mo - 1, j, h, m);
  let t = local - ecartParis(local) * 60_000;
  t = local - ecartParis(t) * 60_000;
  return new Date(t).toISOString();
}

export function ajouterJours(jour: string, n: number): string {
  const d = new Date(`${jour}T12:00:00.000Z`);
  d.setUTCDate(d.getUTCDate() + n);
  return d.toISOString().slice(0, 10);
}

/** Prochain lundi, strictement après le jour donné. */
export function prochainLundi(jour: string): string {
  let j = ajouterJours(jour, 1);
  for (let garde = 0; garde < 8 && SEMAINE.format(new Date(`${j}T12:00:00.000Z`)) !== 'Mon'; garde += 1) j = ajouterJours(j, 1);
  return j;
}
