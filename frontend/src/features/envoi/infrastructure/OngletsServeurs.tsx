/**
 * DM0-COQ — barre d'onglets PARTAGÉE « Serveurs et adresses IP », créée complète ici pour
 * qu'aucun lot d'écran n'ait à la toucher (plan 30 § 4.1).
 */
import { OngletsDeNavigation, type OngletDeNavigation } from '@/features/socle/OngletsDeNavigation';

export const ONGLETS: readonly OngletDeNavigation[] = [
  { to: '/envoi/serveurs', libelle: 'Serveurs et adresses IP' },
  { to: '/envoi/serveurs/configuration', libelle: 'Configuration' },
];

export function OngletsServeurs() {
  return <OngletsDeNavigation libelle="Serveurs et adresses IP" onglets={ONGLETS} />;
}
