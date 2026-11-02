/**
 * Ctrl K — actions des contacts (DM1-CON). Chargé tout seul par
 * `src/features/socle/commandes/registre.ts`.
 *
 * Les gestes d'ouverture passent par un paramètre d'adresse lu par la page
 * « Contacts » (`?ajouter=1`, `?importer=1`, `?corbeille=1`).
 */
import type { CommandeRecherche } from '@/features/socle/commandes/registre';

function ouvrir(geste: 'ajouter' | 'importer' | 'corbeille'): void {
  window.history.pushState({}, '', `/console/contacts?${geste}=1`);
  window.dispatchEvent(new PopStateEvent('popstate'));
}

export const commandes: CommandeRecherche[] = [
  { id: 'contacts-ajouter', libelle: 'Ajouter un contact', produit: 'crm', motsCles: ['nouveau', 'créer', 'personne', 'ajout rapide'], agir: () => ouvrir('ajouter') },
  { id: 'contacts-importer', libelle: 'Importer des contacts', produit: 'crm', motsCles: ['csv', 'fichier', 'excel'], agir: () => ouvrir('importer') },
  { id: 'contacts-corbeille', libelle: 'Corbeille des contacts', produit: 'crm', motsCles: ['restaurer', 'retirés'], agir: () => ouvrir('corbeille') },
];
