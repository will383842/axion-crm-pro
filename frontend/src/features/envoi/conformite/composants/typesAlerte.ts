/** Libellés français des types d'alerte d'envoi (`TypeAlerte` du contrat) et des motifs de pause de la garde de chauffe. */
import type { components } from '@/features/envoi/api/commun';

export type TypeAlerte = components['schemas']['TypeAlerte'];

export const LIBELLE_TYPE_ALERTE: Record<TypeAlerte, string> = {
  taux_refus: 'Taux de refus trop haut',
  plainte: 'Plainte reçue',
  liste_noire: 'Liste noire',
  boite_ralentie: 'Boîte ralentie',
  test_matin: 'Test du matin',
  reponse_interessee: 'Réponse intéressée',
  quota_atteint: 'Plafond du jour atteint',
  temoin_indesirable: 'Message en indésirables chez un témoin',
  reponses_en_baisse: 'Réponses en baisse',
  reports_en_hausse: 'Reports en hausse',
  homme_mort: 'Tableau de bord non validé',
  sante_silencieuse: 'Santé des envois silencieuse',
  reponses_non_traitees: 'Réponses non traitées',
};

/** Motif d'une pause active (code de la garde) → phrase affichable ; un code inconnu s'affiche tel quel, sans invention. */
const MOTIF_PAUSE: Record<string, string> = {
  rebonds_durs_trop_nombreux: 'Trop de refus définitifs',
  deux_plaintes_en_sept_jours: 'Deux plaintes en sept jours',
  indesirable_chez_un_temoin: LIBELLE_TYPE_ALERTE.temoin_indesirable,
  taux_de_reponse_en_baisse: LIBELLE_TYPE_ALERTE.reponses_en_baisse,
  reports_en_hausse_sur_une_heure: LIBELLE_TYPE_ALERTE.reports_en_hausse,
  tableau_de_bord_non_valide: LIBELLE_TYPE_ALERTE.homme_mort,
  sante_silencieuse: LIBELLE_TYPE_ALERTE.sante_silencieuse,
  reponses_non_traitees: LIBELLE_TYPE_ALERTE.reponses_non_traitees,
};

export const libelleMotifPause = (motif: string): string => MOTIF_PAUSE[motif] ?? motif;
