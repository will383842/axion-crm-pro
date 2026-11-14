/**
 * DM0-COQ — barre d'onglets PARTAGÉE « Santé des envois », créée complète ici pour
 * qu'aucun lot d'écran n'ait à la toucher (plan 30 § 4.1).
 */
import { OngletsDeNavigation, type OngletDeNavigation } from '@/features/socle/OngletsDeNavigation';

export const ONGLETS: readonly OngletDeNavigation[] = [
  { to: '/envoi/sante', libelle: 'Santé des envois' },
  { to: '/envoi/sante/chercher', libelle: 'Chercher un envoi' },
  { to: '/envoi/sante/feu-vert', libelle: 'Feu vert' },
  { to: '/envoi/sante/reputation', libelle: 'Réputation' },
];

export function OngletsSante() {
  return <OngletsDeNavigation libelle="Santé des envois" onglets={ONGLETS} />;
}
