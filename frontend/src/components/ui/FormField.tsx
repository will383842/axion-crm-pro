import { forwardRef, type InputHTMLAttributes, type ReactNode } from 'react';

export interface FormFieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'prefix' | 'suffix'> {
  label: string;
  error?: string | undefined;
  helpText?: string;
  prefix?: ReactNode;
  suffix?: ReactNode;
}

let _id = 0;
const nextId = () => `ff-${++_id}`;

export const FormField = forwardRef<HTMLInputElement, FormFieldProps>(function FormField(
  { label, error, helpText, prefix, suffix, id: providedId, className = '', ...inputProps },
  ref,
) {
  const id = providedId ?? nextId();
  const helpId = `${id}-help`;
  const errorId = `${id}-error`;
  const describedBy = [error ? errorId : null, helpText ? helpId : null].filter(Boolean).join(' ') || undefined;

  return (
    <div className="mb-3">
      <label htmlFor={id} className="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-200">
        {label}
        {inputProps.required ? <span aria-hidden className="ml-0.5 text-rose-600">*</span> : null}
      </label>
      <div className={`flex rounded-md border ${error ? 'border-rose-400 focus-within:ring-rose-300' : 'border-slate-300 focus-within:ring-brand-300'} focus-within:ring-2 dark:border-slate-600 dark:bg-slate-800`}>
        {prefix ? <span className="flex items-center px-2 text-slate-500">{prefix}</span> : null}
        <input
          id={id}
          ref={ref}
          aria-invalid={!!error}
          aria-describedby={describedBy}
          className={`min-w-0 flex-1 rounded-md border-0 bg-transparent px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-0 dark:text-slate-100 ${className}`}
          {...inputProps}
        />
        {suffix ? <span className="flex items-center px-2 text-slate-500">{suffix}</span> : null}
      </div>
      {error ? (
        <p id={errorId} role="alert" className="mt-1 text-xs text-rose-600">{error}</p>
      ) : helpText ? (
        <p id={helpId} className="mt-1 text-xs text-slate-500 dark:text-slate-400">{helpText}</p>
      ) : null}
    </div>
  );
});
