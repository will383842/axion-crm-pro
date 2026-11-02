/** DM2-INF — pastilles de feu (mot + signe, jamais la couleur seule) et jauge de quota. */
import { Circle, CircleDashed, Play, Square, Triangle } from 'lucide-react';
import type { ReactNode } from 'react';
import { StatusPill, cn } from '@/components/ui';
import type { EtatFeu } from './api';
import { LIBELLE_FEU } from './format';

const SIGNE: Record<EtatFeu, ReactNode> = {
  vert: <Circle className="h-3 w-3" fill="currentColor" />,
  ambre: <Triangle className="h-3 w-3" fill="currentColor" />,
  rouge: <Square className="h-3 w-3" fill="currentColor" />,
  bleu: <Play className="h-3 w-3" fill="currentColor" />,
  gris: <CircleDashed className="h-3 w-3" />,
};

export function PastilleFeu({ etat }: { etat: EtatFeu }) {
  const { mot, ton } = LIBELLE_FEU[etat];
  return (
    <StatusPill tone={ton}>
      <span aria-hidden className="inline-flex">{SIGNE[etat]}</span>
      {mot}
    </StatusPill>
  );
}

export function Jauge({ libelle, valeur, max, texte, ton = 'info' }: { libelle: string; valeur: number; max: number; texte: string; ton?: 'success' | 'warning' | 'info' | 'danger' }) {
  const pct = max > 0 ? Math.min(100, Math.round((valeur / max) * 100)) : 0;
  const barre = { success: 'bg-emerald-500', warning: 'bg-amber-500', info: 'bg-sky-500', danger: 'bg-rose-500' }[ton];
  return (
    <div role="meter" aria-valuemin={0} aria-valuemax={max} aria-valuenow={valeur} aria-label={`${libelle} : ${texte}`}>
      <div className="mb-1 flex justify-between text-xs text-slate-600">
        <span>{libelle}</span>
        <span>{texte}</span>
      </div>
      <div className="h-2 overflow-hidden rounded-full bg-slate-100">
        <div className={cn('h-full rounded-full', barre)} style={{ width: `${pct}%` }} />
      </div>
    </div>
  );
}
