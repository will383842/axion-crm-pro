import { StatusPill, type StatusTone } from '@/components/ui';
import type { components } from '@/features/envoi/api/commun';

export type EtatFeu = components['schemas']['EtatFeu'];

const LIBELLES: Record<EtatFeu, string> = { vert: 'Bon', ambre: 'Attention', rouge: 'Arrêté', bleu: 'En montée', gris: 'Inconnu' };
const TONS: Record<EtatFeu, StatusTone> = { vert: 'success', ambre: 'warning', rouge: 'danger', bleu: 'info', gris: 'neutral' };

export const libelleFeu = (e: EtatFeu): string => LIBELLES[e];

/** Pastille d'état d'un feu : toujours un mot, jamais la couleur seule. */
export function Feu({ etat }: { etat: EtatFeu }) {
  return <StatusPill tone={TONS[etat]}>{LIBELLES[etat]}</StatusPill>;
}
