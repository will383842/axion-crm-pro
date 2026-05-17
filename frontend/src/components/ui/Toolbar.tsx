import type { ReactNode } from 'react';
import { cn } from './cn';

export function Toolbar({ left, right, className }: { left?: ReactNode; right?: ReactNode; className?: string }) {
  return (
    <div className={cn('mb-4 flex flex-wrap items-center gap-3 rounded-xl bg-white/70 p-2 ring-1 ring-slate-200/60 backdrop-blur-sm dark:bg-slate-900/60 dark:ring-slate-800', className)}>
      <div className="flex flex-wrap items-center gap-2">{left}</div>
      {right ? <div className="ml-auto flex flex-wrap items-center gap-2">{right}</div> : null}
    </div>
  );
}

export function SearchInput({
  value,
  onChange,
  placeholder = 'Rechercher…',
  className,
}: {
  value: string;
  onChange: (v: string) => void;
  placeholder?: string;
  className?: string;
}) {
  return (
    <div className={cn('relative inline-flex w-full max-w-xs', className)}>
      <svg viewBox="0 0 20 20" className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" fill="none">
        <circle cx="9" cy="9" r="6" stroke="currentColor" strokeWidth="2" />
        <path d="M14 14l3 3" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
      </svg>
      <input
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        className="h-9 w-full rounded-lg bg-white pl-8 pr-3 text-sm text-slate-900 ring-1 ring-slate-200 transition placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:bg-slate-900 dark:text-white dark:ring-slate-700 dark:focus:ring-slate-600"
      />
    </div>
  );
}
