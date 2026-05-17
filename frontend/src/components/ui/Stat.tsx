import type { ReactNode } from 'react';
import { cn } from './cn';

/** Petit bloc label/valeur réutilisable — variante card-flat. */
export function Stat({
  label,
  value,
  icon,
  className,
}: {
  label: ReactNode;
  value: ReactNode;
  icon?: ReactNode;
  className?: string;
}) {
  return (
    <div className={cn('rounded-xl bg-slate-50 p-3 ring-1 ring-slate-100 dark:bg-slate-800/60 dark:ring-slate-800', className)}>
      <div className="flex items-center gap-1.5 text-[10px] font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">
        {icon}
        {label}
      </div>
      <div className="mt-0.5 text-lg font-semibold tracking-tight tabular-nums text-slate-900 dark:text-white">
        {value}
      </div>
    </div>
  );
}
