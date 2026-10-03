/**
 * « SANTÉ DU SYSTÈME » EN CLAIR (constaté en production le 03/10/2026).
 *
 * L'écran affichait les codes bruts du serveur : motifs d'archivage
 * (« ENTREPRISE_RADIEE », « NO_EMAIL »), actions du journal métier
 * (« audience.refreshed », « company.tags_synced ») et éléments concernés
 * (« company #5507510 »). On les traduit ici.
 *
 * Les actions réutilisent le dictionnaire de « Activité récente »
 * (`features/dashboard/activite.ts`) : une seule traduction des événements
 * pour toute la console. Un code inconnu ne s'affiche jamais tel quel : il
 * retombe sur un libellé neutre.
 */
import { AUTRE_OPERATION, libelleActivite } from '@/features/dashboard/activite';

/** Libellé d'un motif d'archivage inconnu. */
export const AUTRE_RAISON = 'Autre raison';

/**
 * Valeurs possibles de `companies.archive_reason` : contrainte
 * `companies_archive_reason_check` (migration du 18/05/2026).
 */
export const MOTIFS_ARCHIVAGE: Readonly<Record<string, string>> = {
  entreprise_radiee: 'Entreprise radiée',
  no_email: 'Sans e-mail',
  low_quality_score: 'Score de qualité trop bas',
  duplicate: 'Doublon',
  manual: 'Archivée à la main',
};

export function libelleMotifArchivage(motif: string | null | undefined): string {
  const cle = typeof motif === 'string' ? motif.trim().toLowerCase() : '';
  // `Object.hasOwn` : « constructor » ou « __proto__ » rendraient sinon une
  // propriété héritée du prototype (une fonction, que React refuse d'afficher).
  return Object.hasOwn(MOTIFS_ARCHIVAGE, cle) ? (MOTIFS_ARCHIVAGE[cle] ?? AUTRE_RAISON) : AUTRE_RAISON;
}

/** La phrase d'une action du journal métier (`business_events.action`). */
export function libelleEvenementMetier(action: string | null | undefined): string {
  return libelleActivite(action, null) ?? AUTRE_OPERATION;
}

/** Route de la fiche d'un élément, quand elle existe. */
export type LienElement =
  | { to: '/companies/$companyId'; params: { companyId: string } }
  | { to: '/audiences/$audienceId'; params: { audienceId: string } }
  | { to: '/listes/$listeId'; params: { listeId: string } };

export interface ElementConcerne {
  texte: string;
  lien: LienElement | null;
}

/** Noms des types d'éléments écrits dans `business_events.resource_type`. */
const TYPES: Readonly<Record<string, string>> = {
  company: 'Entreprise',
  audience: 'Audience',
  liste_manuelle: 'Liste',
};

/**
 * L'élément concerné par un événement, en clair : « company » + « 5507510 »
 * → « Entreprise n° 5507510 », avec le lien vers sa fiche. `null` quand
 * l'événement ne porte sur aucun élément.
 */
export function elementConcerne(type: string | null | undefined, id: string | null | undefined): ElementConcerne | null {
  const t = typeof type === 'string' ? type.trim() : '';
  if (t === '') return null;
  const ident = typeof id === 'string' && id.trim() !== '' ? id.trim() : null;

  // `email.verified` : l'identifiant EST l'adresse vérifiée.
  if (t === 'email') {
    return { texte: ident ? `Adresse e-mail ${ident}` : 'Adresse e-mail', lien: null };
  }

  const nom = Object.hasOwn(TYPES, t) ? (TYPES[t] ?? 'Autre élément') : 'Autre élément';
  const texte = ident ? `${nom} n° ${ident}` : nom;
  if (ident === null || !/^\d+$/.test(ident)) return { texte, lien: null };

  if (t === 'company') return { texte, lien: { to: '/companies/$companyId', params: { companyId: ident } } };
  if (t === 'audience') return { texte, lien: { to: '/audiences/$audienceId', params: { audienceId: ident } } };
  if (t === 'liste_manuelle') return { texte, lien: { to: '/listes/$listeId', params: { listeId: ident } } };
  return { texte, lien: null };
}
