/**
 * Libellés du dictionnaire d'origine v1 (`resources/dictionnaire-origine.v1.json`).
 * Une famille ou un détail absent (null) ou « autre_non_classe » se dit PARTOUT
 * « Non classé » : même mot sur la vue d'ensemble, la liste et la fiche.
 */
export const NON_CLASSE = 'Non classé';

export const FAMILLES: Record<string, { libelle: string; details: Record<string, string> }> = {
  guide_reseau: { libelle: 'Guide réseau', details: { guide_ia: 'Guide IA', autre_non_classe: NON_CLASSE } },
  cas_concret: { libelle: 'Cas concret', details: { autre_non_classe: NON_CLASSE } },
  carrousel: { libelle: 'Carrousel', details: { autre_non_classe: NON_CLASSE } },
  video: { libelle: 'Vidéo', details: { autre_non_classe: NON_CLASSE } },
  tour_france_ia: { libelle: 'Tour France IA', details: { autre_non_classe: NON_CLASSE } },
  autre: { libelle: 'Autre', details: { autre_non_classe: NON_CLASSE } },
};

const RESEAUX: Record<string, string> = { instagram: 'Instagram', facebook: 'Facebook', linkedin: 'LinkedIn', tiktok: 'TikTok' };
export const RESEAUX_CHOIX = Object.keys(RESEAUX);

const CANAUX: Record<string, string> = { commentaire: 'Commentaire', message_prive: 'Message privé', publication: 'Publication', page_site: 'Page du site' };

function lisible(cle: string): string {
  const t = cle.replace(/[_-]+/g, ' ').trim();
  return t === '' ? cle : t.charAt(0).toUpperCase() + t.slice(1);
}

export const libelleReseau = (r: string | null | undefined): string | null => (r === null || r === undefined || r === '' ? null : (RESEAUX[r] ?? lisible(r)));
export const libelleCanal = (c: string | null | undefined): string | null => (c === null || c === undefined || c === '' ? null : (CANAUX[c] ?? lisible(c)));
export const libelleFamille = (f: string | null | undefined): string => (f === null || f === undefined || f === '' ? NON_CLASSE : (FAMILLES[f]?.libelle ?? lisible(f)));

/** « Famille › Détail » ; « Non classé » seul si la famille est inconnue. */
export function libelleOrigine(famille: string | null | undefined, detail: string | null | undefined): string {
  if (famille === null || famille === undefined || famille === '') return NON_CLASSE;
  const d = detail === null || detail === undefined || detail === '' ? null : (FAMILLES[famille]?.details[detail] ?? lisible(detail));
  return d === null ? libelleFamille(famille) : `${libelleFamille(famille)} › ${d}`;
}

export function dateFr(iso: string | null | undefined, avecHeure = false): string {
  if (iso === null || iso === undefined) return '—';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '—';
  const jour = d.toLocaleDateString('fr-FR', { timeZone: 'Europe/Paris' });
  return avecHeure ? `${jour} · ${d.toLocaleTimeString('fr-FR', { timeZone: 'Europe/Paris', hour: '2-digit', minute: '2-digit' })}` : jour;
}

/** `2026-11-04` → `04/11` sans passer par un fuseau. */
export const jourCourt = (iso: string): string => `${iso.slice(8, 10)}/${iso.slice(5, 7)}`;

export const nombre = (n: number): string => n.toLocaleString('fr-FR');
