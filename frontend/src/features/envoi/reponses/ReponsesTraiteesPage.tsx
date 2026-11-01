/** Page « en attente » créée par DM0-COQ : le lot DM2-REP remplace ce contenu (plan 30 § 4.2). */
import { PageEnAttente } from '@/features/socle/PageEnAttente';
import { OngletsReponses } from './OngletsReponses';

export function ReponsesTraiteesPage() {
  return <PageEnAttente title="Réponses traitées" lot="DM2-REP" onglets={<OngletsReponses />} />;
}
