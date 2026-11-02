/** La frise des 5 étapes : on revient en arrière librement, on n'avance pas sans avoir fait la précédente. */
import { cn } from '@/components/ui';

export const ETAPES = ['Cible', 'Message', 'Quand', 'Vérification', 'Lancement'] as const;

export function Frise({
  courante,
  accessible,
  onAller,
}: {
  courante: number;
  /** Plus grande étape qu'on a le droit d'ouvrir. */
  accessible: number;
  onAller: (etape: number) => void;
}) {
  return (
    <ol aria-label="Étapes" className="mb-4 grid grid-cols-5 gap-1 md:gap-2">
      {ETAPES.map((nom, i) => {
        const n = i + 1;
        const active = n === courante;
        return (
          <li key={nom}>
            <button
              type="button"
              disabled={n > accessible}
              aria-current={active ? 'step' : undefined}
              onClick={() => { onAller(n); }}
              className={cn(
                'flex min-h-11 w-full flex-col items-center justify-center gap-0.5 rounded-lg px-1 py-1.5 text-center text-xs font-medium ring-1 transition md:flex-row md:gap-2 md:text-sm',
                active ? 'bg-produit-pale text-produit ring-produit' : 'bg-white text-slate-700 ring-slate-200 hover:bg-slate-50',
                n > accessible && 'cursor-not-allowed opacity-50 hover:bg-white',
              )}
            >
              <span className={cn('inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-xs font-semibold', active ? 'bg-produit text-white' : 'bg-slate-100 text-slate-700')}>{n}</span>
              <span className="max-w-full truncate">{nom}</span>
            </button>
          </li>
        );
      })}
    </ol>
  );
}
