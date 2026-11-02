/** DM2-INF — champs de formulaire des fenêtres d'ajout (44 px au téléphone, 36 px ensuite). */
import type { InputHTMLAttributes, ReactNode, SelectHTMLAttributes } from 'react';
import { useId } from 'react';
import { cn } from '@/components/ui';

export const CLASSE_CHAMP =
  'h-11 w-full rounded-lg bg-white px-3 text-sm text-slate-900 ring-1 ring-slate-300 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-produit md:h-9';
/** Boutons : 44 px au téléphone. */
export const BOUTON_TACTILE = 'max-md:h-11';

export function ChampTexte({ label, aide, className, ...rest }: { label: string; aide?: string } & InputHTMLAttributes<HTMLInputElement>) {
  const id = useId();
  return (
    <div className="flex flex-col gap-1">
      <label htmlFor={id} className="text-xs font-medium text-slate-700">{label}</label>
      <input id={id} className={cn(CLASSE_CHAMP, className)} aria-describedby={aide ? `${id}-aide` : undefined} {...rest} />
      {aide ? <p id={`${id}-aide`} className="text-xs text-slate-500">{aide}</p> : null}
    </div>
  );
}

export function ChampChoix({ label, children, ...rest }: { label: string; children: ReactNode } & SelectHTMLAttributes<HTMLSelectElement>) {
  const id = useId();
  return (
    <div className="flex flex-col gap-1">
      <label htmlFor={id} className="text-xs font-medium text-slate-700">{label}</label>
      <select id={id} className={CLASSE_CHAMP} {...rest}>{children}</select>
    </div>
  );
}

export function MessageErreur({ children }: { children: ReactNode }) {
  return (
    <p role="alert" className="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-800 ring-1 ring-rose-200">{children}</p>
  );
}

export function MessageSucces({ children }: { children: ReactNode }) {
  return (
    <p role="status" className="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 ring-1 ring-emerald-200">{children}</p>
  );
}
