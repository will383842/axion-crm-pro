import type { HTMLAttributes, ReactNode } from 'react';
import { cn } from './cn';

export interface CardProps extends HTMLAttributes<HTMLDivElement> {
  variant?: 'default' | 'glass' | 'flat' | 'outline';
  hover?: boolean;
  padding?: 'none' | 'sm' | 'md' | 'lg';
}

const VARIANTS = {
  default: 'bg-white ring-1 ring-slate-200/70 shadow-[var(--shadow-card)] dark:bg-slate-900 dark:ring-slate-800',
  glass:   'bg-white/80 backdrop-blur-sm ring-1 ring-slate-200/60 shadow-[var(--shadow-card)] dark:bg-slate-900/80 dark:ring-slate-800/70',
  flat:    'bg-slate-50 ring-1 ring-slate-100 dark:bg-slate-800/60 dark:ring-slate-800',
  outline: 'bg-transparent ring-1 ring-dashed ring-slate-300 dark:ring-slate-700',
};

const PADS = { none: '', sm: 'p-3', md: 'p-5', lg: 'p-6' };

export function Card({ variant = 'default', hover, padding = 'md', className, ...rest }: CardProps) {
  return (
    <div
      className={cn(
        'rounded-2xl transition-shadow',
        VARIANTS[variant],
        hover && 'hover:shadow-[var(--shadow-card-hover)] hover:-translate-y-0.5 transition-transform',
        PADS[padding],
        className,
      )}
      {...rest}
    />
  );
}

export function CardHeader({ className, children }: { className?: string; children?: ReactNode }) {
  return <div className={cn('mb-3 flex items-start justify-between gap-2', className)}>{children}</div>;
}

export function CardTitle({ className, children }: { className?: string; children?: ReactNode }) {
  return <h3 className={cn('text-sm font-semibold tracking-tight text-slate-900 dark:text-white', className)}>{children}</h3>;
}

export function CardEyebrow({ className, children }: { className?: string; children?: ReactNode }) {
  return (
    <div className={cn('text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400', className)}>
      {children}
    </div>
  );
}

export function CardFooter({ className, children }: { className?: string; children?: ReactNode }) {
  return <div className={cn('mt-4 flex items-center justify-between gap-2', className)}>{children}</div>;
}
