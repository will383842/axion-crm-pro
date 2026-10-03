import { Card, CardHeader, CardTitle, StatusPill, mapStatusToTone, cn } from '@/components/ui';

export interface TimelineStep {
  source: string;
  status: string;
  label?: string;
  at?: string | null;
  detail?: string | null;
}

/**
 * Statut d'une source, EN CLAIR (constaté en production le 03/10/2026 :
 * « Base Adresse Nationale · success »).
 *
 * La fiche lit `companies.signals` (voir `deriveTimelineFromSignals`), PAS
 * les traces de passage des sources. Aujourd'hui, aucun signal ne porte de
 * champ `status` : seules arrivent les valeurs par défaut posées ci-dessous,
 * `success` (signal présent) et `pending` (signal vide). La table couvre
 * malgré tout les autres valeurs plausibles (`failed`, `partial`, `skipped`,
 * `not_found`…) pour le jour où un signal en portera une. Les synonymes sont
 * regroupés ; une valeur jamais vue devient « Autre » plutôt que de laisser
 * passer un mot anglais.
 */
const STATUTS_SOURCE: ReadonlyArray<readonly [ReadonlyArray<string>, string]> = [
  [['success', 'succeeded', 'completed', 'done', 'ok', 'enriched'], 'Réussi'],
  [['failed', 'failure', 'error', 'gave_up'], 'Échec'],
  [['skipped', 'ignored'], 'Ignoré'],
  [['partial', 'partielle'], 'Partiel'],
  [['pending', 'queued', 'waiting'], 'En attente'],
  [['running', 'in_progress', 'processing', 'enriching'], 'En cours'],
  [['not_found', 'no_result', 'no_results', 'empty'], 'Rien trouvé'],
  [['rate_limited', 'throttled', 'quota_exceeded'], 'Limite atteinte'],
  [['cancelled', 'canceled'], 'Annulé'],
];

export function libelleStatutSource(statut: string | null | undefined): string {
  const s = (statut ?? '').trim().toLowerCase();
  for (const [valeurs, libelle] of STATUTS_SOURCE) {
    if (valeurs.includes(s)) return libelle;
  }
  return 'Autre';
}

/**
 * Derive a timeline from the company's `signals` object when no explicit
 * enrichment_runs is available. Falls back to a small set of well-known
 * sources (INSEE, BAN, France Travail, Mistral) when present.
 */
export function deriveTimelineFromSignals(signals?: Record<string, unknown> | null): TimelineStep[] {
  if (!signals || typeof signals !== 'object') return [];
  const known: Array<{ key: string; label: string }> = [
    { key: 'insee', label: 'INSEE Sirene' },
    { key: 'ban', label: 'Base Adresse Nationale' },
    { key: 'france_travail', label: 'France Travail' },
    { key: 'mistral', label: 'IA Mistral (activité en clair)' },
    { key: 'website', label: 'Lecture du site web' },
    { key: 'linkedin', label: 'LinkedIn' },
  ];
  const steps: TimelineStep[] = [];
  for (const k of known) {
    if (k.key in signals) {
      const raw = (signals as Record<string, unknown>)[k.key];
      const obj = raw && typeof raw === 'object' ? (raw as Record<string, unknown>) : null;
      const status = obj && typeof obj.status === 'string' ? (obj.status as string) : raw ? 'success' : 'pending';
      const at = obj && typeof obj.at === 'string' ? (obj.at as string) : null;
      const detail = obj && typeof obj.detail === 'string' ? (obj.detail as string) : null;
      steps.push({ source: k.key, label: k.label, status, at, detail });
    }
  }
  return steps;
}

export function EnrichmentTimeline({ steps }: { steps: TimelineStep[] }) {
  return (
    <Card padding="md">
      <CardHeader>
        <CardTitle>Sources consultées</CardTitle>
      </CardHeader>
      {steps.length === 0 ? (
        <p className="text-sm text-slate-500 dark:text-slate-400">
          Aucune trace d'enrichissement disponible pour le moment.
        </p>
      ) : (
        <ol className="relative ml-2 space-y-4 border-l border-slate-200 pl-5 dark:border-slate-800">
          {steps.map((s, i) => {
            const tone = mapStatusToTone(s.status);
            const dotTone =
              tone === 'success' ? 'bg-emerald-500'
                : tone === 'danger' ? 'bg-rose-500'
                : tone === 'running' ? 'bg-sky-500'
                : tone === 'warning' ? 'bg-amber-500'
                : 'bg-slate-400';
            return (
              <li key={`${s.source}-${i}`} className="relative">
                <span
                  className={cn('absolute -left-[27px] top-1.5 inline-block h-2.5 w-2.5 rounded-full ring-2 ring-white dark:ring-slate-900', dotTone)}
                  aria-hidden
                />
                <div className="flex flex-wrap items-center gap-2">
                  <span className="text-sm font-medium text-slate-900 dark:text-white">
                    {s.label ?? s.source}
                  </span>
                  <StatusPill tone={tone}>{libelleStatutSource(s.status)}</StatusPill>
                  {s.at ? (
                    <span className="text-xs tabular-nums text-slate-500 dark:text-slate-400">
                      {new Date(s.at).toLocaleString('fr-FR')}
                    </span>
                  ) : null}
                </div>
                {s.detail ? (
                  <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{s.detail}</p>
                ) : null}
              </li>
            );
          })}
        </ol>
      )}
    </Card>
  );
}
