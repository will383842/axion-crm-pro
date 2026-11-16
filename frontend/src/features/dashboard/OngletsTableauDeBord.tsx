/**
 * DM0-COQ — barre d'onglets PARTAGÉE « Tableau de bord », créée complète ici pour
 * qu'aucun lot d'écran n'ait à la toucher (plan 30 § 4.1).
 */
import { affairesDisponibles, tachesDisponibles } from '@/features/crm/taches/disponible';
import { OngletsDeNavigation, type OngletDeNavigation } from '@/features/socle/OngletsDeNavigation';

export const ONGLETS: readonly OngletDeNavigation[] = [
  { to: '/', libelle: 'Tableau de bord' },
  { to: '/taches', libelle: 'Mes tâches' },
  { to: '/affaires', libelle: 'Affaires' },
];

/** Les onglets dont l'écran est ouvert : « Affaires » attend son lot serveur (G2-D). */
export function ONGLETS_DISPONIBLES(): readonly OngletDeNavigation[] {
  return ONGLETS.filter((o) => (o.to === '/taches' ? tachesDisponibles() : o.to === '/affaires' ? affairesDisponibles() : true));
}

export function OngletsTableauDeBord() {
  return <OngletsDeNavigation libelle="Tableau de bord" onglets={ONGLETS_DISPONIBLES()} />;
}
