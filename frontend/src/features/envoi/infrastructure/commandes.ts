/** DM2-INF — actions de Ctrl K pour l'infrastructure d'envoi (chargé par le registre du socle). */
import type { CommandeRecherche } from '@/features/socle/commandes/registre';
import { demanderAction } from './composants/actionDemandee';

export const commandes: CommandeRecherche[] = [
  { id: 'infra-ajouter-ip', libelle: 'Ajouter une adresse IP', produit: 'envoi', motsCles: ['ip', 'serveur', 'adresse', 'ajouter et appliquer'], agir: () => demanderAction('ajouter-ip', '/envoi/serveurs') },
  { id: 'infra-ajouter-domaine', libelle: 'Ajouter un domaine', produit: 'envoi', motsCles: ['domaine', 'dns', 'spf', 'dkim'], agir: () => demanderAction('ajouter-domaine', '/envoi/domaines') },
  { id: 'infra-boites', libelle: 'Ouvrir les boîtes d’envoi', produit: 'envoi', motsCles: ['boîtes', 'quota'], aller: '/envoi/boites' },
  { id: 'infra-domaines', libelle: 'Ouvrir les domaines', produit: 'envoi', motsCles: ['domaines', 'dmarc', 'liste noire'], aller: '/envoi/domaines' },
  { id: 'infra-serveurs', libelle: 'Ouvrir les serveurs et adresses IP', produit: 'envoi', motsCles: ['serveur', 'ip', 'configuration'], aller: '/envoi/serveurs' },
  { id: 'infra-chauffe', libelle: 'Voir la chauffe des boîtes', produit: 'envoi', motsCles: ['chauffe', 'montée', 'palier'], aller: '/envoi/chauffe' },
];
