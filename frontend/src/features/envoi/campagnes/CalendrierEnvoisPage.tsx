/** Page « en attente » créée par DM0-COQ : le lot DM2-CAM remplace ce contenu (plan 30 § 4.2). */
import { PageEnAttente } from '@/features/socle/PageEnAttente';
import { OngletsCampagnes } from './OngletsCampagnes';

export function CalendrierEnvoisPage() {
  return <PageEnAttente title="Calendrier" lot="DM2-CAM" onglets={<OngletsCampagnes />} />;
}
