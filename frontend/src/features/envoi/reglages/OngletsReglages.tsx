/**
 * DM0-COQ — barre d'onglets PARTAGÉE « Réglages d’envoi », créée complète ici pour
 * qu'aucun lot d'écran n'ait à la toucher (plan 30 § 4.1).
 */
import { OngletsDeNavigation, type OngletDeNavigation } from '@/features/socle/OngletsDeNavigation';

export const ONGLETS: readonly OngletDeNavigation[] = [
  { to: '/envoi/reglages', libelle: 'Réglages' },
  { to: '/envoi/reglages/identite', libelle: 'Identité' },
  { to: '/envoi/reglages/alertes', libelle: 'Alertes et avis' },
  { to: '/envoi/reglages/regles', libelle: 'Règles et horaires' },
  { to: '/envoi/reglages/connexion-crm', libelle: 'Connexion au CRM' },
];

export function OngletsReglages() {
  return <OngletsDeNavigation libelle="Réglages d’envoi" onglets={ONGLETS} />;
}
