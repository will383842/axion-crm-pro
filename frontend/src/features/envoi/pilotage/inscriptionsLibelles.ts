/** Libellés de l'écran « Inscriptions » (purs, testables). Lecture seule : rien ici n'écrit. */
export const TITRE_FICHE_K14 = 'Fiche K14 — inscriptions de réputation (pas à pas)';

type EtatPoint = 'fait' | 'a_faire' | 'attend_serveur_reel';
type Source = 'google_postmaster' | 'microsoft_snds' | 'microsoft_jmrp' | 'orange';

export interface ServiceInscription {
  nom: string;
  /** Code du point du feu vert (ne change jamais, même si le libellé évolue). */
  code: string;
  /** Sources de constats de réputation qui concernent ce service. */
  sources: readonly Source[];
}

export const SERVICES: readonly ServiceInscription[] = [
  { nom: 'Google Postmaster Tools', code: 'manuel.postmaster_google', sources: ['google_postmaster'] },
  { nom: 'Microsoft SNDS et JMRP', code: 'manuel.snds_jmrp', sources: ['microsoft_snds', 'microsoft_jmrp'] },
  { nom: 'Yahoo, boucle de plaintes (CFL)', code: 'manuel.yahoo_cfl', sources: [] },
  { nom: 'Orange : contact délivrabilité', code: 'manuel.orange_cfl', sources: ['orange'] },
  { nom: 'Signal Spam', code: 'manuel.signal_spam', sources: [] },
];

/** Un point absent du feu vert, ou qui attend le serveur réel, n'est pas évalué : on ne dit jamais « fait » à tort. */
export function libelleEtat(etat: EtatPoint | undefined): 'Fait' | 'À faire' | 'Non évalué' {
  if (etat === 'fait') return 'Fait';
  if (etat === 'a_faire') return 'À faire';
  return 'Non évalué';
}

const VALEURS: Record<string, string> = { bonne: 'Bonne', moyenne: 'Moyenne', mauvaise: 'Mauvaise', inconnue: 'Inconnue' };
export const libelleReputation = (v: string): string => VALEURS[v] ?? 'Inconnue';
