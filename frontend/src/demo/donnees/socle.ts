/**
 * Données du socle : l'espace « Axion (démo) », les utilisateurs, les 6
 * notifications de la cloche (les mêmes que les maquettes, deux produits) et
 * les identifiants de connexion de démonstration (aucun compte réel).
 */
import { decalage, ilYa } from '../horloge';
import type { Espace, Notification, Utilisateur } from './types';

/** Identifiants de démonstration affichés dans le bandeau. Aucun compte réel. */
export const IDENTIFIANTS_DEMO = {
  email: 'demo@example.test',
  motDePasse: 'demo',
  /** Code de connexion à 6 chiffres (la date de la démo). */
  code: '120127',
} as const;

export const ESPACE_DEMO: Espace = { id: 'ws-demo', name: 'Axion (démo)' };

export const UTILISATEURS_DEMO: Utilisateur[] = [
  { id: 'u-demo-1', name: 'Claire Valette', email: IDENTIFIANTS_DEMO.email, role: 'Propriétaire', current_workspace_id: ESPACE_DEMO.id, totp_enabled_at: ilYa(40, 9, 0) },
  { id: 'u-demo-2', name: 'Sophie Mercier', email: 'sophie.mercier@example.test', role: 'Administrateur', current_workspace_id: ESPACE_DEMO.id, totp_enabled_at: ilYa(38, 9, 0) },
  { id: 'u-demo-3', name: 'Hugo Lambert', email: 'hugo.lambert@example.test', role: 'Opérateur', current_workspace_id: ESPACE_DEMO.id, totp_enabled_at: ilYa(20, 9, 0) },
  { id: 'u-demo-4', name: 'Lucie Perrin', email: 'lucie.perrin@example.test', role: 'Lecteur', current_workspace_id: ESPACE_DEMO.id, totp_enabled_at: ilYa(12, 9, 0) },
];

const notif = (
  id: string,
  produit: Notification['produit'],
  niveau: Notification['niveau'],
  title: string,
  body: string,
  lien: string,
  created_at: string,
): Notification => ({ id, type: `demo.${niveau}`, title, body, action_url: lien, lien, produit, niveau, read_at: null, created_at });

/** Les 6 alertes de la cloche des maquettes (`ALERTES_CLOCHE`), datées sur l'horloge de démo. */
export function notificationsDemo(): Notification[] {
  return [
    notif('ntf-1', 'envoi', 'attention', 'Boîte « camille@ » ralentie', 'Refus temporaires : 3 %. Plafond divisé par deux.', '/envoi/boites/boi-camille', decalage({ minutes: -12 })),
    notif('ntf-2', 'envoi', 'a_faire', '2 réponses intéressées', 'Organisateurs Rhône-Alpes.', '/envoi/reponses', decalage({ minutes: -25 })),
    notif('ntf-3', 'crm', 'a_faire', '12 doublons à vérifier', 'Collecte « Rhône » terminée.', '/doublons', ilYa(0, 7, 40)),
    notif('ntf-4', 'crm', 'attention', 'Demande RGPD : 5 jours restants', 'Droit d’accès. Reçue le 8 janvier.', '/rgpd', ilYa(4, 14, 10)),
    notif('ntf-5', 'envoi', 'nouveau', 'DMARC : passer à « quarantaine »', 'prospection-c.example est prêt. Rappel au 15 janvier.', '/envoi/domaines/dom-c', ilYa(1, 18, 0)),
    notif('ntf-6', 'envoi', 'fait', 'Test du matin réussi', '07:05, 4 témoins sur 4.', '/envoi/sante', ilYa(0, 7, 5)),
  ];
}
