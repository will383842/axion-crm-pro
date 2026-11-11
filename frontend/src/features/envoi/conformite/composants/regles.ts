/**
 * Règles de prudence : on ne peut que DURCIR (jamais assouplir).
 * Logique pure, partagée par l'écran (message avant l'envoi) et le faux
 * serveur de démonstration (réponse 422), donc testée une seule fois.
 */
import type { components } from '@/features/envoi/api/conformite';

export type ReglesPrudence = components['schemas']['Regles'];

export type ChampNombre = 'pause_si_refus_pct' | 'pause_si_plaintes_pour_mille' | 'frein_reponses_par_jour' | 'pression_max_par_organisation_30j';

export const CHAMPS_NOMBRE: ChampNombre[] = ['pause_si_refus_pct', 'pause_si_plaintes_pour_mille', 'frein_reponses_par_jour', 'pression_max_par_organisation_30j'];

/**
 * Valeur permise pour un seuil, selon `backend/openapi/envoi/conformite.yaml` :
 * - refus (%) et plaintes (pour 1 000) : nombre fini ≥ 0 (minimum 0 du contrat) ;
 *   0 est le durcissement maximal légitime (pause dès le premier refus ou la
 *   première plainte) et 0,5 est permis ;
 * - frein : entier ≥ 0 (minimum 0 du contrat) ;
 * - pression par organisation : entier ≥ 1 (minimum 1 du contrat).
 */
export function valeurPermise(cle: ChampNombre, n: unknown): boolean {
  if (typeof n !== 'number' || !Number.isFinite(n)) return false;
  if (cle === 'pause_si_refus_pct' || cle === 'pause_si_plaintes_pour_mille') return n >= 0;
  if (cle === 'frein_reponses_par_jour') return Number.isInteger(n) && n >= 0;
  return Number.isInteger(n) && n >= 1;
}

/** Texte de saisie : vide ou non numérique refusés, puis `valeurPermise`. */
export function saisiePermise(cle: ChampNombre, t: string | undefined): boolean {
  if (t === undefined || t.trim() === '') return false;
  return valeurPermise(cle, Number(t));
}

/** Phrase d'aide affichée quand la saisie est refusée. */
export function exigence(cle: ChampNombre): string {
  if (cle === 'frein_reponses_par_jour') return 'un nombre entier, 0 ou plus';
  if (cle === 'pression_max_par_organisation_30j') return 'un nombre entier d’au moins 1';
  return 'un nombre de 0 ou plus (0,5 est permis)';
}

function minutes(heure: string): number {
  const [h, m] = heure.split(':');
  return Number(h) * 60 + Number(m ?? 0);
}

/**
 * Les raisons (phrases, au vouvoiement) pour lesquelles `propose` assouplit
 * `actuel`. Liste vide : la modification est permise.
 */
export function verifierDurcissement(actuel: ReglesPrudence, propose: ReglesPrudence): string[] {
  const refus: string[] = [];
  if (propose.pause_si_refus_pct > actuel.pause_si_refus_pct) {
    refus.push(`Le seuil d’adresses refusées ne peut que baisser (actuellement ${actuel.pause_si_refus_pct} %).`);
  }
  if (propose.pause_si_plaintes_pour_mille > actuel.pause_si_plaintes_pour_mille) {
    refus.push(`Le seuil de plaintes ne peut que baisser (actuellement ${actuel.pause_si_plaintes_pour_mille} pour 1 000).`);
  }
  if (propose.frein_reponses_par_jour > actuel.frein_reponses_par_jour) {
    refus.push(`Le frein « réponses en attente » ne peut que baisser (actuellement ${actuel.frein_reponses_par_jour} par jour).`);
  }
  if (propose.pression_max_par_organisation_30j > actuel.pression_max_par_organisation_30j) {
    refus.push(`Le nombre de messages par organisation ne peut que baisser (actuellement ${actuel.pression_max_par_organisation_30j} sur 30 jours).`);
  }
  if (minutes(propose.heure_de) < minutes(actuel.heure_de)) {
    refus.push(`La fenêtre d’envoi ne peut que se resserrer : début au plus tôt à ${actuel.heure_de}.`);
  }
  if (minutes(propose.heure_a) > minutes(actuel.heure_a)) {
    refus.push(`La fenêtre d’envoi ne peut que se resserrer : fin au plus tard à ${actuel.heure_a}.`);
  }
  if (minutes(propose.heure_de) >= minutes(propose.heure_a)) {
    refus.push('L’heure de début doit précéder l’heure de fin.');
  }
  if (actuel.jours_ouvres_seulement && !propose.jours_ouvres_seulement) {
    refus.push('Les week-ends et jours fériés restent exclus : on ne peut pas les rouvrir.');
  }
  if (actuel.test_du_matin_bloquant && !propose.test_du_matin_bloquant) {
    refus.push('Le test du matin reste bloquant.');
  }
  return refus;
}
