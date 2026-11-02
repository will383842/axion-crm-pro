/**
 * DM0-COQ — LA BARRE DE L'ESPACE ENVOI (maquettes M2 validées, campagnes.html).
 *
 * Dix entrées, dans l'ordre des maquettes, sous une seule liste toujours
 * dépliée (pas d'accordéon : dix entrées tiennent d'un regard). Les pages à
 * plusieurs vues ont des ONGLETS en haut de page (`Onglets*.tsx`).
 *
 * Un libellé = un mot partout : le menu, le titre de la page et le fil
 * d'Ariane disent la même chose (garde `tests/components/navigation-cible.test.tsx`).
 *
 * Offre « Envoi seul » : les réglages du socle (Utilisateurs, Demandes RGPD,
 * Journal des actions) restent atteignables depuis la barre (ADR-12).
 */
import type { LayoutDashboard } from 'lucide-react';
import {
  Send,
  MessageSquareText,
  Inbox,
  Globe,
  Server,
  Flame,
  Activity,
  Ban,
  TriangleAlert,
  Settings as SettingsIcon,
  UserCog,
  ShieldCheck,
  ScrollText,
} from 'lucide-react';
import { produitsDe, type ConsoleFeatures } from '@/features/crm-console/useConsoleFeatures';
import type { NavSection } from './Sidebar';

const icone = (I: typeof LayoutDashboard) => <I className="h-4 w-4" />;

/** Identifiant de la section d'Envoi (lu par les gardes). */
export const SECTION_ENVOI = 'envoi';

export function sectionsEnvoi(features: ConsoleFeatures): NavSection[] {
  const sections: NavSection[] = [
    {
      id: SECTION_ENVOI,
      title: 'Envoi',
      sansTitre: true,
      items: [
        { to: '/envoi/campagnes', label: 'Campagnes', icon: icone(Send) },
        { to: '/envoi/reponses', label: 'Réponses', icon: icone(MessageSquareText), compteurEnvoi: 'reponses_a_traiter' },
        { to: '/envoi/boites', label: 'Boîtes d’envoi', icon: icone(Inbox) },
        { to: '/envoi/domaines', label: 'Domaines', icon: icone(Globe) },
        { to: '/envoi/serveurs', label: 'Serveurs et adresses IP', icon: icone(Server) },
        { to: '/envoi/chauffe', label: 'Chauffe', icon: icone(Flame) },
        { to: '/envoi/sante', label: 'Santé des envois', icon: icone(Activity), pointDeFeu: true },
        { to: '/envoi/desinscriptions', label: 'Désinscriptions', icon: icone(Ban) },
        { to: '/envoi/alertes', label: 'Alertes d’envoi', icon: icone(TriangleAlert), compteurEnvoi: 'alertes_ouvertes' },
        { to: '/envoi/reglages', label: 'Réglages d’envoi', icon: icone(SettingsIcon) },
      ],
    },
  ];
  if (produitsDe(features).crm === 'absent') {
    sections.push({
      id: 'commun',
      title: 'Commun',
      items: [
        { to: '/users', label: 'Utilisateurs', icon: icone(UserCog) },
        { to: '/rgpd/requests', label: 'Demandes RGPD', icon: icone(ShieldCheck) },
        { to: '/audit-logs', label: 'Journal des actions', icon: icone(ScrollText) },
      ],
    });
  }
  return sections;
}

/** Les phrases des pastilles de la barre d'Envoi. */
export const LIBELLES_COMPTEUR_ENVOI = {
  reponses_a_traiter: (n: number) => `${n} réponse${n > 1 ? 's' : ''} à traiter`,
  alertes_ouvertes: (n: number) => `${n} alerte${n > 1 ? 's' : ''} d’envoi ouverte${n > 1 ? 's' : ''}`,
} as const;

export type CleCompteurEnvoi = keyof typeof LIBELLES_COMPTEUR_ENVOI;
