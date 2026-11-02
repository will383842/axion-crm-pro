/**
 * Export CSV de la SÉLECTION de l'écran Entreprises : on envoie les
 * identifiants cochés (jamais « tout ce qui correspond au filtre »), le serveur
 * rend le fichier, le navigateur le propose au téléchargement.
 */
import { api } from '@/lib/api';

/** Plafond d'une sélection (le serveur n'en accepte pas plus pour une action de masse). */
export const SELECTION_MAX = 500;

export async function exporterSelection(ids: readonly number[]): Promise<Blob> {
  const reponse = await api.get<Blob>(`/companies/export?ids=${ids.join(',')}`, { responseType: 'blob' });
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
