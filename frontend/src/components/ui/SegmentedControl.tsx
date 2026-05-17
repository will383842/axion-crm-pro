import { cn } from './cn';

export interface SegOption<T extends string> { id: T; label: string; icon?: React.ReactNode }

export function SegmentedControl<T extends string>({
  options,
  value,
  onChange,
  variant = 'solid',
  size = 'md',
  className,
}: {
  options: Array<SegOption<T>>;
  value: T;
  onChange: (v: T) => void;
  variant?: 'solid' | 'ghost';
  size?: 'sm' | 'md';
  className?: string;
}) {
  const sizeCls = size === 'sm' ? 'rounded-md px-2 py-1 text-xs' : 'rounded-full px-3.5 py-1.5 text-sm';
  return (
    <div
      role="tablist"
      className={cn(
        variant === 'solid'
          ? 'inline-flex items-center gap-1 rounded-full bg-slate-100 p-1 shadow-inner ring-1 ring-slate-200/60 dark:bg-slate-800 dark:ring-slate-700'
          : 'inline-flex items-center gap-1 rounded-lg bg-white p-0.5 ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800',
        className,
      )}
    >
      {options.map((o) => {
        const active = o.id === value;
        return (
          <button
            key={o.id}
            role="tab"
            aria-selected={active}
            onClick={() => onChange(o.id)}
            className={cn(
              'inline-flex items-center gap-1.5 font-medium transition',
              sizeCls,
              variant === 'ghost' && 'rounded-md px-2.5 py-1 text-xs',
              active
                ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200 dark:bg-slate-700 dark:text-white dark:ring-slate-600'
                : 'text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white',
            )}
          >
            {o.icon}
            {o.label}
          </button>
        );
      })}
    </div>
  );
}
