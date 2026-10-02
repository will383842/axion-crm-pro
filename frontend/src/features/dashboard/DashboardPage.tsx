import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import {
  PageHeader,
  LiveBadge,
  KpiCard,
  Button,
  Card,
  EmptyState,
  QueryErrorState,
  Skeleton,
  cn,
} from '@/components/ui';
import { api } from '@/lib/api';
import { QualityDistributionBar } from './components/QualityDistributionBar';
import { SizeDistributionChart } from './components/SizeDistributionChart';
import { TopDeptsCard } from './components/TopDeptsCard';
import { ActivityFeed } from './components/ActivityFeed';
import { NextActions } from './components/NextActions';
import { etatQualite } from './qualite';

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
  /** Moyenne RÉELLE de `quality_score` (serveur) ; null = inconnue. */
  quality_avg?: number | null;
  /** Part estimée (%) des scores périmés ; au-delà du seuil : « calcul en attente ». */
  quality_a_recalculer_pct?: number | null;
  period_label?: string;
  /** Heure du calcul (UTC) : les chiffres sont servis depuis un cache court. */
  computed_at?: string;
}

/**
 * « mis à jour il y a N min » — 2026-10-02 : `/dashboard/stats` est servi par
 * un cache de 2 min (recalcul ≈ 10 s sur 4,3 M de fiches). Un chiffre mis en
 * cache qui se présente comme instantané est un mensonge d'interface.
 */
function fraicheur(computedAt: string | undefined, maintenant: number = Date.now()): string | null {
  if (computedAt === undefined) return null;
  const t = Date.parse(computedAt);
  if (Number.isNaN(t)) return null;
  const minutes = Math.max(0, Math.floor((maintenant - t) / 60_000));
  if (minutes < 1) return 'chiffres mis à jour à l’instant';
  if (minutes < 60) return `chiffres mis à jour il y a ${minutes} min`;
  return `chiffres mis à jour il y a ${Math.floor(minutes / 60)} h`;
}

interface MeResponse {
  user: { id: string; name?: string | null; email?: string | null };
}

// P0 (audit UX 02/10) — le sélecteur 7 j / 30 j / 90 j a été RETIRÉ. Il
// n'agissait sur rien : la période n'était pas dans la clé de cache, et le
// serveur ne s'en sert que pour un libellé (`libellePeriode`). Les chiffres
// affichés ne dépendent d'aucune période ; un sélecteur qui ne change rien
// laisse croire le contraire.

function firstNameFrom(me: MeResponse | undefined): string | null {
  const raw = me?.user?.name?.trim() || me?.user?.email?.split('@')[0]?.trim() || '';
  if (!raw) return null;
  return raw.split(/\s+/)[0] ?? null;
}

export function DashboardPage() {
  const { data: me } = useQuery<MeResponse>({
    queryKey: ['auth', 'me'],
    queryFn: async () => (await api.get<MeResponse>('/auth/me')).data,
    retry: false,
    staleTime: 5 * 60 * 1000,
  });

  const { data, isLoading, isFetching, refetch, error } = useQuery({
    queryKey: ['dashboard-stats'],
    queryFn: async ({ signal }) => (await api.get<DashboardStats>('/dashboard/stats', { signal })).data,
    refetchInterval: 30_000,
    // D25-008 — PAS de `placeholderData` ici, et c'est délibéré. Un
    // `placeholderData` met `isPending` à faux dès le premier rendu ; `isLoading`
    // (= isPending && isFetching) ne vaut alors JAMAIS vrai, et le
    // `{isLoading ? <DashboardSkeleton /> : …}` plus bas ne s'ouvre jamais.
    // Mesure du 2026-08-22 : le premier écran du CRM était la grille de zéros de
    // cet objet de repli — « aucune entreprise collectée » et « le serveur n'a
    // pas encore répondu » avaient exactement la même apparence, sur l'écran
    // d'accueil. Le garde-fou `const stats = data ?? {…}` juste dessous suffit à
    // protéger le rendu quand `data` est indéfini ; le squelette, lui, DIT
    // l'attente au lieu de l'habiller en résultat.
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

  const qualite = etatQualite(stats);
  const qualityAvg = qualite.etat === 'ok' ? qualite.moyenne : 0;
  const firstName = firstNameFrom(me);
  // P0-1 — une panne n'est PAS une base vide. L'état vide n'existe que sur un
  // vrai 0 venu d'une réponse RÉUSSIE ; un échec affiche l'erreur.
  const echec = error !== null && data === undefined;
  const isEmpty = data !== undefined && data.companies_total === 0;
  const miseAJour = fraicheur(data?.computed_at);

  return (
    <div className="px-6 py-6">
      <PageHeader
        eyebrow={firstName ? `Bonjour ${firstName} 👋` : 'Bienvenue'}
        title="Tableau de bord"
        subtitle={miseAJour === null ? "Vue d'ensemble de votre base" : `Vue d'ensemble de votre base · ${miseAJour}`}
        actions={
          <>
            <LiveBadge label="En direct" refreshLabel="actualisé toutes les 30s" />
            <Button
              variant="secondary"
              size="sm"
              loading={isFetching && !isLoading}
              onClick={() => refetch()}
              iconLeft={<span aria-hidden>⟳</span>}
            >
              Actualiser
            </Button>
          </>
        }
      />

      {isLoading ? (
        <DashboardSkeleton />
      ) : echec ? (
        <QueryErrorState error={error} contexte="les chiffres du tableau de bord" onRetry={() => void refetch()} />
      ) : isEmpty ? (
        <Card padding="lg">
          <EmptyState
            title="Votre base est vide"
            description="Aucune entreprise pour l’instant. Choisissez un département sur la carte de France pour en récupérer."
            icon="🚀"
            action={
              <Link
                to="/coverage"
                className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-gradient-to-b from-slate-900 to-slate-800 px-5 text-sm font-semibold text-white shadow-sm transition hover:from-slate-800 hover:to-slate-700 dark:from-white dark:to-slate-100 dark:text-slate-900"
              >
                Récupérer des entreprises
              </Link>
            }
          />
        </Card>
      ) : (
        <>
          {/* KPI grid */}
          <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <KpiCard
              tone="sky"
              label="Total entreprises"
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
              // Pas de valeur inventée (l'ancien repli `enrichies 24 h × 7`) :
              // sans chiffre du serveur, on l'écrit.
              value={typeof stats.companies_new_7d === 'number' ? stats.companies_new_7d.toLocaleString('fr-FR') : '—'}
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
              value={qualite.etat === 'ok' ? `${qualite.moyenne}/100` : '—'}
              sublabel={
                qualite.etat === 'ok'
                  ? 'Score de qualité moyen des fiches'
                  : qualite.etat === 'en_attente'
                    ? `Calcul en attente (≈ ${qualite.pctARecalculer} % des fiches à recalculer)`
                    : 'Chiffre non disponible'
              }
              {...(qualite.etat === 'ok' ? { progress: qualite.moyenne } : {})}
              {...(qualite.etat === 'ok' && typeof stats.quality_trend_pct === 'number'
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
              <QualityDistributionBar data={stats.quality_distribution} qualite={qualite} />
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
