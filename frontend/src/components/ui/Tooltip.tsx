import type { ReactNode } from 'react';
import { cloneElement, isValidElement, useId, useState } from 'react';
import { cn } from './cn';

/**
 * Tooltip minimaliste (zéro lib). Pour features simples : titre + un cours texte.
 * Accessibilité : utilise aria-describedby + le tooltip est visible sur focus aussi.
 */
export function Tooltip({
  content,
  side = 'top',
  children,
  delayMs = 200,
}: {
  content: ReactNode;
  side?: 'top' | 'bottom' | 'left' | 'right';
  children: React.ReactElement;
  delayMs?: number;
}) {
  const id = useId();
  const [open, setOpen] = useState(false);
  const [timer, setTimer] = useState<number | null>(null);

  const show = () => {
    if (timer) window.clearTimeout(timer);
    setTimer(window.setTimeout(() => setOpen(true), delayMs));
  };
  const hide = () => {
    if (timer) window.clearTimeout(timer);
    setOpen(false);
  };

  const trigger = isValidElement(children)
    ? cloneElement(children as React.ReactElement<Record<string, unknown>>, {
        onMouseEnter: show,
        onMouseLeave: hide,
        onFocus: show,
        onBlur: hide,
        'aria-describedby': open ? id : undefined,
      })
    : children;

  const pos: Record<typeof side, string> = {
    top: 'bottom-full left-1/2 -translate-x-1/2 mb-1.5',
    bottom: 'top-full left-1/2 -translate-x-1/2 mt-1.5',
    left: 'right-full top-1/2 -translate-y-1/2 mr-1.5',
    right: 'left-full top-1/2 -translate-y-1/2 ml-1.5',
  };

  return (
    <span className="relative inline-flex">
      {trigger}
      {open ? (
        <span
          id={id}
          role="tooltip"
          className={cn(
            'pointer-events-none absolute z-50 whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-[11px] font-medium text-white shadow-lg ring-1 ring-white/10',
            'axion-fade-in',
            pos[side],
          )}
        >
          {content}
        </span>
      ) : null}
    </span>
  );
}
