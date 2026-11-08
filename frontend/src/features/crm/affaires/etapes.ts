/**
 * Les sept étapes d'une affaire, dans l'ordre voulu par Will : du premier
 * contact au gain ou à la perte. Elles prolongent `lifecycle_stage` de la
 * fiche (`nouveau → perdu`) sans toucher à `crm-console/types.ts` (gelé).
 */
export type EtapeAffaire = 'a_contacter' | 'contacte' | 'repondu' | 'rendez_vous' | 'devis' | 'gagne' | 'perdu';

export const ETAPES: ReadonlyArray<{ valeur: EtapeAffaire; libelle: string }> = [
  { valeur: 'a_contacter', libelle: 'À contacter' },
  { valeur: 'contacte', libelle: 'Contacté' },
  { valeur: 'repondu', libelle: 'Répondu' },
  { valeur: 'rendez_vous', libelle: 'Rendez-vous' },
  { valeur: 'devis', libelle: 'Devis' },
  { valeur: 'gagne', libelle: 'Gagné' },
  { valeur: 'perdu', libelle: 'Perdu' },
];

export function libelleEtape(etape: EtapeAffaire): string {
  return ETAPES.find((e) => e.valeur === etape)?.libelle ?? etape;
}

/** Une affaire est ouverte tant qu'elle n'est ni gagnée ni perdue. */
export function estOuverte(etape: EtapeAffaire): boolean {
  return etape !== 'gagne' && etape !== 'perdu';
}

/** La liste courte des raisons de perte. */
export const RAISONS_DE_PERTE: readonly string[] = [
  'Prix trop élevé',
  'Concurrent choisi',
  'Pas de budget',
  'Pas de réponse',
  'Projet reporté',
  'Autre raison',
];

export interface Affaire {
  id: string;
  entreprise: string;
  entreprise_id: number | null;
  /** Montant en euros ; `null` tant qu'il n'est pas estimé. */
  montant: number | null;
  etape: EtapeAffaire;
  /** Chance de réussite, de 0 à 100. */
  chance: number;
  /** Date de signature prévue, AAAA-MM-JJ. */
  signature_prevue: string | null;
  raison_perte: string | null;
}

export interface ReponseAffaires {
  /** « Aujourd'hui » du serveur, AAAA-MM-JJ (jamais l'horloge du navigateur). */
  aujourdhui: string;
  data: Affaire[];
}
