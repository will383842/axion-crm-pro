/**
 * Référentiels PARTAGÉS des écrans de prospection.
 *
 * Ces listes vivaient en double, recopiées dans chaque page. Le dépôt applique
 * déjà la règle ailleurs (`CompanyQueryFilters` partagé entre la liste
 * entreprises et le hub de contacts) et la motive ainsi : « deux listes
 * jumelles finissent toujours par diverger, et l'écart se découvre par un
 * export qui ne correspond plus à la liste affichée ». Un pays ajouté ici
 * apparaît donc partout, ou nulle part — jamais dans un écran sur deux.
 */

export interface OptionReferentiel {
  value: string;
  label: string;
}

/**
 * Prospection internationale : sans ce filtre, les fiches étrangères restent
 * noyées dans les 4,29 M de fiches françaises et aucune campagne ne peut les
 * viser. « Tous pays » laisse le comportement historique inchangé.
 */
export const COUNTRY_OPTIONS: OptionReferentiel[] = [
  { value: '', label: 'Tous pays' },
  { value: 'FR', label: 'France' },
  { value: 'RO', label: 'Roumanie' },
];

/**
 * Nature de l'entité — une association et une entreprise ne se prospectent pas
 * de la même façon, et le vocabulaire est celui de la base (`entity_nature`).
 */
export const NATURE_OPTIONS: OptionReferentiel[] = [
  { value: '', label: 'Toutes natures' },
  { value: 'entreprise', label: 'Entreprises' },
  { value: 'association', label: 'Associations' },
  { value: 'institution', label: 'Institutions' },
  { value: 'autre', label: 'Autres' },
];

/**
 * Statut de prospection — LE vocabulaire qui répond à « qui puis-je contacter ».
 *
 * 🔴 C'est le champ que le CRM pose lui-même selon ce qu'il a trouvé, et c'est
 * lui qui distingue une fiche seulement COLLECTÉE d'une fiche ENRICHIE et
 * réellement contactable. Les libellés disent l'action possible, pas l'état
 * technique : « Contactables » est plus utile que « ready_for_outreach ».
 */
/**
 * Prêt pour une campagne — la définition CALCULÉE côté serveur
 * (`App\Support\EligibiliteCampagne`), reprise telle quelle par le futur
 * moteur d'envoi. Une adresse existe ET ne s'est pas opposée.
 *
 * 🔴 On ne déplace PAS les fiches complètes dans un bac : une fiche sort
 * d'elle-même de cette liste le jour où l'adresse s'oppose, et 3,49 M de
 * fiches encore à enrichir la rejoindront au fil de l'eau. Un ensemble figé
 * serait faux la semaine suivante — et continuerait d'arroser un désinscrit.
 */
export const ELIGIBILITE_OPTIONS: OptionReferentiel[] = [
  { value: '', label: 'Prêtes ou non' },
  { value: '1', label: '✅ Prêtes pour une campagne' },
  { value: '0', label: '⏳ Reste à enrichir' },
];

/**
 * Qualité de l'adresse — le VRAI signal « complet pour une campagne », bien
 * plus utile qu'un binaire. Mesuré en production le 2026-08-15 sur les
 * 255 290 fiches pourvues d'une adresse : A = 165 587, B = 48 554, C = 40 956.
 * Une première campagne se lance sur les A, pas sur le tas entier.
 */
export const CONFIANCE_EMAIL_OPTIONS: OptionReferentiel[] = [
  { value: '', label: 'Toutes qualités' },
  { value: 'A', label: 'A — adresse du domaine officiel' },
  { value: 'B', label: 'B — adresse probable' },
  { value: 'C', label: 'C — adresse incertaine' },
];

export const PROSPECTION_STATUS_OPTIONS: OptionReferentiel[] = [
  { value: '', label: 'Tous statuts' },
  { value: 'ready_for_outreach', label: 'Contactables (e-mail exploitable)' },
  { value: 'partial_email', label: 'Partiels (e-mail incertain)' },
  { value: 'pending', label: 'Collectés, pas encore enrichis' },
  { value: 'archived_no_email', label: 'Sans e-mail (archivés)' },
];
