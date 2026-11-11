/**
 * CRM-OPP — libellé de l'accord d'une personne, en tenant compte de l'opposition.
 *
 * Règle : « accord donné » n'est JAMAIS affiché seul quand une opposition existe.
 * L'opposition l'emporte, qu'elle soit reçue avant ou après l'accord (la date de
 * l'accord et celle de l'opposition sont toutes deux montrées). Pure lecture :
 * rien n'est supprimé, ni côté données ni côté affichage.
 */
import type { ContenusAccords } from './types';

type Consentement = ContenusAccords['consentements'][number];

export interface LibelleAccord {
  /** Phrase prête à afficher (sans la source ni la version du texte). */
  texte: string;
  /** Consentement dont la source et la version du texte accompagnent la phrase. */
  retenu: Consentement | null;
}

function jour(value: string | null | undefined): string {
  if (value === null || value === undefined) return '—';
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString('fr-FR');
}

function plusRecent(liste: Consentement[]): Consentement | null {
  return liste.length === 0 ? null : liste.reduce((a, c) => ((c.date ?? '') >= (a.date ?? '') ? c : a));
}

/** `null` quand il n'y a ni accord, ni retrait, ni opposition à dire. */
export function libelleAccord(consentements: Consentement[], opposition: ContenusAccords['opposition']): LibelleAccord | null {
  const choix = consentements.filter((c) => c.nature === 'optin' || c.nature === 'retrait');
  const dernier = plusRecent(choix);
  const donne = plusRecent(choix.filter((c) => c.nature === 'optin'));

  if (opposition.actif) {
    if (donne !== null) {
      const retrait = plusRecent(choix.filter((c) => c.nature === 'retrait' && (c.date ?? '') >= (donne.date ?? '')));
      if (retrait !== null) {
        return { texte: `accord donné le ${jour(donne.date)}, retiré le ${jour(retrait.date)}, opposition le ${jour(opposition.date)}`, retenu: retrait };
      }
      return { texte: `accord donné le ${jour(donne.date)}, retiré par opposition le ${jour(opposition.date)}`, retenu: donne };
    }
    return { texte: `aucun accord donné · opposition reçue le ${jour(opposition.date)}`, retenu: dernier };
  }
  if (dernier === null) return null;
  return { texte: `${dernier.nature === 'retrait' ? 'retiré' : 'donné'} le ${jour(dernier.date)}`, retenu: dernier };
}
