import { useState, useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
  PageHeader,
  LiveBadge,
  KpiCard,
  Button,
  SegmentedControl,
  Card,
  EmptyState,
  Skeleton,
  cn,
} from '@/components/ui';
import { api } from '@/lib/api';
import { QualityDistributionBar } from './components/QualityDistributionBar';
import { SizeDistributionChart } from './components/SizeDistributionChart';
import { TopDeptsCard } from './components/TopDeptsCard';
import { ActivityFeed } from './components/ActivityFeed';
import { NextActions } from './components/NextActions';

interface DashboardStats {
  companies_total: number;
  companies_enriched_24h: number;
  contacts_qualified: number;
  scraper_runs_24h: number;
  llm_cost_eur_month: number;
  quality_distribution: { complete: number; partielle: number; basique: number };
  size_distribution: Record<string, number>;
  // Champs optionnels — non garantis côté backend, traités défensivement.
  companies_new_7d?: number;
  companies_total_trend_pct?: number;
  enriched_24h_trend_pct?: number;
  new_7d_trend_pct?: number;
  quality_trend_pct?: number;
  period_label?: string;
}

interface MeResponse {
  user: { id: string; name?: string | null; email?: string | null };
}

type Period = '7d' | '30d' | '90d';

const PERIOD_OPTIONS = [
  { id: '7d' as const, label: '7j' },
  { id: '30d' as const, label: '30j' },
  { id: '90d' as const, label: '90j' },
];

const PERIOD_LABEL: Record<Period, string> = {
  '7d': 'derniers 7 jours',
  '30d': 'derniers 30 jours',
  '90d': 'derniers 90 jours',
};

function firstNameFrom(me: MeResponse | undefined): string | null {
  const raw = me?.user?.name?.trim() || me?.user?.email?.split('@')[0]?.trim() || '';
  if (!raw) return null;
  return raw.split(/\s+/)[0] ?? null;
}

function computeQualityAvg(qd: DashboardStats['quality_distribution']): number {
  const total = qd.complete + qd.partielle + qd.basique;
  if (total === 0) return 0;
  return Math.round(((qd.complete * 100) + (qd.partielle * 60) + (qd.basique * 25)) / total);
}

export function DashboardPage() {
  const [period, setPeriod] = useState<Period>('30d');

  const { data: me } = useQuery<MeResponse>({
    queryKey: ['auth', 'me'],
    queryFn: async () => (await api.get<MeResponse>('/auth/me')).data,
    retry: false,
    staleTime: 5 * 60 * 1000,
  });

  const { data, isLoading, isFetching, refetch } = useQuery({
    queryKey: ['dashboard-stats'],
    queryFn: async () => (await api.get<DashboardStats>('/dashboard/stats', { params: { period } })).data,
    refetchInterval: 30_000,
    placeholderData: {
      companies_total: 0,
      companies_enriched_24h: 0,
      contacts_qualified: 0,
      scraper_runs_24h: 0,
      llm_cost_eur_month: 0,
      quality_distribution: { complete: 0, partielle: 0, basique: 0 },
      size_distribution: {},
    },
  });

  const stats = data ?? {
    companies_total: 0,
    companies_enriched_24h: 0,
    contacts_qualified: 0,
    scraper_runs_24h: 0,
    llm_cost_eur_month: 0,
    quality_distribution: { complete: 0, partielle: 0, basique: 0 },
    size_distribution: {},
  };

  const qualityAvg = useMemo(() => computeQualityAvg(stats.quality_distribution), [stats.quality_distribution]);
  const firstName = firstNameFrom(me);
  const isEmpty = !isLoading && stats.companies_total === 0;

  return (
    <div className="px-6 py-6">
      <PageHeader
        eyebrow={firstName ? `Bonjour ${firstName} 👋` : 'Bienvenue'}
        title="Dashboard"
        subtitle={`Vue d'ensemble · ${stats.period_label ?? PERIOD_LABEL[period]}`}
        actions={
          <>
            <LiveBadge label="Live" refreshLabel="30s" />
            <SegmentedControl
              size="sm"
              options={PERIOD_OPTIONS}
              value={period}
              onChange={(v) => setPeriod(v)}
            />
            <Button
              variant="secondary"
              size="sm"
              loading={isFetching && !isLoading}
              onClick={() => refetch()}
              iconLeft={<span aria-hidden>⟳</span>}
            >
              Rafraîchir
            </Button>
          </>
        }
      />

      {isLoading ? (
        <DashboardSkeleton />
      ) : isEmpty ? (
        <Card padding="lg">
          <EmptyState
            title="Lance ton premier scrape"
            description="Aucune entreprise collectée pour l'instant. Choisis un département sur la carte France et démarre la couverture en un clic."
            icon="🚀"
            action={
              <a
                href="/coverage"
                className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-gradient-to-b from-slate-900 to-slate-800 px-5 text-sm font-semibold text-white shadow-sm transition hover:from-slate-800 hover:to-slate-700 dark:from-white dark:to-slate-100 dark:text-slate-900"
              >
                Démarrer sur /coverage →
              </a>
            }
          />
        </Card>
      ) : (
        <>
          {/* KPI grid */}
          <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <KpiCard
              tone="sky"
              label="Companies total"
              value={stats.companies_total.toLocaleString('fr-FR')}
              sublabel="Toutes périodes confondues"
              {...(typeof stats.companies_total_trend_pct === 'number'
                ? {
                    trend: {
                      value: Math.abs(stats.companies_total_trend_pct),
                      direction: stats.companies_total_trend_pct >= 0 ? 'up' : 'down',
                      label: 'vs précédent',
                    },
                  }
                : {})}
              progress={stats.companies_total > 0 ? Math.min(100, Math.round((stats.companies_total / 50_000) * 100)) : 2}
            />
            <KpiCard
              tone="violet"
              label="Enrichies 24h"
              value={stats.companies_enriched_24h.toLocaleString('fr-FR')}
              sublabel="Fiches enrichies sur 24h"
              {...(typeof stats.enriched_24h_trend_pct === 'number'
                ? {
                    trend: {
                      value: Math.abs(stats.enriched_24h_trend_pct),
                      direction: stats.enriched_24h_trend_pct >= 0 ? 'up' : 'down',
                      label: 'vs J-1',
                    },
                  }
                : {})}
            />
            <KpiCard
              tone="emerald"
              label="Nouvelles 7j"
              value={(stats.companies_new_7d ?? stats.companies_enriched_24h * 7).toLocaleString('fr-FR')}
              sublabel="Découvertes sur 7 jours"
              {...(typeof stats.new_7d_trend_pct === 'number'
                ? {
                    trend: {
                      value: Math.abs(stats.new_7d_trend_pct),
                      direction: stats.new_7d_trend_pct >= 0 ? 'up' : 'down',
                      label: 'vs S-1',
                    },
                  }
                : {})}
            />
            <KpiCard
              tone="amber"
              label="Qualité moyenne"
              value={`${qualityAvg}/100`}
              sublabel="Score pondéré qualité"
              progress={qualityAvg}
              {...(typeof stats.quality_trend_pct === 'number'
                ? {
                    trend: {
                      value: Math.abs(stats.quality_trend_pct),
                      direction: stats.quality_trend_pct >= 0 ? 'up' : 'down',
                      label: 'vs précédent',
                    },
                  }
                : {})}
            />
          </section>

          {/* 2 cols : gauche 2/3, droite 1/3 */}
          <section className="mt-6 grid gap-4 lg:grid-cols-3">
            <div className="space-y-4 lg:col-span-2">
              <QualityDistributionBar data={stats.quality_distribution} />
              <SizeDistributionChart data={stats.size_distribution} />
              <TopDeptsCard />
            </div>
            <div className="space-y-4">
              <ActivityFeed />
              <NextActions
                companiesTotal={stats.companies_total}
                scraperRuns24h={stats.scraper_runs_24h}
                qualityAvgScore={qualityAvg}
              />
            </div>
          </section>
        </>
      )}
    </div>
  );
}

function DashboardSkeleton() {
  return (
    <>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <div
            key={i}
            className={cn(
              'rounded-2xl bg-white/80 p-4 ring-1 ring-slate-200/60 shadow-[var(--shadow-card)] dark:bg-slate-900/60 dark:ring-slate-800/60',
            )}
          >
            <Skeleton className="mb-3 h-4 w-20" />
            <Skeleton className="h-7 w-28" />
            <Skeleton className="mt-3 h-1.5 w-full" />
          </div>
        ))}
      </div>
      <div className="mt-6 grid gap-4 lg:grid-cols-3">
        <div className="space-y-4 lg:col-span-2">
          {Array.from({ length: 3 }).map((_, i) => (
            <div key={i} className="rounded-2xl bg-white p-5 ring-1 ring-slate-200/70 shadow-[var(--shadow-card)] dark:bg-slate-900 dark:ring-slate-800">
              <Skeleton className="mb-3 h-4 w-32" />
              <Skeleton className="h-32 w-full" />
            </div>
          ))}
        </div>
        <div className="space-y-4">
          {Array.from({ length: 2 }).map((_, i) => (
            <div key={i} className="rounded-2xl bg-white p-5 ring-1 ring-slate-200/70 shadow-[var(--shadow-card)] dark:bg-slate-900 dark:ring-slate-800">
              <Skeleton className="mb-3 h-4 w-24" />
              <Skeleton className="h-24 w-full" />
            </div>
          ))}
        </div>
      </div>
    </>
  );
}
