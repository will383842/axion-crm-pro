/** Page « en attente » créée par DM0-COQ : le lot DM2-PIL remplace ce contenu (plan 30 § 4.2). */
import { PageEnAttente } from '@/features/socle/PageEnAttente';
import { OngletsCampagnes } from '../campagnes/OngletsCampagnes';

export function ResultatsPage() {
  return <PageEnAttente title="Résultats" lot="DM2-PIL" onglets={<OngletsCampagnes />} />;
}
