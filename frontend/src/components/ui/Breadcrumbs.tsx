import type { ReactNode } from 'react';
import { Link } from '@tanstack/react-router';
import { cn } from './cn';

export interface Crumb { label: string; to?: string; icon?: ReactNode }

/**
 * `tone` — ce composant sert sur DEUX fonds : clair (en-tête de page, fiches)
 * et sombre (barre supérieure de l'application, désormais bleu profond). On ne
 * change donc pas ses couleurs de base : on déclare le fond sur lequel il
 * vit. Une inversion « devinée » par le composant aurait été fausse dès le
 * troisième usage.
 */
export function Breadcrumbs({
  items,
  className,
  tone = 'default',
}: {
  items: Crumb[];
  className?: string;
  tone?: 'default' | 'inverse';
}) {
  const inverse = tone === 'inverse';

  return (
    <nav
      aria-label="Fil d'Ariane"
      className={cn(
        'flex items-center gap-1 text-xs',
        inverse ? 'text-sidebar-fg-muted' : 'text-slate-500 dark:text-slate-400',
        className,
      )}
    >
      {items.map((c, i) => {
        const last = i === items.length - 1;
        return (
          <div key={`${c.label}-${i}`} className="flex items-center gap-1">
            {c.to && !last ? (
              <Link
                to={c.to}
                className={cn(
                  'inline-flex items-center gap-1 rounded-md px-1.5 py-0.5',
                  inverse
                    ? 'hover:bg-white/10 hover:text-white'
                    : 'hover:bg-slate-100 hover:text-slate-900 dark:hover:bg-slate-800 dark:hover:text-white',
                )}
              >
                {c.icon}
                {c.label}
              </Link>
            ) : (
              <span
                className={cn(
                  'inline-flex items-center gap-1 px-1.5 py-0.5',
                  last && 'font-medium',
                  last && (inverse ? 'text-white' : 'text-slate-900 dark:text-white'),
                )}
              >
                {c.icon}
                {c.label}
              </span>
            )}
            {!last ? (
              <span aria-hidden className={inverse ? 'text-white/30' : 'text-slate-300 dark:text-slate-600'}>
                /
              </span>
            ) : null}
          </div>
        );
      })}
    </nav>
  );
}
