/** Le statut d'un message type, en mot et en couleur d'état (jamais la couleur du produit). */
import { StatusPill, type StatusTone } from '@/components/ui';
import type { MessageTypeEtat } from '../../creation/api';

const STATUTS: Record<MessageTypeEtat['statut'], { libelle: string; ton: StatusTone }> = {
  valide: { libelle: 'Validé', ton: 'success' },
  a_relire: { libelle: 'À relire', ton: 'warning' },
  brouillon: { libelle: 'Brouillon', ton: 'neutral' },
  archive: { libelle: 'Archivé', ton: 'neutral' },
};

export function StatutMessage({ statut }: { statut: MessageTypeEtat['statut'] }) {
  const s = STATUTS[statut];
  return <StatusPill tone={s.ton}>{s.libelle}</StatusPill>;
}
