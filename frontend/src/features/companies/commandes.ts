/**
 * Ctrl K — actions de la zone « entreprises » (DM1-ENT). Chargé tout seul par
 * `features/socle/commandes/registre.ts`.
 */
import type { CommandeRecherche } from '@/features/socle/commandes/registre';

export const commandes: CommandeRecherche[] = [
  { id: 'entreprises-liste', libelle: 'Ouvrir les entreprises', produit: 'crm', motsCles: ['entreprises', 'sociétés', 'fiches'], aller: '/companies' },
  { id: 'entreprises-prospectables', libelle: 'Voir les entreprises à priorité haute', produit: 'crm', motsCles: ['priorité', 'prospectables'], aller: '/companies?priority=haute' },
];
