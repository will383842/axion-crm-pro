/** S2-ROUTEUR — page d'attente des inscriptions (lecture seule) ; K14-ECRAN remplace ce contenu. */
import { PageEnAttente } from '@/features/socle/PageEnAttente';
import { OngletsSante } from './OngletsSante';

export function InscriptionsPage() {
  return <PageEnAttente title="Inscriptions" lot="K14-ECRAN" onglets={<OngletsSante />} />;
}
