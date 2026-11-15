/** S2-ROUTEUR — page d'attente d'une séquence ; F-SEQ remplace ce contenu. */
import { PageEnAttente } from '@/features/socle/PageEnAttente';
import { OngletsCampagnes } from '@/features/envoi/campagnes/OngletsCampagnes';

export function SequencePage() {
  return <PageEnAttente title="Séquence" lot="F-SEQ" onglets={<OngletsCampagnes />} />;
}
