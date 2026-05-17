import { Button, cn } from '@/components/ui';

export interface PaginationProps {
  page: number;
  lastPage: number;
  total?: number | undefined;
  onChange: (page: number) => void;
  className?: string | undefined;
}

/** Compact page list à la "1 2 3 … 125". */
function buildPages(page: number, last: number): Array<number | 'gap'> {
  if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1);
  const pages: Array<number | 'gap'> = [1];
  const start = Math.max(2, page - 1);
  const end = Math.min(last - 1, page + 1);
  if (start > 2) pages.push('gap');
  for (let i = start; i <= end; i++) pages.push(i);
  if (end < last - 1) pages.push('gap');
  pages.push(last);
  return pages;
}

export function Pagination({ page, lastPage, total, onChange, className }: PaginationProps) {
  if (lastPage <= 1) return null;
  const pages = buildPages(page, lastPage);

  return (
    <nav
      className={cn('mt-4 flex flex-wrap items-center justify-between gap-3 text-sm', className)}
      aria-label="Pagination"
    >
      <div className="text-xs text-slate-500 dark:text-slate-400">
        Page <span className="font-semibold tabular-nums text-slate-700 dark:text-slate-200">{page}</span> / {lastPage}
        {typeof total === 'number' ? <> · <span className="tabular-nums">{total.toLocaleString('fr-FR')}</span> au total</> : null}
      </div>

      <div className="flex items-center gap-1">
        <Button
          variant="secondary"
          size="sm"
          disabled={page <= 1}
          onClick={() => onChange(Math.max(1, page - 1))}
          aria-label="Page précédente"
        >
          ← Précédent
        </Button>
        <div className="mx-1 hidden items-center gap-0.5 md:flex">
          {pages.map((p, i) =>
            p === 'gap' ? (
              <span key={`gap-${i}`} className="px-1.5 text-slate-400">…</span>
            ) : (
              <button
                key={p}
                onClick={() => onChange(p)}
                aria-current={p === page ? 'page' : undefined}
                className={cn(
                  'inline-flex h-8 min-w-8 items-center justify-center rounded-lg px-2 text-xs font-medium tabular-nums transition',
                  p === page
                    ? 'bg-slate-900 text-white shadow-sm dark:bg-white dark:text-slate-900'
                    : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800',
                )}
              >
                {p}
              </button>
            ),
          )}
        </div>
        <Button
          variant="secondary"
          size="sm"
          disabled={page >= lastPage}
          onClick={() => onChange(Math.min(lastPage, page + 1))}
          aria-label="Page suivante"
        >
          Suivant →
        </Button>
      </div>
    </nav>
  );
}
