import type { ReactNode } from 'react';
import { Link } from '@tanstack/react-router';
import { Building2, Map as MapIcon, RefreshCw, Rocket, Sparkles } from 'lucide-react';
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
  /** Écran visé : un `<Link>` du routeur, plus un `<a href>` qui rechargeait toute l'application. */
  to: '/coverage' | '/companies';
  /** Filtre de la liste des entreprises (lu par `validateSearch`). */
  quality?: 'basique';
  tone: 'sky' | 'violet' | 'emerald' | 'amber';
  icon: ReactNode;
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
      title: 'Récupérer des entreprises',
      description: 'Choisissez un département sur la carte de France.',
      to: '/coverage',
      tone: 'sky',
      icon: <Rocket className="h-4 w-4" />,
    });
  } else if (input.scraperRuns24h === 0) {
    out.push({
      id: 'resume-coverage',
      title: 'Reprendre la collecte',
      description: 'Aucune collecte depuis 24 heures.',
      to: '/coverage',
      tone: 'violet',
      icon: <RefreshCw className="h-4 w-4" />,
    });
  }

  if (input.qualityAvgScore > 0 && input.qualityAvgScore < 70) {
    out.push({
      id: 'enrich-quality',
      title: 'Compléter les fiches incomplètes',
      description: `Qualité moyenne : ${input.qualityAvgScore}/100.`,
      // Lot 4 — `quality` est le nom du filtre lu par `/companies` (validateSearch).
      to: '/companies',
      quality: 'basique',
      tone: 'amber',
      icon: <Sparkles className="h-4 w-4" />,
    });
  }

  // Actions toujours utiles
  if (input.companiesTotal > 0) {
    out.push({
      id: 'browse-companies',
      title: 'Voir vos entreprises',
      description: `${input.companiesTotal.toLocaleString('fr-FR')} fiches.`,
      to: '/companies',
      tone: 'emerald',
      icon: <Building2 className="h-4 w-4" />,
    });
  }

  // Fallback minimal si rien
  if (out.length === 0) {
    out.push({
      id: 'discover-coverage',
      title: 'Voir la carte',
      description: 'Régions, départements et villes couverts.',
      to: '/coverage',
      tone: 'sky',
      icon: <MapIcon className="h-4 w-4" />,
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
          <CardTitle>Et maintenant ?</CardTitle>
        </div>
      </CardHeader>

      <ul className="space-y-2">
        {actions.map((a) => {
          const t = TONE[a.tone];
          return (
            <li key={a.id}>
              <Link
                to={a.to}
                {...(a.quality ? { search: { quality: a.quality } } : {})}
                className={cn(
                  'group relative flex items-start gap-3 overflow-hidden rounded-xl bg-gradient-to-br p-3 ring-1 ring-slate-200/70 transition-all hover:-translate-y-0.5 hover:shadow-[var(--shadow-card-hover)] dark:ring-slate-800',
                  t.bg,
                )}
              >
                <span aria-hidden className={cn('inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-base', t.chip)}>
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
              </Link>
            </li>
          );
        })}
      </ul>
    </Card>
  );
}
