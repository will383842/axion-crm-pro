/**
 * Export CSV de la SÉLECTION de l'écran Entreprises : on envoie les
 * identifiants cochés (jamais « tout ce qui correspond au filtre »), le serveur
 * rend le fichier, le navigateur le propose au téléchargement.
 *
 * Garde de production : le vrai `GET /companies/export` ne connaît pas encore
 * `filter[ids]` ; il l'ignorerait et rendrait TOUTE la liste. Le bouton ne
 * s'affiche donc que si la réponse de la liste annonce la fonction
 * (`meta.export_selection: true`) : la démo l'annonce, le vrai serveur non,
 * tant que le lot serveur du CRM ne l'a pas ajoutée.
 */
import { api } from '@/lib/api';

/** Plafond d'une sélection (le serveur n'en accepte pas plus pour une action de masse). */
export const SELECTION_MAX = 500;

/** Vrai seulement si le serveur annonce savoir exporter une sélection. */
export function exportSelectionAnnonce(meta: { export_selection?: boolean } | undefined): boolean {
  return meta?.export_selection === true;
}

export async function exporterSelection(ids: readonly number[]): Promise<Blob> {
  // Une sélection vide ne doit jamais partir : sans identifiant, l'export
  // porterait sur toute la liste.
  if (ids.length === 0 || ids.length > SELECTION_MAX) {
    throw new Error('Sélection vide ou trop grande : export refusé.');
  }
  const reponse = await api.get<Blob>('/companies/export', {
    params: { 'filter[ids]': ids.join(',') },
    responseType: 'blob',
  });
  return reponse.data;
}

/** Propose un fichier au téléchargement. */
export function proposerFichier(contenu: Blob, nom: string): void {
  const url = URL.createObjectURL(contenu);
  const lien = document.createElement('a');
  lien.href = url;
  lien.download = nom;
  document.body.appendChild(lien);
  lien.click();
  lien.remove();
  URL.revokeObjectURL(url);
}
