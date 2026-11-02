/** Page « en attente » créée par DM0-COQ : le lot DM2-CNF remplace ce contenu (plan 30 § 4.2). */
import { PageEnAttente } from '@/features/socle/PageEnAttente';
import { OngletsReglages } from './OngletsReglages';

export function ConnexionCrmPage() {
  return <PageEnAttente title="Connexion au CRM" lot="DM2-CNF" onglets={<OngletsReglages />} />;
}
