import type { CommandeRecherche } from '@/features/socle/commandes/registre';

export const commandes: CommandeRecherche[] = [
  { id: 'reponses-a-traiter', libelle: 'Traiter les réponses', produit: 'envoi', motsCles: ['réponses', 'répondre', 'boîte', 'intéressé'], aller: '/envoi/reponses' },
  { id: 'reponses-a-rattacher', libelle: 'Rattacher une réponse à une fiche', produit: 'envoi', motsCles: ['adresse inconnue', 'rattacher', 'fiche'], aller: '/envoi/reponses/a-rattacher' },
  { id: 'reponses-traitees', libelle: 'Voir les réponses traitées', produit: 'envoi', motsCles: ['traitées', 'historique', 'stop'], aller: '/envoi/reponses/traitees' },
];
