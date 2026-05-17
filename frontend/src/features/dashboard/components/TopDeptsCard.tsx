import { useQuery } from '@tanstack/react-query';
import { Card, CardHeader, CardTitle, CardEyebrow, EmptyState, cn } from '@/components/ui';
import { api } from '@/lib/api';

interface CoverageCell {
  code: string;
  name: string;
  total: number;
  complete?: number;
  partial?: number;
}

export function TopDeptsCard() {
  // Réutilise l'endpoint /coverage déjà disponible (CoveragePage).
  // Si l'endpoint renvoie 404/500 ou rien, on tombe sur EmptyState.
  const { data, isLoading } = useQuery({
    queryKey: ['dashboard-top-depts'],
    queryFn: async () => {
      const r = await api.get<{ cells: CoverageCell[] }>('/coverage', { params: { level: 'department' } });
      return r.data.cells ?? [];
    },
    staleTime: 60_000,
    retry: false,
  });

  const top = (data ?? [])
    .filter((c) => (c.total ?? 0) > 0)
    .sort((a, b) => (b.total ?? 0) - (a.total ?? 0))
    .slice(0, 5);

  const max = Math.max(1, ...top.map((c) => c.total ?? 0));

  return (
    <Card>
      <CardHeader>
        <div className="min-w-0">
          <CardEyebrow>Couverture</CardEyebrow>
          <CardTitle>Top 5 départements</CardTitle>
        </div>
      </CardHeader>

      {isLoading ? (
        <ul className="space-y-2" aria-busy="true">
          {Array.from({ length: 5 }).map((_, i) => (
            <li key={i} className="h-9 animate-pulse rounded-lg bg-slate-100 dark:bg-slate-800" />
          ))}
        </ul>
      ) : top.length === 0 ? (
        <EmptyState
          title="Aucun département couvert"
          description="Lance un scrape pour commencer à couvrir la France."
          icon="🗺️"
        />
      ) : (
        <ul className="space-y-2">
          {top.map((cell, idx) => {
            const pct = (cell.total / max) * 100;
            return (
              <li
                key={cell.code}
                className="group flex items-center gap-3 rounded-lg px-2 py-1.5 transition-colors hover:bg-slate-50 dark:hover:bg-slate-800/60"
              >
                {/* Rang */}
                <span className="w-5 text-center text-xs font-semibold tabular-nums text-slate-400">
                  {idx + 1}
                </span>

                {/* Code dépt en chip mono */}
                <span className="inline-flex h-6 min-w-[2rem] items-center justify-center rounded-md bg-slate-100 px-1.5 font-mono text-[11px] font-semibold text-slate-700 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700">
                  {cell.code}
                </span>

                {/* Nom + mini-bar */}
                <div className="min-w-0 flex-1">
                  <div className="truncate text-xs font-medium text-slate-700 dark:text-slate-300" title={cell.name}>
                    {cell.name || `Département ${cell.code}`}
                  </div>
                  <div className="mt-1 h-1 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                    <div
                      className={cn(
                        'h-full rounded-full bg-gradient-to-r transition-[width] duration-500',
                        idx === 0 ? 'from-sky-500 to-blue-500'
                          : idx === 1 ? 'from-violet-500 to-fuchsia-500'
                          : idx === 2 ? 'from-emerald-500 to-teal-500'
                          : 'from-slate-400 to-slate-500',
                      )}
                      style={{ width: `${pct}%` }}
                    />
                  </div>
                </div>

                {/* Total */}
                <span className="shrink-0 text-sm font-semibold tabular-nums text-slate-900 dark:text-white">
                  {cell.total.toLocaleString('fr-FR')}
                </span>
              </li>
            );
          })}
        </ul>
      )}
    </Card>
  );
}
