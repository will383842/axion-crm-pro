import type { ButtonHTMLAttributes, ReactNode } from 'react';
import { forwardRef } from 'react';
import { cn } from './cn';

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'destructive' | 'subtle';
export type ButtonSize = 'sm' | 'md' | 'lg';

export interface ButtonProps extends Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'prefix'> {
  variant?: ButtonVariant;
  size?: ButtonSize;
  loading?: boolean;
  iconLeft?: ReactNode;
  iconRight?: ReactNode;
  full?: boolean;
}

const VARIANTS: Record<ButtonVariant, string> = {
  // DM0-COQ (ADR-13) — le SEUL bouton plein de l'écran prend la couleur du
  // produit courant (`data-produit` de la coquille) : bleu de marque dans le
  // CRM et les écrans communs, turquoise pétrole dans Envoi. Écart assumé et
  // validé dans les maquettes M2 : il était ardoise avant DM0-COQ.
  primary:
    'bg-produit text-white shadow-produit ' +
    'hover:bg-produit-fonce active:scale-[0.98] focus-visible:ring-produit',
  secondary:
    'bg-white text-slate-900 ring-1 ring-slate-200 shadow-sm ' +
    'hover:bg-slate-50 hover:ring-slate-300 active:scale-[0.98] focus-visible:ring-slate-400 ' +
    'dark:bg-slate-800 dark:text-white dark:ring-slate-700 dark:hover:bg-slate-700',
  ghost:
    'bg-transparent text-slate-700 hover:bg-slate-100 hover:text-slate-900 ' +
    'focus-visible:ring-slate-300 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white',
  subtle:
    'bg-slate-100 text-slate-900 hover:bg-slate-200 active:scale-[0.98] focus-visible:ring-slate-300 ' +
    'dark:bg-slate-800 dark:text-white dark:hover:bg-slate-700',
  destructive:
    'bg-gradient-to-b from-rose-600 to-rose-700 text-white shadow-sm shadow-rose-900/10 ' +
    'hover:from-rose-500 hover:to-rose-600 active:scale-[0.98] focus-visible:ring-rose-500',
};

const SIZES: Record<ButtonSize, string> = {
  sm: 'h-8 px-3 text-xs gap-1.5 rounded-lg',
  md: 'h-9 px-4 text-sm gap-2 rounded-lg',
  lg: 'h-11 px-5 text-sm gap-2 rounded-xl font-semibold',
};

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { variant = 'primary', size = 'md', loading, iconLeft, iconRight, full, className, disabled, children, ...rest },
  ref,
) {
  return (
    <button
      ref={ref}
      disabled={disabled ?? loading}
      className={cn(
        'inline-flex items-center justify-center font-medium transition-[transform,background-color,box-shadow,color,opacity]',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-1',
        'disabled:opacity-50 disabled:pointer-events-none',
        VARIANTS[variant],
        SIZES[size],
        full && 'w-full',
        className,
      )}
      {...rest}
    >
      {loading ? <Spinner /> : iconLeft}
      {children}
      {!loading && iconRight}
    </button>
  );
});

function Spinner() {
  return (
    <svg viewBox="0 0 24 24" className="h-3.5 w-3.5 animate-spin" fill="none" aria-hidden>
      <circle cx="12" cy="12" r="10" stroke="currentColor" strokeOpacity="0.25" strokeWidth="3" />
      <path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
    </svg>
  );
}
