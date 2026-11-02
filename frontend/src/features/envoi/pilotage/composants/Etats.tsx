import type { ReactNode } from 'react';
import { AlertTriangle, Inbox } from 'lucide-react';
import { Button } from '@/components/ui';

/** Chargement : squelette discret annoncé aux lecteurs d'écran. */
export function Chargement({ libelle }: { libelle: string }) {
  return (
    <div role="status" aria-live="polite" aria-label={libelle} className="space-y-3">
      <div className="h-24 animate-pulse rounded-xl bg-slate-100" />
      <div className="h-48 animate-pulse rounded-xl bg-slate-100" />
      <span className="sr-only">{libelle}</span>
    </div>
  );
}

/** État vide : « il n'y a rien », jamais confondu avec une panne. */
export function Vide({ titre = 'Rien à afficher pour le moment', description = 'Les éléments apparaîtront ici dès qu’il y en aura.', action }: { titre?: string; description?: string; action?: ReactNode }) {
  return (
    <div className="flex flex-col items-center rounded-xl border-2 border-dashed border-slate-200 bg-white px-6 py-12 text-center">
      <Inbox className="mb-3 h-10 w-10 text-slate-400" aria-hidden />
      <h2 className="text-lg font-bold text-slate-900">{titre}</h2>
      <p className="mt-1 max-w-md text-sm text-slate-600">{description}</p>
      {action ? <div className="mt-5">{action}</div> : null}
    </div>
  );
}

/** Panne : dit ce qui s'est passé et propose de réessayer. */
export function Panne({ titre = 'Lecture impossible', description = 'Les données n’ont pas pu être lues. Rien n’est perdu. Réessayez dans un instant.', onReessayer }: { titre?: string; description?: string; onReessayer: () => void }) {
  return (
    <div role="alert" className="flex flex-col items-center rounded-xl border border-amber-200 bg-amber-50 px-6 py-10 text-center">
      <AlertTriangle className="mb-3 h-9 w-9 text-amber-600" aria-hidden />
      <h2 className="text-lg font-bold text-slate-900">{titre}</h2>
      <p className="mt-1 max-w-md text-sm text-slate-700">{description}</p>
      <Button variant="secondary" className="mt-5 min-h-11 md:min-h-9" onClick={onReessayer}>Réessayer</Button>
    </div>
  );
}
