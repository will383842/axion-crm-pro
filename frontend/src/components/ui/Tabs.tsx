import type { ReactNode } from 'react';
import { cn } from './cn';

export interface TabItem<T extends string> { id: T; label: string; count?: number; icon?: ReactNode }

export function Tabs<T extends string>({
  items,
  value,
  onChange,
  variant = 'underline',
  className,
}: {
  items: Array<TabItem<T>>;
  value: T;
  onChange: (v: T) => void;
  variant?: 'underline' | 'pills';
  className?: string;
}) {
  if (variant === 'pills') {
    return (
      <div role="tablist" className={cn('inline-flex flex-wrap items-center gap-1', className)}>
        {items.map((t) => {
          const active = t.id === value;
          return (
            <button
              key={t.id}
              role="tab"
              aria-selected={active}
              onClick={() => onChange(t.id)}
              className={cn(
                'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition',
                active
                  ? 'bg-slate-900 text-white shadow-sm dark:bg-white dark:text-slate-900'
                  : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white',
              )}
            >
              {t.icon}
              {t.label}
              {typeof t.count === 'number' ? (
                <span className={cn('ml-1 rounded-full px-1.5 py-0.5 text-[10px] font-semibold', active ? 'bg-white/20' : 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200')}>
                  {t.count}
                </span>
              ) : null}
            </button>
          );
        })}
      </div>
    );
  }

  return (
    <div role="tablist" className={cn('flex flex-wrap items-center gap-1 border-b border-slate-200 dark:border-slate-800', className)}>
      {items.map((t) => {
        const active = t.id === value;
        return (
          <button
            key={t.id}
            role="tab"
            aria-selected={active}
            onClick={() => onChange(t.id)}
            className={cn(
              'relative inline-flex items-center gap-1.5 px-3 py-2.5 text-sm font-medium transition',
              active
                ? 'text-slate-900 dark:text-white'
                : 'text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white',
            )}
          >
            {t.icon}
            {t.label}
            {typeof t.count === 'number' ? (
              <span className="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                {t.count}
              </span>
            ) : null}
            {active ? (
              <span className="absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-slate-900 dark:bg-white" aria-hidden />
            ) : null}
          </button>
        );
      })}
    </div>
  );
}
