/** DM2-CAM — actions de Ctrl K pour les campagnes (chargé tout seul par le registre du socle). */
import type { CommandeRecherche } from '@/features/socle/commandes/registre';

export const commandes: CommandeRecherche[] = [
  { id: 'campagnes-liste', libelle: 'Voir les campagnes en cours', produit: 'envoi', motsCles: ['campagnes', 'en cours', 'pause', 'reprendre', 'terminer'], aller: '/envoi/campagnes' },
  { id: 'campagnes-calendrier', libelle: 'Ouvrir le calendrier des envois', produit: 'envoi', motsCles: ['calendrier', 'semaine', 'programmé', 'date'], aller: '/envoi/campagnes/calendrier' },
  { id: 'campagnes-destinataire', libelle: 'Retrouver un destinataire dans une campagne', produit: 'envoi', motsCles: ['destinataire', 'refusé', 'état', 'adresse'], aller: '/envoi/campagnes' },
];
