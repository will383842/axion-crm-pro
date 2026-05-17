import type { ReactNode } from 'react';
import { Link } from '@tanstack/react-router';
import { cn } from './cn';

export interface Crumb { label: string; to?: string; icon?: ReactNode }

export function Breadcrumbs({ items, className }: { items: Crumb[]; className?: string }) {
  return (
    <nav aria-label="Fil d'Ariane" className={cn('flex items-center gap-1 text-xs text-slate-500 dark:text-slate-400', className)}>
      {items.map((c, i) => {
        const last = i === items.length - 1;
        return (
          <div key={`${c.label}-${i}`} className="flex items-center gap-1">
            {c.to && !last ? (
              <Link to={c.to} className="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 hover:bg-slate-100 hover:text-slate-900 dark:hover:bg-slate-800 dark:hover:text-white">
                {c.icon}
                {c.label}
              </Link>
            ) : (
              <span className={cn('inline-flex items-center gap-1 px-1.5 py-0.5', last && 'text-slate-900 font-medium dark:text-white')}>
                {c.icon}
                {c.label}
              </span>
            )}
            {!last ? <span aria-hidden className="text-slate-300 dark:text-slate-600">/</span> : null}
          </div>
        );
      })}
    </nav>
  );
}
