/** Ctrl K : les actions de conformité d'Envoi (DM2-CNF). Parcours P8. */
import type { CommandeRecherche } from '@/features/socle/commandes/registre';

export const commandes: CommandeRecherche[] = [
  { id: 'conformite-puis-je-ecrire', libelle: 'Puis-je écrire à… ?', produit: 'envoi', motsCles: ['adresse', 'opposition', 'désinscrit', 'vérifier', 'ne plus écrire'], aller: '/envoi/desinscriptions' },
  { id: 'conformite-desinscriptions', libelle: 'Ouvrir les désinscriptions', produit: 'envoi', motsCles: ['registre', 'stop', 'opposition'], aller: '/envoi/desinscriptions' },
  { id: 'conformite-alertes', libelle: 'Voir les alertes d’envoi', produit: 'envoi', motsCles: ['alertes', 'avis', 'cloche'], aller: '/envoi/alertes' },
  { id: 'conformite-reglages', libelle: 'Ouvrir les réglages d’envoi', produit: 'envoi', motsCles: ['identité', 'règles', 'horaires', 'absence'], aller: '/envoi/reglages' },
];
