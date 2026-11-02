/** Ctrl K : les actions des messages types et de la création de campagne (DM2-ASS). */
import type { CommandeRecherche } from '@/features/socle/commandes/registre';

export const commandes: CommandeRecherche[] = [
  { id: 'envoi-campagne-nouvelle', libelle: 'Créer une campagne', produit: 'envoi', motsCles: ['nouvelle campagne', 'envoyer', 'étiquette', 'audience', 'liste'], aller: '/envoi/campagnes/nouvelle' },
  { id: 'envoi-messages-types', libelle: 'Ouvrir les messages types', produit: 'envoi', motsCles: ['modèles', 'objet', 'texte', 'relance'], aller: '/envoi/campagnes/messages' },
  { id: 'envoi-message-nouveau', libelle: 'Écrire un message type', produit: 'envoi', motsCles: ['nouveau message', 'éditeur', 'objet'], aller: '/envoi/campagnes/messages/nouveau' },
];
