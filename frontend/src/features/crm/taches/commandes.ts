/** Ctrl K : les actions de « Mes tâches ». Chargé tout seul par le registre des commandes. */
import type { CommandeRecherche } from '@/features/socle/commandes/registre';

export const commandes: CommandeRecherche[] = [
  { id: 'crm-mes-taches', libelle: 'Voir mes tâches', produit: 'crm', motsCles: ['tâches', 'à faire', 'rappel', 'retard'], aller: '/taches' },
  { id: 'crm-affaires', libelle: 'Voir les affaires', produit: 'crm', motsCles: ['affaires', 'devis', 'pipeline', 'montant'], aller: '/affaires' },
];
