/** Ctrl K : les actions de « Mes tâches ». Chargé tout seul par le registre des commandes. */
import type { CommandeRecherche } from '@/features/socle/commandes/registre';
import { affairesDisponibles, tachesDisponibles } from './disponible';

// « Mes tâches » partout ; « Affaires » seulement en démo (voir `disponible.ts`).
export const commandes: CommandeRecherche[] = [
  ...(tachesDisponibles()
    ? [{ id: 'crm-mes-taches', libelle: 'Voir mes tâches', produit: 'crm' as const, motsCles: ['tâches', 'à faire', 'rappel', 'retard'], aller: '/taches' }]
    : []),
  ...(affairesDisponibles()
    ? [{ id: 'crm-affaires', libelle: 'Voir les affaires', produit: 'crm' as const, motsCles: ['affaires', 'devis', 'pipeline', 'montant'], aller: '/affaires' }]
    : []),
];
