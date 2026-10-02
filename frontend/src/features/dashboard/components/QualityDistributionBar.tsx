import { Card, CardHeader, CardTitle, CardEyebrow, cn } from '@/components/ui';
import type { EtatQualite } from '../qualite';

export interface QualityDistribution {
  complete: number;
  partielle: number;
  basique: number;
}

const SEGMENTS = [
  {
    key: 'complete' as const,
    label: 'Complète',
    icon: '🟢',
    bar: 'from-emerald-500 to-teal-500',
    dot: 'bg-emerald-500',
    text: 'text-emerald-700 dark:text-emerald-300',
  },
  {
    key: 'partielle' as const,
    label: 'Partielle',
    icon: '🟡',
    bar: 'from-amber-500 to-orange-500',
    dot: 'bg-amber-500',
    text: 'text-amber-700 dark:text-amber-300',
  },
  {
    key: 'basique' as const,
    label: 'Basique',
    icon: '🔴',
    bar: 'from-rose-500 to-pink-500',
    dot: 'bg-rose-500',
    text: 'text-rose-700 dark:text-rose-300',
  },
];

export function QualityDistributionBar({ data, qualite }: { data: QualityDistribution; qualite: EtatQualite }) {
  const total = (data.complete ?? 0) + (data.partielle ?? 0) + (data.basique ?? 0);
  const safeTotal = total || 1;

  // Lot 3 (2026-10-02) — plus de « moyenne » pondérée inventée à l'écran
  // (100 / 60 / 25) : la moyenne vient du serveur. Tant que les scores sont
  // majoritairement périmés, ou qu'aucun chiffre n'est connu, le bloc le DIT
  // au lieu d'afficher des barres et un 0 trompeurs.
  if (qualite.etat !== 'ok' || total === 0) {
    return (
      <Card>
        <CardHeader>
          <div className="min-w-0">
            <CardEyebrow>Qualité</CardEyebrow>
            <CardTitle>Distribution qualité des fiches</CardTitle>
          </div>
        </CardHeader>
        <p className="text-sm text-slate-600 dark:text-slate-300" data-testid="qualite-indisponible">
          {qualite.etat === 'en_attente'
            ? `Score de qualité pas encore calculé : environ ${qualite.pctARecalculer} % des fiches attendent leur calcul. La répartition s’affichera une fois le calcul terminé.`
            : 'Score de qualité non disponible pour le moment.'}
        </p>
      </Card>
    );
  }
  const avgScore = qualite.moyenne;

  return (
    <Card>
      <CardHeader>
        <div className="min-w-0">
          <CardEyebrow>Qualité</CardEyebrow>
          <CardTitle>Distribution qualité des fiches</CardTitle>
        </div>
        <div className="shrink-0 text-right">
          <div className="text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
            Score moyen
          </div>
          <div className="text-2xl font-semibold tabular-nums text-slate-900 dark:text-white">
            {avgScore}
            <span className="ml-0.5 text-sm font-normal text-slate-500 dark:text-slate-400">/100</span>
          </div>
        </div>
      </CardHeader>

      {/* Barre stack horizontale */}
      <div className="flex h-3 w-full overflow-hidden rounded-full bg-slate-100 ring-1 ring-slate-200/60 dark:bg-slate-800 dark:ring-slate-700/60">
        {total === 0 ? (
          <div className="h-full w-full bg-slate-100 dark:bg-slate-800" aria-label="Aucune donnée" />
        ) : (
          SEGMENTS.map((seg) => {
            const v = data[seg.key] ?? 0;
            const pct = (v / safeTotal) * 100;
            if (pct <= 0) return null;
            return (
              <div
                key={seg.key}
                className={cn('h-full bg-gradient-to-r transition-[width] duration-500', seg.bar)}
                style={{ width: `${pct}%` }}
                role="img"
                aria-label={`${seg.label} : ${v} (${pct.toFixed(1)}%)`}
                title={`${seg.label} · ${v.toLocaleString('fr-FR')} (${pct.toFixed(1)}%)`}
              />
            );
          })
        )}
      </div>

      {/* Légende */}
      <ul className="mt-4 grid gap-2 sm:grid-cols-3">
        {SEGMENTS.map((seg) => {
          const v = data[seg.key] ?? 0;
          const pct = total === 0 ? 0 : (v / safeTotal) * 100;
          return (
            <li
              key={seg.key}
              className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 ring-1 ring-slate-100 dark:bg-slate-800/60 dark:ring-slate-800"
            >
              <div className="flex items-center gap-2">
                <span className={cn('inline-block h-2 w-2 rounded-full', seg.dot)} aria-hidden />
                <span className="text-xs font-medium text-slate-700 dark:text-slate-300">{seg.label}</span>
              </div>
              <div className="text-right tabular-nums">
                <div className={cn('text-sm font-semibold', seg.text)}>{v.toLocaleString('fr-FR')}</div>
                <div className="text-[10px] text-slate-500 dark:text-slate-400">{pct.toFixed(1)}%</div>
              </div>
            </li>
          );
        })}
      </ul>
    </Card>
  );
}
