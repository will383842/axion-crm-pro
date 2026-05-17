import { Card, CardHeader, CardTitle, CardEyebrow, cn } from '@/components/ui';

export interface NextActionsInput {
  companiesTotal: number;
  scraperRuns24h: number;
  qualityAvgScore: number; // 0-100
}

interface ActionItem {
  id: string;
  title: string;
  description: string;
  href: string;
  tone: 'sky' | 'violet' | 'emerald' | 'amber';
  icon: string;
}

const TONE: Record<ActionItem['tone'], { bg: string; chip: string; arrow: string }> = {
  sky:     { bg: 'from-sky-50 to-white dark:from-sky-950/40 dark:to-slate-900',         chip: 'bg-sky-100 text-sky-700 dark:bg-sky-900/50 dark:text-sky-300',         arrow: 'text-sky-600 dark:text-sky-400' },
  violet:  { bg: 'from-violet-50 to-white dark:from-violet-950/40 dark:to-slate-900',   chip: 'bg-violet-100 text-violet-700 dark:bg-violet-900/50 dark:text-violet-300', arrow: 'text-violet-600 dark:text-violet-400' },
  emerald: { bg: 'from-emerald-50 to-white dark:from-emerald-950/40 dark:to-slate-900', chip: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300', arrow: 'text-emerald-600 dark:text-emerald-400' },
  amber:   { bg: 'from-amber-50 to-white dark:from-amber-950/40 dark:to-slate-900',     chip: 'bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-300',     arrow: 'text-amber-600 dark:text-amber-400' },
};

function buildActions(input: NextActionsInput): ActionItem[] {
  const out: ActionItem[] = [];

  if (input.companiesTotal === 0) {
    out.push({
      id: 'first-scrape',
      title: 'Lance ton premier scrape',
      description: 'Choisis un département sur la carte France et démarre la collecte.',
      href: '/coverage',
      tone: 'sky',
      icon: '🚀',
    });
  } else if (input.scraperRuns24h === 0) {
    out.push({
      id: 'resume-coverage',
      title: 'Reprends ton dernier zone',
      description: 'Aucun scrape lancé sur les dernières 24h — relance la couverture.',
      href: '/coverage',
      tone: 'violet',
      icon: '🔄',
    });
  }

  if (input.qualityAvgScore > 0 && input.qualityAvgScore < 70) {
    out.push({
      id: 'enrich-quality',
      title: 'Améliore la qualité',
      description: `Score moyen ${input.qualityAvgScore}/100 — enrichis tes fiches "basique".`,
      href: '/companies?quality_badge=basique',
      tone: 'amber',
      icon: '✨',
    });
  }

  // Actions toujours utiles
  if (input.companiesTotal > 0) {
    out.push({
      id: 'browse-companies',
      title: 'Explore tes entreprises',
      description: `${input.companiesTotal.toLocaleString('fr-FR')} fiches collectées — filtre, segmente, exporte.`,
      href: '/companies',
      tone: 'emerald',
      icon: '🏢',
    });
  }

  // Fallback minimal si rien
  if (out.length === 0) {
    out.push({
      id: 'discover-coverage',
      title: 'Découvre la carte France',
      description: 'Visualise la couverture en régions, départements et villes.',
      href: '/coverage',
      tone: 'sky',
      icon: '🗺️',
    });
  }

  return out.slice(0, 3);
}

export function NextActions(props: NextActionsInput) {
  const actions = buildActions(props);

  return (
    <Card>
      <CardHeader>
        <div className="min-w-0">
          <CardEyebrow>Prochaines étapes</CardEyebrow>
          <CardTitle>Que veux-tu faire maintenant ?</CardTitle>
        </div>
      </CardHeader>

      <ul className="space-y-2">
        {actions.map((a) => {
          const t = TONE[a.tone];
          return (
            <li key={a.id}>
              <a
                href={a.href}
                className={cn(
                  'group relative flex items-start gap-3 overflow-hidden rounded-xl bg-gradient-to-br p-3 ring-1 ring-slate-200/70 transition-all hover:-translate-y-0.5 hover:shadow-[var(--shadow-card-hover)] dark:ring-slate-800',
                  t.bg,
                )}
              >
                <span className={cn('inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-base', t.chip)}>
                  {a.icon}
                </span>
                <div className="min-w-0 flex-1">
                  <div className="flex items-center justify-between gap-2">
                    <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">
                      {a.title}
                    </p>
                    <span
                      className={cn('shrink-0 text-base transition-transform group-hover:translate-x-0.5', t.arrow)}
                      aria-hidden
                    >
                      →
                    </span>
                  </div>
                  <p className="mt-0.5 line-clamp-2 text-xs text-slate-600 dark:text-slate-400">
                    {a.description}
                  </p>
                </div>
              </a>
            </li>
          );
        })}
      </ul>
    </Card>
  );
}
