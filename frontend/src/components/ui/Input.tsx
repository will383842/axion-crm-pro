import type { InputHTMLAttributes, ReactNode } from 'react';
import { forwardRef } from 'react';
import { cn } from './cn';

export interface InputProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'prefix'> {
  iconLeft?: ReactNode;
  iconRight?: ReactNode;
  invalid?: boolean;
}

export const Input = forwardRef<HTMLInputElement, InputProps>(function Input(
  { iconLeft, iconRight, invalid, className, ...rest },
  ref,
) {
  return (
    <div className={cn('relative inline-flex w-full', className)}>
      {iconLeft ? (
        <span className="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400">{iconLeft}</span>
      ) : null}
      <input
        ref={ref}
        className={cn(
          'h-9 w-full rounded-lg bg-white text-sm text-slate-900 ring-1 transition placeholder:text-slate-400',
          'focus:outline-none focus:ring-2',
          'dark:bg-slate-900 dark:text-white',
          iconLeft ? 'pl-8' : 'pl-3',
          iconRight ? 'pr-8' : 'pr-3',
          invalid
            ? 'ring-rose-300 focus:ring-rose-400 dark:ring-rose-800'
            : 'ring-slate-200 focus:ring-slate-300 dark:ring-slate-700 dark:focus:ring-slate-600',
        )}
        {...rest}
      />
      {iconRight ? (
        <span className="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400">{iconRight}</span>
      ) : null}
    </div>
  );
});
