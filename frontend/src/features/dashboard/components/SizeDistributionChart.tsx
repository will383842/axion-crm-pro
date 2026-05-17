import { Card, CardHeader, CardTitle, CardEyebrow, cn } from '@/components/ui';

export type SizeDistribution = Record<string, number>;

const BUCKETS: Array<{ key: string; label: string; bar: string; ring: string }> = [
  { key: 'artisan',           label: 'Artisan', bar: 'from-sky-400 to-sky-600',          ring: 'ring-sky-200/60 dark:ring-sky-900/40' },
  { key: 'tpe',               label: 'TPE',     bar: 'from-violet-400 to-violet-600',    ring: 'ring-violet-200/60 dark:ring-violet-900/40' },
  { key: 'pme',               label: 'PME',     bar: 'from-emerald-400 to-emerald-600',  ring: 'ring-emerald-200/60 dark:ring-emerald-900/40' },
  { key: 'eti',               label: 'ETI',     bar: 'from-amber-400 to-amber-600',      ring: 'ring-amber-200/60 dark:ring-amber-900/40' },
  { key: 'grande_entreprise', label: 'Grande',  bar: 'from-rose-400 to-rose-600',        ring: 'ring-rose-200/60 dark:ring-rose-900/40' },
];

export function SizeDistributionChart({ data }: { data: SizeDistribution }) {
  const max = Math.max(1, ...BUCKETS.map((b) => data[b.key] ?? 0));
  const total = BUCKETS.reduce((s, b) => s + (data[b.key] ?? 0), 0);

  return (
    <Card>
      <CardHeader>
        <div className="min-w-0">
          <CardEyebrow>Taille d'entreprise (INSEE)</CardEyebrow>
          <CardTitle>Distribution par catégorie</CardTitle>
        </div>
        <div className="shrink-0 text-right">
          <div className="text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
            Total classé
          </div>
          <div className="text-2xl font-semibold tabular-nums text-slate-900 dark:text-white">
            {total.toLocaleString('fr-FR')}
          </div>
        </div>
      </CardHeader>

      {/* Bar chart vertical pur CSS */}
      <div className="flex h-44 items-end gap-3 px-1 pt-2">
        {BUCKETS.map((b) => {
          const v = data[b.key] ?? 0;
          const heightPct = total === 0 ? 0 : Math.max(2, Math.round((v / max) * 100));
          const sharePct = total === 0 ? 0 : (v / total) * 100;
          return (
            <div key={b.key} className="group flex flex-1 flex-col items-center gap-1.5">
              <div
                className="w-full text-center text-[10px] font-semibold tabular-nums text-slate-600 transition-opacity dark:text-slate-400"
                aria-hidden
              >
                {v.toLocaleString('fr-FR')}
              </div>
              <div
                className={cn(
                  'relative w-full overflow-hidden rounded-t-md bg-gradient-to-t ring-1',
                  b.bar,
                  b.ring,
                  'transition-[height,transform] hover:-translate-y-0.5',
                )}
                style={{ height: `${heightPct}%`, minHeight: total === 0 ? 4 : undefined }}
                role="img"
                aria-label={`${b.label} : ${v} entreprises (${sharePct.toFixed(1)}%)`}
                title={`${b.label} · ${v.toLocaleString('fr-FR')} (${sharePct.toFixed(1)}%)`}
              />
            </div>
          );
        })}
      </div>

      {/* Labels axe X */}
      <div className="mt-2 flex gap-3 px-1">
        {BUCKETS.map((b) => (
          <div key={b.key} className="flex-1 text-center text-[11px] font-medium text-slate-600 dark:text-slate-400">
            {b.label}
          </div>
        ))}
      </div>
    </Card>
  );
}
