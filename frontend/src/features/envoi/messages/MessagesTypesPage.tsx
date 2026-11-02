/** Page « en attente » créée par DM0-COQ : le lot DM2-ASS remplace ce contenu (plan 30 § 4.2). */
import { PageEnAttente } from '@/features/socle/PageEnAttente';
import { OngletsCampagnes } from '../campagnes/OngletsCampagnes';

export function MessagesTypesPage() {
  return <PageEnAttente title="Messages types" lot="DM2-ASS" onglets={<OngletsCampagnes />} />;
}
