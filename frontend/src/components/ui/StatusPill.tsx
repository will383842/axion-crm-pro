import { cn } from './cn';

export type StatusTone =
  | 'neutral' | 'success' | 'warning' | 'danger' | 'info' | 'pending' | 'running';

export interface StatusPillProps {
  tone?: StatusTone;
  pulse?: boolean;
  children: React.ReactNode;
  className?: string;
}

const TONES: Record<StatusTone, { bg: string; text: string; dot: string }> = {
  neutral: { bg: 'bg-slate-100 dark:bg-slate-800',     text: 'text-slate-700 dark:text-slate-300', dot: 'bg-slate-400' },
  success: { bg: 'bg-emerald-50 dark:bg-emerald-950/40', text: 'text-emerald-700 dark:text-emerald-300', dot: 'bg-emerald-500' },
  warning: { bg: 'bg-amber-50 dark:bg-amber-950/40',   text: 'text-amber-700 dark:text-amber-300', dot: 'bg-amber-500' },
  danger:  { bg: 'bg-rose-50 dark:bg-rose-950/40',     text: 'text-rose-700 dark:text-rose-300',   dot: 'bg-rose-500' },
  info:    { bg: 'bg-sky-50 dark:bg-sky-950/40',       text: 'text-sky-700 dark:text-sky-300',     dot: 'bg-sky-500' },
  pending: { bg: 'bg-slate-100 dark:bg-slate-800',     text: 'text-slate-600 dark:text-slate-400', dot: 'bg-slate-400' },
  running: { bg: 'bg-sky-50 dark:bg-sky-950/40',       text: 'text-sky-700 dark:text-sky-300',     dot: 'bg-sky-500' },
};

export function StatusPill({ tone = 'neutral', pulse, children, className }: StatusPillProps) {
  const t = TONES[tone];
  return (
    <span className={cn('inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium', t.bg, t.text, className)}>
      <span className={cn('inline-block h-1.5 w-1.5 rounded-full', t.dot, pulse && 'axion-pulse-dot')} aria-hidden />
      {children}
    </span>
  );
}

/** Pratique : mappe les statuts CRM vers le bon ton. */
export function mapStatusToTone(status: string): StatusTone {
  const s = status.toLowerCase();
  if (['success', 'completed', 'done', 'enriched'].includes(s)) return 'success';
  if (['running', 'in_progress', 'processing', 'enriching'].includes(s)) return 'running';
  if (['pending', 'queued', 'waiting'].includes(s)) return 'pending';
  if (['failed', 'error', 'cancelled', 'canceled'].includes(s)) return 'danger';
  if (['warning', 'partial', 'partielle'].includes(s)) return 'warning';
  if (['info', 'new'].includes(s)) return 'info';
  return 'neutral';
}
