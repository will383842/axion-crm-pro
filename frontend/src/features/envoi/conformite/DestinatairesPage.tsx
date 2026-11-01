/** Page « en attente » créée par DM0-COQ : le lot DM2-CNF remplace ce contenu (plan 30 § 4.2). */
import { PageEnAttente } from '@/features/socle/PageEnAttente';
import { OngletsCampagnes } from '../campagnes/OngletsCampagnes';

export function DestinatairesPage() {
  return <PageEnAttente title="Destinataires" lot="DM2-CNF" onglets={<OngletsCampagnes />} />;
}
