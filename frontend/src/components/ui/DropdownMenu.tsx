import type { ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';
import { cn } from './cn';

export interface MenuItem {
  id: string;
  label: string;
  icon?: ReactNode;
  onSelect?: () => void;
  destructive?: boolean;
  disabled?: boolean;
  divider?: boolean;
}

export function DropdownMenu({
  trigger,
  items,
  align = 'right',
  className,
}: {
  trigger: React.ReactElement;
  items: MenuItem[];
  align?: 'left' | 'right';
  className?: string;
}) {
  const [open, setOpen] = useState(false);
  const wrapRef = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    if (!open) return;
    const onClick = (e: MouseEvent) => {
      if (wrapRef.current && !wrapRef.current.contains(e.target as Node)) setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false);
    };
    document.addEventListener('mousedown', onClick);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onClick);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  return (
    <div ref={wrapRef} className={cn('relative inline-block', className)}>
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-haspopup="menu"
        aria-expanded={open}
        className="inline-flex"
      >
        {trigger}
      </button>
      {open ? (
        <div
          role="menu"
          className={cn(
            'absolute z-40 mt-1.5 min-w-[180px] rounded-xl bg-white p-1 shadow-[var(--shadow-popover)] ring-1 ring-slate-200',
            'dark:bg-slate-900 dark:ring-slate-800 axion-slide-up',
            align === 'right' ? 'right-0' : 'left-0',
          )}
        >
          {items.map((it) =>
            it.divider ? (
              <div key={it.id} className="my-1 h-px bg-slate-100 dark:bg-slate-800" />
            ) : (
              <button
                key={it.id}
                role="menuitem"
                disabled={it.disabled}
                onClick={() => {
                  it.onSelect?.();
                  setOpen(false);
                }}
                className={cn(
                  'flex w-full items-center gap-2 rounded-lg px-2.5 py-1.5 text-left text-sm transition',
                  it.destructive
                    ? 'text-rose-600 hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-950/40'
                    : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white',
                  it.disabled && 'opacity-50 pointer-events-none',
                )}
              >
                {it.icon ? <span className="text-slate-400">{it.icon}</span> : null}
                <span className="flex-1">{it.label}</span>
              </button>
            ),
          )}
        </div>
      ) : null}
    </div>
  );
}
