/**
 * DM0-COQ — barre d'onglets PARTAGÉE « Réponses », créée complète ici pour
 * qu'aucun lot d'écran n'ait à la toucher (plan 30 § 4.1).
 */
import { OngletsDeNavigation, type OngletDeNavigation } from '@/features/socle/OngletsDeNavigation';

export const ONGLETS: readonly OngletDeNavigation[] = [
  { to: '/envoi/reponses', libelle: 'À traiter' },
  { to: '/envoi/reponses/a-rattacher', libelle: 'À rattacher' },
  { to: '/envoi/reponses/traitees', libelle: 'Traitées' },
];

export function OngletsReponses() {
  return <OngletsDeNavigation libelle="Réponses" onglets={ONGLETS} />;
}
