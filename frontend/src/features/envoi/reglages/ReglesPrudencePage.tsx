/** Page « en attente » créée par DM0-COQ : le lot DM2-CNF remplace ce contenu (plan 30 § 4.2). */
import { PageEnAttente } from '@/features/socle/PageEnAttente';
import { OngletsReglages } from './OngletsReglages';

export function ReglesPrudencePage() {
  return <PageEnAttente title="Règles et horaires" lot="DM2-CNF" onglets={<OngletsReglages />} />;
}
