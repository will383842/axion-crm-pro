import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { Activity, AlertTriangle, MailCheck, Archive, MapPin } from 'lucide-react';
import { Card, KpiCard, PageHeader, QueryErrorState } from '@/components/ui';
import { api } from '@/lib/api';
import { ReglagesTechniques } from '@/features/settings/ReglagesTechniques';
import { elementConcerne, libelleEvenementMetier, libelleMotifArchivage, type ElementConcerne } from './libelles';

interface ObservabilitySummary {
  waterfall_errors_24h: number;
  hunter_quota_month: { used: number; soft_limit: number; percent: number };
  google_places_quota: { used: number; soft_limit: number; percent: number; pending_companies: number };
  archive_reasons: Record<string, number>;
  audience_failures_7d: number;
  recent_events: Array<{
    id: number;
    action: string;
    resource_type: string | null;
    resource_id: string | null;
    context: Record<string, unknown> | null;
    created_at: string;
  }>;
}

/**
 * Sprint H4 — Dashboard observabilité.
 * KPI cards + table dernières 50 business_events.
 * Data via /api/v1/observability/summary, servi depuis un cache de 5 min par
 * espace côté serveur (le calcul complet peut prendre plusieurs secondes).
 */
export function ObservabilityPage() {
  // P1-12 — les réglages techniques quittent les Paramètres et vivent ici,
  // sous « Technique ». Ils s'affichent même si le résumé ne se charge pas.
  return (
    <div className="space-y-10">
      <SanteDuSysteme />
      <ReglagesTechniques />
    </div>
  );
}

function SanteDuSysteme() {
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['observability', 'summary'],
    queryFn: async () => {
      const res = await api.get<{ data: ObservabilitySummary }>('/observability/summary');
      return res.data.data;
    },
    refetchInterval: 30_000,
    // 2026-10-03 — pas de nouvel essai automatique. Avec les trois essais par
    // défaut (et 30 s de délai chacun), une réponse lente laissait l'écran
    // plus d'une minute et demie sur « Chargement… » avant d'avouer l'échec.
    // L'état d'erreur propose « Réessayer », et le rafraîchissement toutes
    // les 30 s retente de lui-même.
    retry: false,
  });

  if (isLoading) {
    return <div className="text-sm text-slate-500">Chargement de la santé du système…</div>;
  }
  if (error !== null && data === undefined) {
    // P0-3 — le composant d'erreur partagé : nature de l'échec + « Réessayer ».
    return (
      <div>
        <QueryErrorState error={error} contexte="la santé du système" onRetry={() => void refetch()} />
      </div>
    );
  }
  if (!data) {
    return null;
  }

  const totalArchived = Object.values(data.archive_reasons).reduce((a, b) => a + b, 0);

  return (
    <div className="space-y-6">
      <PageHeader
        title="Santé du système"
        subtitle="Les traitements automatiques tournent-ils bien ? Quotas, archivages, erreurs."
      />

      <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5">
        <KpiCard
          label="Erreurs d’enrichissement (24 h)"
          value={data.waterfall_errors_24h}
          icon={<AlertTriangle className="size-4" />}
          tone={data.waterfall_errors_24h > 10 ? 'rose' : 'slate'}
        />
        <KpiCard
          label="Quota Google Places (mois)"
          value={`${data.google_places_quota.used} / ${data.google_places_quota.soft_limit}`}
          sublabel={
            data.google_places_quota.pending_companies > 5000
              ? `${data.google_places_quota.pending_companies} fiches en attente : le quota est peut-être trop bas.`
              : data.google_places_quota.pending_companies > 0
              ? `${data.google_places_quota.percent}% utilisé · ${data.google_places_quota.pending_companies} en attente (reprise le 1er du mois)`
              : `${data.google_places_quota.percent}% utilisé · fiches déjà complètes ignorées`
          }
          progress={data.google_places_quota.percent}
          icon={<MapPin className="size-4" />}
          tone={
            data.google_places_quota.pending_companies > 5000
              ? 'rose'
              : data.google_places_quota.percent >= 100
              ? 'rose'
              : data.google_places_quota.percent > 80
              ? 'amber'
              : 'sky'
          }
        />
        <KpiCard
          label="Quota Hunter.io (mois)"
          value={`${data.hunter_quota_month.used} / ${data.hunter_quota_month.soft_limit}`}
          sublabel={`${data.hunter_quota_month.percent}% utilisé`}
          progress={data.hunter_quota_month.percent}
          icon={<MailCheck className="size-4" />}
          tone={data.hunter_quota_month.percent > 80 ? 'amber' : 'sky'}
        />
        <KpiCard
          label="Entreprises archivées"
          value={totalArchived}
          icon={<Archive className="size-4" />}
          tone="slate"
        />
        <KpiCard
          label="Échecs de mise à jour d’audience (7 j)"
          value={data.audience_failures_7d}
          icon={<Activity className="size-4" />}
          tone={data.audience_failures_7d > 0 ? 'amber' : 'emerald'}
        />
      </div>

      <Card>
        <CardSection title="Archivages par raison">
          {Object.entries(data.archive_reasons).length === 0 ? (
            <div className="text-sm text-slate-500">Aucun archivage enregistré.</div>
          ) : (
            <div className="grid grid-cols-2 gap-2 text-sm md:grid-cols-5">
              {motifsEnClair(data.archive_reasons).map(([libelle, count]) => (
                <div
                  key={libelle}
                  className="rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800/40"
                >
                  <div className="text-xs text-slate-500">{libelle}</div>
                  <div className="text-lg font-semibold">{count}</div>
                </div>
              ))}
            </div>
          )}
        </CardSection>
      </Card>

      <Card>
        <CardSection title="50 derniers événements">
          <div className="max-h-[480px] overflow-y-auto text-sm">
            {data.recent_events.length === 0 ? (
              <div className="text-slate-500">Aucun événement récent.</div>
            ) : (
              <table className="w-full">
                <thead className="sticky top-0 bg-white text-xs uppercase text-slate-500 dark:bg-slate-900">
                  <tr>
                    <th className="p-2 text-left">Date</th>
                    <th className="p-2 text-left">Action</th>
                    <th className="p-2 text-left">Élément</th>
                  </tr>
                </thead>
                <tbody>
                  {data.recent_events.map((event) => (
                    <tr key={event.id} className="border-t border-slate-100 dark:border-slate-800">
                      <td className="p-2 text-xs text-slate-500">
                        {new Date(event.created_at).toLocaleString('fr-FR')}
                      </td>
                      <td className="p-2 text-xs">{libelleEvenementMetier(event.action)}</td>
                      <td className="p-2 text-xs text-slate-500">
                        <ElementCell element={elementConcerne(event.resource_type, event.resource_id)} />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </CardSection>
      </Card>
    </div>
  );
}

/**
 * Les motifs d'archivage en clair, regroupés par libellé (deux codes inconnus
 * tombent tous deux sous « Autre raison » : une seule tuile, leurs nombres
 * additionnés).
 */
function motifsEnClair(motifs: Record<string, number>): Array<[string, number]> {
  const parLibelle = new Map<string, number>();
  for (const [motif, nombre] of Object.entries(motifs)) {
    const libelle = libelleMotifArchivage(motif);
    parLibelle.set(libelle, (parLibelle.get(libelle) ?? 0) + nombre);
  }
  return [...parLibelle.entries()];
}

const CLASSE_LIEN = 'text-indigo-700 hover:underline dark:text-indigo-300';

function ElementCell({ element }: { element: ElementConcerne | null }) {
  if (element === null) return <>—</>;
  const { texte, lien } = element;
  if (lien === null) return <>{texte}</>;
  switch (lien.to) {
    case '/companies/$companyId':
      return (
        <Link to="/companies/$companyId" params={lien.params} className={CLASSE_LIEN}>
          {texte}
        </Link>
      );
    case '/audiences/$audienceId':
      return (
        <Link to="/audiences/$audienceId" params={lien.params} className={CLASSE_LIEN}>
          {texte}
        </Link>
      );
    case '/listes/$listeId':
      return (
        <Link to="/listes/$listeId" params={lien.params} className={CLASSE_LIEN}>
          {texte}
        </Link>
      );
  }
}

function CardSection({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="p-4">
      <h3 className="mb-3 text-sm font-medium text-slate-700 dark:text-slate-200">{title}</h3>
      {children}
    </div>
  );
}
