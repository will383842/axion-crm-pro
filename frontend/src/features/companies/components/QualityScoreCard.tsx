import { Card, CardEyebrow, CardHeader, CardTitle, QualityBadge, cn } from '@/components/ui';

export interface QualityScoreCardProps {
  score?: number | null | undefined;
  breakdown?:
    | {
        email?: number | null | undefined;
        phone?: number | null | undefined;
        website?: number | null | undefined;
        contact?: number | null | undefined;
      }
    | undefined;
}

function Bar({ label, value }: { label: string; value: number }) {
  const pct = Math.max(0, Math.min(100, value));
  const tone = pct >= 80 ? 'from-emerald-500 to-teal-600' : pct >= 50 ? 'from-amber-500 to-orange-600' : 'from-rose-500 to-pink-600';
  return (
    <div>
      <div className="mb-1 flex items-center justify-between text-[11px] text-slate-500 dark:text-slate-400">
        <span className="uppercase tracking-wider">{label}</span>
        <span className="tabular-nums font-semibold text-slate-700 dark:text-slate-300">{pct}%</span>
      </div>
      <div className="h-1.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
        <div
          className={cn('h-full rounded-full bg-gradient-to-r transition-[width] duration-500', tone)}
          style={{ width: `${pct}%` }}
        />
      </div>
    </div>
  );
}

export function QualityScoreCard({ score, breakdown }: QualityScoreCardProps) {
  const has = breakdown && (breakdown.email != null || breakdown.phone != null || breakdown.website != null || breakdown.contact != null);
  return (
    <Card padding="md">
      <CardHeader>
        <div>
          <CardEyebrow>Score de qualité</CardEyebrow>
          <CardTitle>Qualité de la donnée</CardTitle>
        </div>
      </CardHeader>
      <div className="flex items-center gap-3">
        <div className="text-3xl font-semibold tabular-nums tracking-tight text-slate-900 dark:text-white">
          {score ?? '—'}
        </div>
        <QualityBadge score={score ?? undefined} />
      </div>
      {has ? (
        <div className="mt-4 space-y-3">
          {breakdown!.email != null ? <Bar label="Email" value={breakdown!.email!} /> : null}
          {breakdown!.phone != null ? <Bar label="Téléphone" value={breakdown!.phone!} /> : null}
          {breakdown!.website != null ? <Bar label="Site web" value={breakdown!.website!} /> : null}
          {breakdown!.contact != null ? <Bar label="Contacts" value={breakdown!.contact!} /> : null}
        </div>
      ) : (
        <p className="mt-3 text-xs text-slate-500 dark:text-slate-400">
          Détail par dimension indisponible pour cette entreprise.
        </p>
      )}
    </Card>
  );
}
