/** Page « en attente » créée par DM0-COQ : le lot DM2-INF remplace ce contenu (plan 30 § 4.2). */
import { PageEnAttente } from '@/features/socle/PageEnAttente';
import { OngletsServeurs } from './OngletsServeurs';

export function ConfigurationPage() {
  return <PageEnAttente title="Configuration" lot="DM2-INF" onglets={<OngletsServeurs />} />;
}
