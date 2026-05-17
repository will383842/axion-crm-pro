import type { ReactNode } from 'react';
import { cn } from './cn';

export type KpiTone = 'sky' | 'violet' | 'emerald' | 'amber' | 'rose' | 'slate';

export interface KpiCardProps {
  label: string;
  value: string | number;
  sublabel?: string;
  tone?: KpiTone;
  progress?: number;
  icon?: ReactNode;
  trend?: { value: number; label?: string; direction?: 'up' | 'down' };
  className?: string;
}

const TONE_MAP: Record<KpiTone, { ring: string; chip: string; bar: string; trendUp: string; trendDown: string }> = {
  sky: {
    ring: 'ring-sky-200/60 dark:ring-sky-900/40',
    chip: 'bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300',
    bar: 'from-sky-500 to-blue-600',
    trendUp: 'text-emerald-600 dark:text-emerald-400',
    trendDown: 'text-rose-600 dark:text-rose-400',
  },
  violet: {
    ring: 'ring-violet-200/60 dark:ring-violet-900/40',
    chip: 'bg-violet-50 text-violet-700 dark:bg-violet-950/40 dark:text-violet-300',
    bar: 'from-violet-500 to-fuchsia-600',
    trendUp: 'text-emerald-600 dark:text-emerald-400',
    trendDown: 'text-rose-600 dark:text-rose-400',
  },
  emerald: {
    ring: 'ring-emerald-200/60 dark:ring-emerald-900/40',
    chip: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
    bar: 'from-emerald-500 to-teal-600',
    trendUp: 'text-emerald-600 dark:text-emerald-400',
    trendDown: 'text-rose-600 dark:text-rose-400',
  },
  amber: {
    ring: 'ring-amber-200/60 dark:ring-amber-900/40',
    chip: 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
    bar: 'from-amber-500 to-orange-600',
    trendUp: 'text-emerald-600 dark:text-emerald-400',
    trendDown: 'text-rose-600 dark:text-rose-400',
  },
  rose: {
    ring: 'ring-rose-200/60 dark:ring-rose-900/40',
    chip: 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300',
    bar: 'from-rose-500 to-pink-600',
    trendUp: 'text-emerald-600 dark:text-emerald-400',
    trendDown: 'text-rose-600 dark:text-rose-400',
  },
  slate: {
    ring: 'ring-slate-200/60 dark:ring-slate-700/60',
    chip: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    bar: 'from-slate-500 to-slate-700',
    trendUp: 'text-emerald-600 dark:text-emerald-400',
    trendDown: 'text-rose-600 dark:text-rose-400',
  },
};

export function KpiCard({ label, value, sublabel, tone = 'slate', progress, icon, trend, className }: KpiCardProps) {
  const t = TONE_MAP[tone];
  return (
    <div
      className={cn(
        'group relative overflow-hidden rounded-2xl bg-white/80 p-4 backdrop-blur-sm',
        'ring-1 shadow-[var(--shadow-card)] dark:bg-slate-900/60',
        'transition-[transform,box-shadow] hover:-translate-y-0.5 hover:shadow-[var(--shadow-card-hover)]',
        t.ring,
        className,
      )}
    >
      <div className="mb-2 flex items-center justify-between gap-2">
        <span className={cn('inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider', t.chip)}>
          {label}
        </span>
        {icon ? <span className="text-slate-400 dark:text-slate-500">{icon}</span> : null}
      </div>
      <div className="flex items-baseline gap-2">
        <div className="text-2xl font-semibold tracking-tight tabular-nums text-slate-900 dark:text-white">
          {value}
        </div>
        {trend ? (
          <div className={cn('text-xs font-semibold tabular-nums', trend.direction === 'down' ? t.trendDown : t.trendUp)}>
            {trend.direction === 'down' ? '↓' : '↑'} {trend.value}%
            {trend.label ? <span className="ml-1 font-normal text-slate-500 dark:text-slate-400">{trend.label}</span> : null}
          </div>
        ) : null}
      </div>
      {sublabel ? <div className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{sublabel}</div> : null}
      {typeof progress === 'number' ? (
        <div className="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
          <div
            className={cn('h-full rounded-full bg-gradient-to-r transition-[width] duration-500', t.bar)}
            style={{ width: `${Math.max(2, Math.min(100, progress))}%` }}
          />
        </div>
      ) : null}
    </div>
  );
}
