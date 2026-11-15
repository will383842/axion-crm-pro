/** S2-ROUTEUR — page d'attente des séquences ; F-SEQ remplace ce contenu. */
import { PageEnAttente } from '@/features/socle/PageEnAttente';
import { OngletsCampagnes } from '@/features/envoi/campagnes/OngletsCampagnes';

export function SequencesPage() {
  return <PageEnAttente title="Séquences" lot="F-SEQ" onglets={<OngletsCampagnes />} />;
}
