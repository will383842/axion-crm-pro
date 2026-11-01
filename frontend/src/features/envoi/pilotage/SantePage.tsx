/** Page « en attente » créée par DM0-COQ : le lot DM2-PIL remplace ce contenu (plan 30 § 4.2). */
import { PageEnAttente } from '@/features/socle/PageEnAttente';
import { OngletsSante } from './OngletsSante';

export function SantePage() {
  return <PageEnAttente title="Santé des envois" lot="DM2-PIL" onglets={<OngletsSante />} />;
}
