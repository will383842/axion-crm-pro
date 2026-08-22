// `MouseEvent` est ALIASÉ : sans cela il masquerait le `MouseEvent` du DOM, dont
// dépend le `document.addEventListener('mousedown', …)` de fermeture ci-dessous.
import type { MouseEvent as EvenementSouris, ReactElement, ReactNode } from 'react';
import { cloneElement, useEffect, useRef, useState } from 'react';
import { cn } from './cn';

/**
 * D28-004 — CE QUE `DropdownMenu` ATTEND DE SON DÉCLENCHEUR.
 *
 * Le composant enveloppait son `trigger` dans SON PROPRE `<button>`. Mesure du
 * 2026-08-22 : cinq des sept appelants lui passaient déjà un `<button>` —
 * `AudiencesListPage`, `CampaignsListPage`, `CompanyDetailPage`, `CompanyRow`
 * et `ScraperRunsPage` (ces trois derniers via `IconButton`). Un bouton dans un
 * bouton est du HTML invalide : le déclencheur extérieur perd son nom
 * accessible (celui de l'`aria-label` intérieur ne remonte pas) et axe relève
 * `nested-interactive`.
 *
 * Le composant CLONE désormais son déclencheur au lieu de l'envelopper : les
 * cinq sites se ferment d'un seul geste, et le nom accessible reste là où il a
 * toujours été, sur l'élément que l'appelant écrit.
 *
 * ⚠️ CONTRAT QUI EN DÉCOULE : le `trigger` doit être un élément FOCALISABLE au
 * clavier (`<button>`, `<a href>`). Le wrapper disparu ne fournit plus la
 * focalisation, donc un `<span>` produirait un menu inatteignable au clavier —
 * c'est pourquoi `UserMenu` et `WorkspaceSelector`, qui passaient un `<span>`,
 * ont été passés en `<button>`. Garde : `tests/components/declencheur-menu.test.tsx`.
 */
interface ProprietesDeclencheur {
  onClick?: (event: EvenementSouris<HTMLElement>) => void;
  'aria-haspopup'?: 'menu';
  'aria-expanded'?: boolean;
}

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

  const declencheur = trigger as ReactElement<ProprietesDeclencheur>;
  const declencheurCable = cloneElement(declencheur, {
    // Le `onClick` de l'appelant est COMPOSÉ, jamais écrasé : `CompanyRow` y
    // pose un `stopPropagation()` sans lequel ouvrir le menu déclencherait
    // aussi la navigation de la ligne.
    onClick: (event: EvenementSouris<HTMLElement>) => {
      declencheur.props.onClick?.(event);
      setOpen((v) => !v);
    },
    'aria-haspopup': 'menu',
    'aria-expanded': open,
  });

  return (
    <div ref={wrapRef} className={cn('relative inline-block', className)}>
      {declencheurCable}
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
