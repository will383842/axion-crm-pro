/**
 * DM1-CIB — action Ctrl K de la page Étiquettes. Chargée toute seule par le
 * registre du socle ; produit « envoi » : sans Envoi, elle ne s'affiche pas.
 */
import type { CommandeRecherche } from '@/features/socle/commandes/registre';

export const commandes: CommandeRecherche[] = [
  {
    id: 'tags-campagne-vers-etiquette',
    libelle: 'Lancer une campagne vers une étiquette',
    produit: 'envoi',
    motsCles: ['étiquette', 'campagne', 'cible', 'envoi', 'e-mails'],
    aller: '/tags',
  },
];
