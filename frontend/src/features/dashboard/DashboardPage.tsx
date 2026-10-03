import { Building2, FolderX } from 'lucide-react';
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
  Tooltip,
  cn,
} from '@/components/ui';
import { api, qualifierErreur } from '@/lib/api';
import { QualityDistributionBar } from './components/QualityDistributionBar';
import { SizeDistributionChart } from './components/SizeDistributionChart';
import { TopDeptsCard } from './components/TopDeptsCard';
import { ActivityFeed } from './components/ActivityFeed';
import { NextActions } from './components/NextActions';
import { etatQualite } from './qualite';

/**
 * Les quatre compteurs valent `null` quand le serveur n'a PAS PU compter
 * (requête en échec, délai dépassé) : l'écran écrit « — », jamais 0 — audit UX
 * du 2026-10-02, P0-1.
 */
interface DashboardStats {
  companies_total: number | null;
  companies_enriched_24h: number | null;
  contacts_qualified: number | null;
  scraper_runs_24h: number | null;
  llm_cost_eur_month: number;
  /** `null` = répartition indisponible (requête en échec) : « — », jamais 0. */
  quality_distribution: { complete: number; partielle: number; basique: number } | null;
  /** `null` = répartition indisponible (requête en échec) : « — », jamais 0. */
  size_distribution: Record<string, number> | null;
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

const CHIFFRE_INDISPONIBLE = 'Chiffre indisponible pour le moment';

/**
 * Un compteur tel qu'il s'affiche : formaté en français, ou « — » avec son
 * infobulle quand le serveur n'a pas pu le calculer. Jamais un 0 inventé.
 */
function valeurKpi(n: number | null | undefined) {
  if (typeof n === 'number') return n.toLocaleString('fr-FR');
  return (
    <Tooltip content={CHIFFRE_INDISPONIBLE}>
      <span tabIndex={0} className="cursor-help" data-testid="chiffre-indisponible">
        —<span className="sr-only"> {CHIFFRE_INDISPONIBLE}</span>
      </span>
    </Tooltip>
  );
}

/**
 * Sans espace de travail courant, le serveur ne compte RIEN (cloisonnement) et
 * le dit en 409 ; avant, il rendait des zéros et l'écran annonçait « Votre base
 * est vide ». Deux codes, deux messages :
 *  - `no_workspace` : le compte n'est membre d'aucun espace ;
 *  - `workspace_not_selected` : il est membre, mais aucun n'est sélectionné.
 * Dans les deux cas, seul l'administrateur peut agir (la console n'a pas de
 * sélecteur d'espace).
 */
const MESSAGES_SANS_ESPACE: Readonly<Record<string, { titre: string; texte: string }>> = {
  no_workspace: {
    titre: 'Aucun espace de travail',
    texte: 'Aucun espace de travail n’est rattaché à votre compte. Contactez l’administrateur.',
  },
  workspace_not_selected: {
    titre: 'Aucun espace de travail sélectionné',
    texte: 'Votre compte est rattaché à un espace de travail, mais aucun n’est sélectionné. Contactez l’administrateur pour qu’il en sélectionne un.',
  },
};

function sansEspaceDe(err: unknown): { titre: string; texte: string } | null {
  const q = qualifierErreur(err);
  if (q.status !== 409 || q.code === null) return null;
  return MESSAGES_SANS_ESPACE[q.code] ?? null;
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
    // Sans espace de travail, redemander toutes les 30 s ne changera rien.
    refetchInterval: (query) => (sansEspaceDe(query.state.error) !== null ? false : 30_000),
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
  const sansEspace = sansEspaceDe(error);
  const echec = error !== null && data === undefined;
  const isEmpty = data !== undefined && data.companies_total === 0;
  const miseAJour = fraicheur(data?.computed_at);

  return (
    <div>
      <PageHeader
        eyebrow={firstName ? `Bonjour ${firstName}` : 'Bienvenue'}
        title="Tableau de bord"
        subtitle={miseAJour === null ? "Vue d'ensemble de votre base" : `Vue d'ensemble de votre base · ${miseAJour}`}
        actions={
          <>
            <LiveBadge label="En direct" refreshLabel="Mis à jour toutes les 30 secondes" />
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
      ) : sansEspace !== null ? (
        <Card padding="lg">
          <EmptyState title={sansEspace.titre} description={sansEspace.texte} icon={<FolderX />} />
        </Card>
      ) : echec ? (
        <QueryErrorState error={error} contexte="les chiffres du tableau de bord" onRetry={() => void refetch()} />
      ) : isEmpty ? (
        <Card padding="lg">
          <EmptyState
            title="Votre base est vide"
            description="Aucune entreprise pour l’instant. Choisissez un département sur la carte de France pour en récupérer."
            icon={<Building2 />}
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
          <section
            className={cn(
              'grid gap-3 sm:grid-cols-2',
              typeof stats.companies_new_7d === 'number' ? 'lg:grid-cols-4' : 'lg:grid-cols-3',
            )}
          >
            <KpiCard
              tone="sky"
              label="Total entreprises"
              value={valeurKpi(stats.companies_total)}
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
              value={valeurKpi(stats.companies_enriched_24h)}
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
            {/*
              Relecture de #301 : le serveur ne calcule PAS (encore)
              `companies_new_7d`. Afficher « — / indisponible pour le moment »
              en permanence ferait passer une absence de calcul pour une panne
              passagère. La vignette n'apparaît que si le serveur envoie le
              chiffre ; sans lui, elle n'existe pas.
            */}
            {typeof stats.companies_new_7d === 'number' ? (
              <KpiCard
                tone="emerald"
                label="Nouvelles 7j"
                value={valeurKpi(stats.companies_new_7d)}
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
            ) : null}
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
