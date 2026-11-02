/**
 * DM0-COQ — barre d'onglets PARTAGÉE « Tableau de bord », créée complète ici pour
 * qu'aucun lot d'écran n'ait à la toucher (plan 30 § 4.1).
 */
import { OngletsDeNavigation, type OngletDeNavigation } from '@/features/socle/OngletsDeNavigation';

export const ONGLETS: readonly OngletDeNavigation[] = [
  { to: '/', libelle: 'Tableau de bord' },
  { to: '/taches', libelle: 'Mes tâches' },
  { to: '/affaires', libelle: 'Affaires' },
];

export function OngletsTableauDeBord() {
  return <OngletsDeNavigation libelle="Tableau de bord" onglets={ONGLETS} />;
}
