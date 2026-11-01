/**
 * DM0-COQ — les commandes Ctrl K du socle. Uniquement d'Envoi : sans Envoi
 * (production d'Axion aujourd'hui), Ctrl K reste exactement ce qu'il était.
 * Chaque lot d'écran ajoute les siennes dans SON `commandes.ts`.
 */
import type { CommandeRecherche } from './commandes/registre';

export const commandes: CommandeRecherche[] = [
  { id: 'socle-envoi-campagnes', libelle: 'Ouvrir les campagnes', produit: 'envoi', motsCles: ['campagnes', 'envoi', 'e-mails'], aller: '/envoi/campagnes' },
  { id: 'socle-envoi-sante', libelle: 'Voir la santé des envois', produit: 'envoi', motsCles: ['feu', 'état', 'délivrabilité'], aller: '/envoi/sante' },
];
