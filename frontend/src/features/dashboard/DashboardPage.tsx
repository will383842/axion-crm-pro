import type { ReactNode } from 'react';
import { Building2, FolderX } from 'lucide-react';
import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { Card, EmptyState, QueryErrorState, Skeleton, cn } from '@/components/ui';
import { api, qualifierErreur } from '@/lib/api';
import { useConsoleFeatures } from '@/features/crm-console/useConsoleFeatures';
import { AFaire } from './components/AFaire';
import { MesAudiences } from './components/MesAudiences';
import { SizeDistributionChart } from './components/SizeDistributionChart';
import { etatQualite } from './qualite';

/**
 * L'ACCUEIL DE LA CONSOLE, EN BLOCS — maquette validée par Will le 03/10/2026.
 *
 * De haut en bas : la date et « Bonjour <prénom> » ; « À faire » (les files
 * qui attendent un geste) ; « Ma base » (quatre tuiles) ; puis « Par taille »
 * et « Mes audiences ». Le fil « Activité récente » a quitté l'accueil : il
 * reste dans Santé du système. La recherche (Ctrl+K) est dans l'en-tête
 * global, elle n'est pas répétée ici.
 *
 * Les compteurs valent `null` quand le serveur n'a PAS PU compter (requête en
 * échec, délai dépassé) : l'écran écrit « — » et le dit, jamais 0 — audit UX
 * du 2026-10-02, P0-1.
 */
interface DashboardStats {
  companies_total: number | null;
  /** `null` = répartition indisponible (requête en échec) : « — », jamais 0. */
  size_distribution: Record<string, number> | null;
  /** Fiches vivantes qui portent un `enriched_at` (tuile « Fiches enrichies »). */
  companies_enriched?: number | null;
  /** Membres de l'audience système « Prospects contactables » (recalculés chaque nuit). */
  prospects_joignables?: number | null;
  /** Membres de « Prospects contactables — Île-de-France ». */
  prospects_joignables_idf?: number | null;
  /** Moyenne RÉELLE de `quality_score` (serveur) ; null = inconnue. */
  quality_avg?: number | null;
  /** Part estimée (%) des scores périmés ; au-delà du seuil : « calcul en attente ». */
  quality_a_recalculer_pct?: number | null;
  /** Heure du calcul (UTC) : les chiffres sont servis depuis un cache court. */
  computed_at?: string;
}

const CHIFFRE_INDISPONIBLE = 'Chiffre indisponible pour le moment';

/**
 * « Chiffres mis à jour il y a N min » — `/dashboard/stats` est servi par un
 * cache de 2 min (recalcul ≈ 10 s sur 4,3 M de fiches). Un chiffre mis en
 * cache qui se présente comme instantané est un mensonge d'interface.
 */
function fraicheur(computedAt: string | undefined, maintenant: number = Date.now()): string | null {
  if (computedAt === undefined) return null;
  const t = Date.parse(computedAt);
  if (Number.isNaN(t)) return null;
  const minutes = Math.max(0, Math.floor((maintenant - t) / 60_000));
  if (minutes < 1) return 'Chiffres mis à jour à l’instant';
  if (minutes < 60) return `Chiffres mis à jour il y a ${minutes} min`;
  return `Chiffres mis à jour il y a ${Math.floor(minutes / 60)} h`;
}

/** « Samedi 3 octobre » — la date du jour, en français, majuscule initiale. */
export function dateDuJour(maintenant: Date = new Date()): string {
  const texte = new Intl.DateTimeFormat('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' }).format(maintenant);
  return texte.charAt(0).toUpperCase() + texte.slice(1);
}

/** Espace insécable entre le nombre et « M » (jamais de retour à la ligne entre les deux). */
const ESPACE_INSECABLE = String.fromCharCode(0xa0);

/** « 4,35 M » au-delà du million, sinon le nombre entier au format français. */
export function nombreCourt(n: number): string {
  if (Math.abs(n) >= 1_000_000) {
    return `${(n / 1_000_000).toLocaleString('fr-FR', { maximumFractionDigits: 2 })}${ESPACE_INSECABLE}M`;
  }
  return n.toLocaleString('fr-FR');
}

/** Un pourcentage entier, ou `null` si l'une des deux mesures manque. */
function part(n: number | null | undefined, total: number | null | undefined): number | null {
  if (typeof n !== 'number' || typeof total !== 'number' || total <= 0) return null;
  return Math.round((n / total) * 100);
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
  const features = useConsoleFeatures();

  const { data, isLoading, refetch, error } = useQuery({
    queryKey: ['dashboard-stats'],
    queryFn: async ({ signal }) => (await api.get<DashboardStats>('/dashboard/stats', { signal })).data,
    // Sans espace de travail, redemander toutes les 30 s ne changera rien.
    refetchInterval: (query) => (sansEspaceDe(query.state.error) !== null ? false : 30_000),
    // D25-008 — PAS de `placeholderData` ici, et c'est délibéré. Un
    // `placeholderData` met `isPending` à faux dès le premier rendu ; `isLoading`
    // (= isPending && isFetching) ne vaut alors JAMAIS vrai, et le squelette ne
    // s'ouvre jamais : « aucune entreprise collectée » et « le serveur n'a pas
    // encore répondu » auraient la même apparence, sur l'écran d'accueil.
  });

  const firstName = firstNameFrom(me);
  // P0-1 — une panne n'est PAS une base vide. L'état vide n'existe que sur un
  // vrai 0 venu d'une réponse RÉUSSIE ; un échec affiche l'erreur.
  const sansEspace = sansEspaceDe(error);
  const echec = error !== null && data === undefined;
  const isEmpty = data !== undefined && data.companies_total === 0;

  return (
    <div className="flex min-w-0 flex-col gap-8">
      <header className="min-w-0">
        {/* « Tableau de bord » : le mot du menu et du fil d'Ariane, sur la ligne de la date. */}
        <p className="text-sm font-medium text-slate-500">
          <span>Tableau de bord</span> · {dateDuJour()}
        </p>
        <h1 className="mt-1 text-3xl font-bold tracking-tight text-slate-900 md:text-4xl">
          {firstName ? `Bonjour ${firstName}` : 'Bienvenue'}
        </h1>
      </header>

      {sansEspace !== null ? (
        <Card padding="lg">
          <EmptyState title={sansEspace.titre} description={sansEspace.texte} icon={<FolderX />} />
        </Card>
      ) : (
        <>
          {features.console_v2 ? <AFaire consoleOuverte /> : null}

          {isLoading ? (
            <MaBaseSquelette />
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
                    className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-gradient-to-b from-slate-900 to-slate-800 px-5 text-sm font-semibold text-white shadow-sm transition hover:from-slate-800 hover:to-slate-700"
                  >
                    Récupérer des entreprises
                  </Link>
                }
              />
            </Card>
          ) : data !== undefined ? (
            <>
              <MaBase stats={data} />
              <div className="grille-auto gap-4 [--grille-min:20rem]">
                <SizeDistributionChart data={data.size_distribution} />
                <MesAudiences />
              </div>
            </>
          ) : null}
        </>
      )}
    </div>
  );
}

// ---------------------------------------------------------------------------
// « Ma base » — quatre tuiles
// ---------------------------------------------------------------------------

interface TuileProps {
  titre: string;
  /** Le chiffre affiché (`null` = indisponible : « — »). */
  affiche: ReactNode;
  /** Ce que lisent les lecteurs d'écran à la place du chiffre affiché. */
  lu: string;
  sousLigne?: string | null;
  /** Barre de progression (0-100) et sa couleur. */
  barre?: { pct: number; couleur: string } | null;
  sombre?: boolean;
  accent?: boolean;
  testId: string;
}

function Tuile({ titre, affiche, lu, sousLigne, barre, sombre = false, accent = false, testId }: TuileProps) {
  return (
    <div
      data-testid={testId}
      className={cn(
        'flex min-w-0 flex-col gap-2 rounded-2xl p-5',
        sombre ? 'bg-sidebar text-white' : 'bg-white text-slate-900 ring-1 ring-slate-200/70 shadow-[var(--shadow-card)]',
      )}
    >
      <h3 className={cn('text-sm font-medium', sombre ? 'text-sidebar-fg' : 'text-slate-500')}>{titre}</h3>
      <p className={cn('text-3xl font-bold tracking-tight tabular-nums', accent && !sombre && affiche !== null && 'text-brand-600')}>
        <span aria-hidden>{affiche ?? '—'}</span>
        <span className="sr-only">{lu}</span>
      </p>
      {sousLigne ? <p className={cn('text-xs', sombre ? 'text-sidebar-fg' : 'text-slate-500')}>{sousLigne}</p> : null}
      {barre ? (
        <div className="mt-1 h-2 overflow-hidden rounded-full bg-slate-100" aria-hidden>
          <div className={cn('h-full rounded-full', barre.couleur)} style={{ width: `${Math.max(0, Math.min(100, barre.pct))}%` }} />
        </div>
      ) : null}
    </div>
  );
}

function MaBase({ stats }: { stats: DashboardStats }) {
  const miseAJour = fraicheur(stats.computed_at);
  const total = stats.companies_total;

  // « 93 % de TPE » : part des TPE dans la répartition par taille (fiches classées).
  const tailles = stats.size_distribution;
  const classees = tailles === null ? 0 : Object.values(tailles).reduce((s, v) => s + v, 0);
  const partTpe = tailles === null ? null : part(tailles['tpe'] ?? 0, classees);

  const joignables = stats.prospects_joignables ?? null;
  const joignablesIdf = stats.prospects_joignables_idf ?? null;
  const partEnrichies = part(stats.companies_enriched, total);
  const qualite = etatQualite(stats);

  return (
    <section aria-labelledby="titre-ma-base" className="flex flex-col gap-3.5">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 id="titre-ma-base" className="text-lg font-bold text-slate-900">
          Ma base
        </h2>
        {miseAJour !== null ? <p className="text-xs text-slate-500">{miseAJour}</p> : null}
      </div>
      <div className="grille-auto gap-4 [--grille-min:13.5rem]">
        <Tuile
          testId="tuile-entreprises"
          titre="Entreprises"
          sombre
          affiche={typeof total === 'number' ? nombreCourt(total) : null}
          lu={typeof total === 'number' ? `${total.toLocaleString('fr-FR')} entreprises` : CHIFFRE_INDISPONIBLE}
          sousLigne={typeof total !== 'number' ? CHIFFRE_INDISPONIBLE : partTpe !== null ? `${partTpe} % de TPE` : null}
        />
        <Tuile
          testId="tuile-joignables"
          titre="Prospects joignables"
          accent
          affiche={joignables === null ? null : joignables.toLocaleString('fr-FR')}
          lu={joignables === null ? CHIFFRE_INDISPONIBLE : `${joignables.toLocaleString('fr-FR')} prospects joignables`}
          sousLigne={
            joignables === null
              ? CHIFFRE_INDISPONIBLE
              : joignablesIdf !== null
                ? `dont ${joignablesIdf.toLocaleString('fr-FR')} en Île-de-France`
                : null
          }
        />
        <Tuile
          testId="tuile-enrichies"
          titre="Fiches enrichies"
          affiche={partEnrichies === null ? null : `${partEnrichies} %`}
          lu={partEnrichies === null ? CHIFFRE_INDISPONIBLE : `${partEnrichies} % des fiches enrichies`}
          sousLigne={partEnrichies === null ? CHIFFRE_INDISPONIBLE : null}
          barre={partEnrichies === null ? null : { pct: partEnrichies, couleur: 'bg-brand-600' }}
        />
        <Tuile
          testId="tuile-qualite"
          titre="Qualité moyenne"
          affiche={
            qualite.etat === 'ok' ? (
              <>
                {qualite.moyenne}
                <span className="text-lg font-semibold text-slate-500"> / 100</span>
              </>
            ) : null
          }
          lu={qualite.etat === 'ok' ? `Qualité moyenne : ${qualite.moyenne} sur 100` : CHIFFRE_INDISPONIBLE}
          sousLigne={
            qualite.etat === 'en_attente'
              ? `Calcul en attente (≈ ${qualite.pctARecalculer} % des fiches à recalculer)`
              : qualite.etat === 'indisponible'
                ? CHIFFRE_INDISPONIBLE
                : null
          }
          barre={qualite.etat === 'ok' ? { pct: qualite.moyenne, couleur: 'bg-orange-500' } : null}
        />
      </div>
    </section>
  );
}

function MaBaseSquelette() {
  return (
    <div className="flex flex-col gap-8" data-testid="accueil-squelette">
      <div className="flex flex-col gap-3.5">
        <Skeleton className="h-6 w-28" />
        <div className="grille-auto gap-4 [--grille-min:13.5rem]">
          {Array.from({ length: 4 }).map((_, i) => (
            <div key={i} className="rounded-2xl bg-white p-5 ring-1 ring-slate-200/70 shadow-[var(--shadow-card)]">
              <Skeleton className="mb-3 h-4 w-24" />
              <Skeleton className="h-8 w-28" />
              <Skeleton className="mt-3 h-2 w-full" />
            </div>
          ))}
        </div>
      </div>
      <div className="grille-auto gap-4 [--grille-min:20rem]">
        {Array.from({ length: 2 }).map((_, i) => (
          <div key={i} className="rounded-2xl bg-white p-6 ring-1 ring-slate-200/70 shadow-[var(--shadow-card)]">
            <Skeleton className="mb-4 h-5 w-32" />
            <Skeleton className="h-32 w-full" />
          </div>
        ))}
      </div>
    </div>
  );
}
