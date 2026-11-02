/**
 * DM0-COQ — barre d'onglets PARTAGÉE « Campagnes », créée complète ici pour
 * qu'aucun lot d'écran n'ait à la toucher (plan 30 § 4.1).
 *
 * « Destinataires » n'existe que SANS CRM Pro (offre « Envoi seul ») : avec le
 * CRM, les destinataires viennent de ses audiences, étiquettes et listes.
 */
import { OngletsDeNavigation, type OngletDeNavigation } from '@/features/socle/OngletsDeNavigation';
import { useProduits } from '@/features/socle/produits/useProduits';

export function ongletsCampagnes(avecCrm: boolean): OngletDeNavigation[] {
  return [
    { to: '/envoi/campagnes', libelle: 'Campagnes', sousPages: true },
    { to: '/envoi/campagnes/calendrier', libelle: 'Calendrier' },
    { to: '/envoi/campagnes/messages', libelle: 'Messages types', sousPages: true },
    { to: '/envoi/campagnes/resultats', libelle: 'Résultats' },
    ...(avecCrm ? [] : [{ to: '/envoi/campagnes/destinataires', libelle: 'Destinataires' }]),
  ];
}

export function OngletsCampagnes() {
  const produits = useProduits();
  return <OngletsDeNavigation libelle="Campagnes" onglets={ongletsCampagnes(produits.crm !== 'absent')} />;
}
