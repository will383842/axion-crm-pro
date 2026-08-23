import type { ReactNode } from 'react';
import { cn } from './cn';
import { Breadcrumbs, type Crumb } from './Breadcrumbs';

export interface PageHeaderProps {
  title: ReactNode;
  subtitle?: ReactNode;
  eyebrow?: ReactNode;
  badge?: ReactNode;
  actions?: ReactNode;
  breadcrumbs?: Crumb[];
  gradient?: boolean;
  className?: string;
}

export function PageHeader({ title, subtitle, eyebrow, badge, actions, breadcrumbs, gradient = true, className }: PageHeaderProps) {
  return (
    <header className={cn('mb-6 flex flex-wrap items-end justify-between gap-4', className)}>
      <div className="min-w-0 flex-1">
        {breadcrumbs && breadcrumbs.length > 0 ? <Breadcrumbs items={breadcrumbs} className="mb-2" /> : null}
        {badge ? <div className="mb-2">{badge}</div> : null}
        {eyebrow ? (
          <div className="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{eyebrow}</div>
        ) : null}
        <h1
          className={cn(
            'text-2xl font-semibold tracking-tight md:text-3xl',
            gradient
              ? 'bg-gradient-to-br from-slate-900 to-slate-600 bg-clip-text text-transparent dark:from-white dark:to-slate-300'
              : 'text-slate-900 dark:text-white',
          )}
        >
          {title}
        </h1>
        {subtitle ? <p className="mt-1 max-w-3xl text-sm text-slate-500 dark:text-slate-400">{subtitle}</p> : null}
      </div>
      {/*
        D30-008 — ce bloc portait `shrink-0` ET `flex-wrap`. Les deux ne peuvent
        pas coexister : `shrink-0` fige la largeur du bloc à `max-content`, ses
        enfants n'y rencontrent donc JAMAIS de contrainte de largeur, et le
        `flex-wrap` du même élément ne peut structurellement jamais s'exercer.
        Le repli était écrit et ne pouvait pas se produire — sur les 27 écrans
        qui montent `PageHeader`. `min-w-0` remplace `shrink-0` : le bloc peut
        se comprimer, et c'est cette compression qui déclenche le repli.
      */}
      {actions ? <div className="flex min-w-0 flex-wrap items-center gap-2">{actions}</div> : null}
    </header>
  );
}

/** Petit badge "live" avec dot pulsé — pratique pour pages temps-réel. */
export function LiveBadge({ label = 'En direct', refreshLabel }: { label?: string; refreshLabel?: string }) {
  return (
    <span className="inline-flex items-center gap-1.5 rounded-full bg-sky-50 px-2 py-0.5 text-[11px] font-medium text-sky-700 ring-1 ring-sky-200 dark:bg-sky-950/40 dark:text-sky-300 dark:ring-sky-900/50">
      <span className="h-1.5 w-1.5 rounded-full bg-sky-500 axion-pulse-dot" />
      {label}
      {refreshLabel ? <span className="text-sky-600/70 dark:text-sky-300/70">· {refreshLabel}</span> : null}
    </span>
  );
}
