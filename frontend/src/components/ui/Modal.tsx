import type { ReactNode } from 'react';
import { useEffect, useRef } from 'react';
import { cn } from './cn';
import { IconButton } from './IconButton';

export interface ModalProps {
  open: boolean;
  onClose: () => void;
  title?: ReactNode;
  description?: ReactNode;
  size?: 'sm' | 'md' | 'lg' | 'xl';
  children?: ReactNode;
  footer?: ReactNode;
  closeOnOverlay?: boolean;
}

const SIZES = {
  sm: 'max-w-sm',
  md: 'max-w-md',
  lg: 'max-w-2xl',
  xl: 'max-w-4xl',
};

export function Modal({ open, onClose, title, description, size = 'md', children, footer, closeOnOverlay = true }: ModalProps) {
  const ref = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', onKey);
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = '';
    };
  }, [open, onClose]);

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 axion-fade-in" role="dialog" aria-modal="true">
      <div
        className="absolute inset-0 bg-slate-950/40 backdrop-blur-sm"
        onClick={() => closeOnOverlay && onClose()}
        aria-hidden
      />
      <div
        ref={ref}
        className={cn(
          'relative w-full rounded-2xl bg-white p-6 shadow-[var(--shadow-popover)] ring-1 ring-slate-200',
          'dark:bg-slate-900 dark:ring-slate-800',
          'axion-slide-up',
          SIZES[size],
        )}
      >
        <div className="mb-2 flex items-start justify-between gap-3">
          <div>
            {title ? <h2 className="text-lg font-semibold tracking-tight text-slate-900 dark:text-white">{title}</h2> : null}
            {description ? <p className="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{description}</p> : null}
          </div>
          <IconButton label="Fermer" onClick={onClose} size="sm">
            <svg viewBox="0 0 20 20" className="h-4 w-4"><path d="M6 6l8 8M14 6l-8 8" stroke="currentColor" strokeWidth="2" strokeLinecap="round" /></svg>
          </IconButton>
        </div>
        <div className="mt-3">{children}</div>
        {footer ? <div className="mt-6 flex items-center justify-end gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">{footer}</div> : null}
      </div>
    </div>
  );
}

/** Drawer latéral droit — utile pour détails contextuels (row click → drawer). */
export function Drawer({
  open,
  onClose,
  title,
  width = 'md',
  children,
  footer,
}: {
  open: boolean;
  onClose: () => void;
  title?: ReactNode;
  width?: 'sm' | 'md' | 'lg';
  children?: ReactNode;
  footer?: ReactNode;
}) {
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', onKey);
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = '';
    };
  }, [open, onClose]);

  if (!open) return null;

  const w = width === 'sm' ? 'max-w-sm' : width === 'lg' ? 'max-w-2xl' : 'max-w-lg';

  return (
    <div className="fixed inset-0 z-50 flex justify-end axion-fade-in" role="dialog" aria-modal="true">
      <div className="absolute inset-0 bg-slate-950/40 backdrop-blur-sm" onClick={onClose} aria-hidden />
      <div
        className={cn(
          'relative h-full w-full overflow-y-auto bg-white shadow-[var(--shadow-popover)] ring-1 ring-slate-200',
          'dark:bg-slate-900 dark:ring-slate-800',
          'axion-slide-in-right',
          w,
        )}
      >
        <div className="sticky top-0 z-10 flex items-center justify-between border-b border-slate-100 bg-white/95 px-6 py-4 backdrop-blur dark:border-slate-800 dark:bg-slate-900/95">
          {title ? <h2 className="text-base font-semibold tracking-tight text-slate-900 dark:text-white">{title}</h2> : <span />}
          <IconButton label="Fermer" onClick={onClose}>
            <svg viewBox="0 0 20 20" className="h-4 w-4"><path d="M6 6l8 8M14 6l-8 8" stroke="currentColor" strokeWidth="2" strokeLinecap="round" /></svg>
          </IconButton>
        </div>
        <div className="px-6 py-4">{children}</div>
        {footer ? (
          <div className="sticky bottom-0 flex items-center justify-end gap-2 border-t border-slate-100 bg-white/95 px-6 py-3 backdrop-blur dark:border-slate-800 dark:bg-slate-900/95">
            {footer}
          </div>
        ) : null}
      </div>
    </div>
  );
}
