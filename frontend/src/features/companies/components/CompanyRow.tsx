import { Link } from '@tanstack/react-router';
import { Avatar, DropdownMenu, IconButton, QualityBadge, SizeCategoryBadge, cn } from '@/components/ui';

export interface CompanyRowData {
  id: number;
  siren: string;
  denomination?: string | null;
  naf?: string | null;
  size_category?: string | null;
  city?: string | null;
  postcode?: string | null;
  quality_score?: number | null;
  priority?: string | null;
  enriched_at?: string | null;
}

export interface CompanyRowProps {
  company: CompanyRowData;
  onEnrich?: (id: number) => void;
  onExport?: (id: number) => void;
  onDelete?: (id: number) => void;
  className?: string;
}

const GRID = '2fr 110px 90px 110px 140px 1.1fr 100px 36px';

/**
 * Single virtualised row in the companies list.
 * Layout is grid-based so the sticky header in CompaniesListPage stays aligned.
 */
export function CompanyRow({ company, onEnrich, onExport, onDelete, className }: CompanyRowProps) {
  const c = company;
  const name = c.denomination ?? c.siren;

  return (
    <div
      role="row"
      className={cn(
        'grid items-center gap-3 border-b border-slate-100 px-4 py-2.5 text-sm transition-colors hover:bg-slate-50',
        'dark:border-slate-800 dark:hover:bg-slate-800/60',
        className,
      )}
      style={{ gridTemplateColumns: GRID }}
    >
      <div className="flex min-w-0 items-center gap-3">
        <Avatar name={name} size="sm" />
        <div className="min-w-0">
          <Link
            to="/companies/$companyId"
            params={{ companyId: String(c.id) }}
            className="block truncate font-medium text-slate-900 hover:text-brand-700 hover:underline dark:text-white"
          >
            {c.denomination ?? '—'}
          </Link>
          <div className="truncate text-xs text-slate-500 dark:text-slate-400">
            {c.city ?? '—'}
            {c.postcode ? <span className="ml-1 text-slate-400">({c.postcode})</span> : null}
          </div>
        </div>
      </div>

      <div className="truncate font-mono text-xs tabular-nums text-slate-600 dark:text-slate-400">{c.siren}</div>

      <div className="truncate font-mono text-xs text-slate-700 dark:text-slate-300">{c.naf ?? '—'}</div>

      <div><SizeCategoryBadge size={c.size_category} /></div>

      <div><QualityBadge score={c.quality_score} /></div>

      <div className="truncate text-slate-600 dark:text-slate-400">
        {c.city ? <span>{c.city}</span> : <span className="text-slate-400">—</span>}
        {c.postcode ? <span className="ml-1 text-xs text-slate-400">{c.postcode.slice(0, 2)}</span> : null}
      </div>

      <div className="text-xs tabular-nums text-slate-500 dark:text-slate-400">
        {c.enriched_at ? new Date(c.enriched_at).toLocaleDateString('fr-FR') : '—'}
      </div>

      <div className="flex justify-end">
        <DropdownMenu
          align="right"
          trigger={
            <IconButton
              label="Actions"
              size="sm"
              variant="ghost"
              onClick={(e) => e.stopPropagation()}
            >
              <svg viewBox="0 0 20 20" className="h-4 w-4" fill="currentColor" aria-hidden>
                <circle cx="4" cy="10" r="1.5" />
                <circle cx="10" cy="10" r="1.5" />
                <circle cx="16" cy="10" r="1.5" />
              </svg>
            </IconButton>
          }
          items={[
            {
              id: 'view',
              label: 'Voir la fiche',
              onSelect: () => {
                window.location.assign(`/companies/${c.id}`);
              },
            },
            {
              id: 'enrich',
              label: 'Enrichir maintenant',
              disabled: !onEnrich,
              onSelect: () => onEnrich?.(c.id),
            },
            {
              id: 'export',
              label: 'Exporter (vCard)',
              disabled: !onExport,
              onSelect: () => onExport?.(c.id),
            },
            { id: 'sep', label: '', divider: true },
            {
              id: 'delete',
              label: 'Supprimer',
              destructive: true,
              disabled: !onDelete,
              onSelect: () => onDelete?.(c.id),
            },
          ]}
        />
      </div>
    </div>
  );
}

export const COMPANY_ROW_GRID = GRID;
