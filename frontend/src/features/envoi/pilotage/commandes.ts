import type { CommandeRecherche } from '@/features/socle/commandes/registre';

export const commandes: CommandeRecherche[] = [
  { id: 'pilotage-chercher-envoi', libelle: 'Chercher un envoi', produit: 'envoi', motsCles: ['adresse', 'message', 'refus', 'raison'], aller: '/envoi/sante/chercher' },
  { id: 'pilotage-resultats', libelle: 'Résultats des envois', produit: 'envoi', motsCles: ['réponses', 'boîte', 'domaine', 'export'], aller: '/envoi/campagnes/resultats' },
  { id: 'pilotage-sante', libelle: 'Santé des envois', produit: 'envoi', motsCles: ['feu', 'test du matin', 'file'], aller: '/envoi/sante' },
  { id: 'pilotage-feu-vert', libelle: 'Feu vert avant le premier envoi', produit: 'envoi', motsCles: ['critères', 'checklist'], aller: '/envoi/sante/feu-vert' },
];
