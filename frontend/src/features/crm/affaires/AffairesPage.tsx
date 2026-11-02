/** Page « en attente » créée par DM0-COQ : le lot DM1-ACC remplace ce contenu (plan 30 § 4.2). */
import { PageEnAttente } from '@/features/socle/PageEnAttente';
import { OngletsTableauDeBord } from '@/features/dashboard/OngletsTableauDeBord';

export function AffairesPage() {
  return <PageEnAttente title="Affaires" lot="DM1-ACC" onglets={<OngletsTableauDeBord />} />;
}
