/**
 * Filtres de l'écran Entreprises, et leur passage par l'adresse de la page.
 *
 * Audit UX du 2026-10-02 (P1-4) : le lien du tableau de bord
 * `/companies?quality_badge=basique` menait à la liste NON filtrée, parce que
 * l'écran ne lisait pas l'adresse. Les filtres vivent désormais dans l'URL :
 * un lien arrive filtré, un rechargement les conserve, un lien se partage.
 *
 * 🔴 On ne fait JAMAIS confiance à l'URL : n'importe qui peut la taper ou la
 * recevoir. Chaque paramètre est donc vérifié ici :
 *   - les listes n'acceptent que leurs valeurs connues ;
 *   - les codes (département, région, NAF, dates) suivent leur forme ;
 *   - les textes libres sont bornés en longueur et débarrassés des caractères
 *     de contrôle.
 * Une valeur refusée est simplement ignorée : le filtre reste « tous ».
 */

import {
  CONFIANCE_EMAIL_OPTIONS,
  COUNTRY_OPTIONS,
  ELIGIBILITE_OPTIONS,
  JOIGNABILITE_OPTIONS,
  NATURE_OPTIONS,
  SECTEUR_OPTIONS,
  TAILLE_OPTIONS,
  type OptionReferentiel,
} from '@/lib/prospection-referentiels';
import { EFFECTIF_OPTIONS } from './effectif';

export const QUALITY_OPTIONS: OptionReferentiel[] = [
  { value: '', label: 'Toutes les fiches' },
  { value: 'complete', label: 'Complète (90 et plus)' },
  { value: 'partielle', label: 'Partielle (50 à 89)' },
  { value: 'basique', label: 'Basique (moins de 50)' },
];

export const PRIORITY_OPTIONS: OptionReferentiel[] = [
  { value: '', label: 'Toutes priorités' },
  { value: 'haute', label: 'Haute' },
  { value: 'moyenne', label: 'Moyenne' },
  { value: 'basse', label: 'Basse' },
  { value: 'gelee', label: 'Gelée' },
];

export const PROSPECTION_TABS: OptionReferentiel[] = [
  { value: '', label: 'Tous' },
  { value: 'ready_for_outreach', label: 'Prospectables' },
  { value: 'partial_email', label: 'Partiels' },
  { value: 'pending', label: 'À compléter' },
  { value: 'archived_no_email', label: 'Archivés' },
];

export interface Filter {
  size: string;
  effectif: string;
  priority: string;
  search: string;
  naf: string;
  quality: string;
  prospection_status: string;
  department_code: string;
  region_code: string;
  sector_main: string;
  country_code: string;
  best_email_confidence: string;
  eligible_campagne: string;
  entity_nature: string;
  joignabilite: string;
  tag: string;
  cree_apres: string;
  cree_avant: string;
}

export const EMPTY_FILTER: Filter = {
  size: '',
  effectif: '',
  priority: '',
  search: '',
  naf: '',
  quality: '',
  prospection_status: '',
  department_code: '',
  region_code: '',
  sector_main: '',
  country_code: '',
  best_email_confidence: '',
  eligible_campagne: '',
  entity_nature: '',
  joignabilite: '',
  tag: '',
  cree_apres: '',
  cree_avant: '',
};

/** Ce que l'adresse porte : seulement les filtres renseignés. */
export type RechercheEntreprises = Partial<Filter>;

/** Longueurs maximales des saisies libres (le champ les applique aussi). */
export const LONGUEUR_MAX_RECHERCHE = 120;
export const LONGUEUR_MAX_ETIQUETTE = 64;
export const LONGUEUR_MAX_NAF = 10;

/**
 * Un code NAF tel que le serveur le compare (`filter[naf]`, égalité stricte
 * sur `companies.naf`) : sans espaces, en majuscules. « 68 31z » devient
 * « 6831Z ». Le point n'est NI retiré NI ajouté : la base stocke le code tel
 * que l'INSEE le fournit (« 68.31Z »), et le deviner changerait la recherche.
 * Appliquée À LA FOIS à la requête et à l'adresse, pour qu'elles concordent.
 */
export function normaliserNaf(valeur: string): string {
  return valeur.replace(/\s+/g, '').toUpperCase();
}

type Regle = (valeur: string) => boolean;

function parmi(options: readonly OptionReferentiel[]): Regle {
  const autorisees = new Set(options.map((o) => o.value).filter((v) => v !== ''));
  return (valeur) => autorisees.has(valeur);
}

function dateValide(valeur: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(valeur)) return false;
  const d = new Date(`${valeur}T00:00:00Z`);
  return !Number.isNaN(d.getTime()) && d.toISOString().slice(0, 10) === valeur;
}

// Aucun caractère de contrôle (U+0000 à U+001F, U+007F) dans un texte libre.
// eslint-disable-next-line no-control-regex
const CONTROLE = /[\u0000-\u001f\u007f]/;

function texteBorne(max: number): Regle {
  return (valeur) => valeur.length <= max && !CONTROLE.test(valeur);
}

const REGLES: Record<keyof Filter, Regle> = {
  size: parmi(TAILLE_OPTIONS),
  effectif: parmi(EFFECTIF_OPTIONS),
  priority: parmi(PRIORITY_OPTIONS),
  search: texteBorne(LONGUEUR_MAX_RECHERCHE),
  naf: (v) => v.length <= LONGUEUR_MAX_NAF && /^[0-9A-Za-z.]+$/.test(v),
  quality: parmi(QUALITY_OPTIONS),
  prospection_status: parmi(PROSPECTION_TABS),
  // Le référentiel des départements arrive de l'API après l'affichage : on
  // vérifie donc la FORME (01-95, 2A, 2B, 971-976), pas la liste.
  department_code: (v) => /^(\d{2}|2[AB]|97\d)$/.test(v),
  region_code: (v) => /^\d{2}$/.test(v),
  sector_main: parmi(SECTEUR_OPTIONS),
  country_code: parmi(COUNTRY_OPTIONS),
  best_email_confidence: parmi(CONFIANCE_EMAIL_OPTIONS),
  eligible_campagne: parmi(ELIGIBILITE_OPTIONS),
  entity_nature: parmi(NATURE_OPTIONS),
  joignabilite: parmi(JOIGNABILITE_OPTIONS),
  tag: texteBorne(LONGUEUR_MAX_ETIQUETTE),
  cree_apres: dateValide,
  cree_avant: dateValide,
};

const CLES = Object.keys(EMPTY_FILTER) as Array<keyof Filter>;

/**
 * Anciens noms de paramètres encore portés par des liens ou des favoris.
 * `quality_badge` : le nom de la colonne en base, employé par le lien du
 * tableau de bord jusqu'au 2026-10-02 ; le filtre s'appelle `quality`.
 */
const ALIAS: Record<string, keyof Filter> = { quality_badge: 'quality' };

/**
 * Le routeur décode les valeurs de l'adresse comme du JSON : `?department_code=75`
 * arrive en NOMBRE 75, `?eligible_campagne=1` en nombre 1. On les ramène en
 * texte ; tout le reste (objet, tableau, booléen) est refusé.
 */
function enTexte(brut: unknown): string | null {
  if (typeof brut === 'string') return brut;
  if (typeof brut === 'number' && Number.isInteger(brut) && brut >= 0) return String(brut);
  return null;
}

/**
 * Lit les paramètres de l'adresse et ne garde que les filtres valides.
 * Sert de `validateSearch` à la route `/companies`.
 */
export function validerRechercheEntreprises(brut: Record<string, unknown>): RechercheEntreprises {
  const sortie: RechercheEntreprises = {};
  const lire = (cle: keyof Filter, valeur: unknown) => {
    const texte = enTexte(valeur);
    if (texte === null || texte === '') return;
    // Les départements à un chiffre arrivent en nombre (`?department_code=1`
    // n'a pas de sens, mais `01` devient 1 si on le tape sans guillemets).
    const normalise =
      cle === 'department_code' && /^\d$/.test(texte)
        ? `0${texte}`
        : cle === 'naf'
          ? normaliserNaf(texte)
          : texte;
    if (normalise === '') return;
    if (REGLES[cle](normalise)) sortie[cle] = normalise;
  };
  for (const [alias, cle] of Object.entries(ALIAS)) {
    if (alias in brut) lire(cle, brut[alias]);
  }
  for (const cle of CLES) {
    if (cle in brut) lire(cle, brut[cle]);
  }
  return sortie;
}

/** L'état complet des filtres à partir de l'adresse (déjà validée ou non). */
export function filtreDepuisRecherche(brut: Record<string, unknown>): Filter {
  return { ...EMPTY_FILTER, ...validerRechercheEntreprises(brut) };
}

/** Ce qu'on écrit dans l'adresse : seulement les filtres renseignés et valides. */
export function rechercheDepuisFiltre(filtre: Filter): RechercheEntreprises {
  return validerRechercheEntreprises({ ...filtre });
}
