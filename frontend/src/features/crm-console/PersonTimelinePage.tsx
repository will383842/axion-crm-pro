/**
 * FICHE 360° — la timeline unifiée d'une personne.
 *
 * La timeline est un INDEX des touchpoints, jamais une copie de leur contenu
 * (plan §2.6) : chaque ligne référence sa source, le détail reste dans le
 * système qui l'a produit.
 *
 * L'encart « existe aussi dans l'autre univers » affiche un BOOLÉEN et rien
 * d'autre quand l'univers n'est pas accessible (plan §2.4.3). Ce n'est pas une
 * demi-mesure : sans lui, un opérateur business créerait une seconde fiche pour
 * quelqu'un qui en a déjà une — l'étanchéité produirait des doublons au lieu de
 * protéger.
 */
import { useQuery } from '@tanstack/react-query';
import { useParams } from '@tanstack/react-router';
import { Card, CardTitle, EmptyState, PageHeader, StatusPill } from '@/components/ui';
import { api } from '@/lib/api';
import { ConsoleGate, ConsoleListSkeleton } from './ConsoleGate';
import type { TimelineResponse } from './types';

export function PersonTimelinePage() {
  return (
    <ConsoleGate>
      <PersonTimelineContent />
    </ConsoleGate>
  );
}

function PersonTimelineContent() {
  const { personKey } = useParams({ from: '/layout/console/personnes/$personKey' });

  const timeline = useQuery<TimelineResponse>({
    queryKey: ['crm', 'person-timeline', personKey],
    queryFn: async () => (await api.get<TimelineResponse>(`/crm/persons/${personKey}/timeline`)).data,
  });

  if (timeline.isLoading) {
    return (
      <div className="px-6 py-6">
        <ConsoleListSkeleton rows={6} />
      </div>
    );
  }

  const data = timeline.data;
  if (data === undefined) {
    return (
      <div className="px-6 py-6">
        <EmptyState title="Fiche introuvable" description="Cette personne n’existe dans aucun univers accessible." />
      </div>
    );
  }

  const identity = data.subjects[0];
  const displayName =
    identity === undefined
      ? 'Personne'
      : [identity.first_name, identity.last_name].filter(Boolean).join(' ') || 'Personne';

  return (
    <div className="px-6 py-6">
      <PageHeader title={displayName} subtitle="Fiche 360° — tous les touchpoints connus de cette personne." />

      <div className="grid gap-4 lg:grid-cols-[320px_1fr]">
        <div className="flex flex-col gap-4">
          <Card>
            <CardTitle>Identité</CardTitle>
            {data.subjects.length === 0 ? (
              <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">
                Aucune fiche dans les univers auxquels vous avez accès.
              </p>
            ) : (
              <ul className="mt-2 flex flex-col gap-3 text-xs text-slate-600 dark:text-slate-300">
                {data.subjects.map((subject) => (
                  <li key={`${subject.universe}-${subject.type}-${subject.id}`}>
                    <div className="font-medium text-slate-900 dark:text-white">
                      {[subject.first_name, subject.last_name].filter(Boolean).join(' ')}
                    </div>
                    <div>{subject.email ?? '—'}</div>
                    {subject.company !== undefined && subject.company !== null && (
                      <div className="text-slate-400">{subject.company.denomination ?? subject.company.siren}</div>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </Card>

          <Card>
            <CardTitle>Univers</CardTitle>
            <ul className="mt-2 flex flex-col gap-2 text-xs">
              <UniverseLine
                label="Business"
                accessible={data.universes.business.accessible}
                exists={data.universes.business.exists}
              />
              <UniverseLine
                label="Vivier candidats"
                accessible={data.universes.vivier.accessible}
                exists={data.universes.vivier.exists}
              />
            </ul>
          </Card>
        </div>

        <Card>
          <CardTitle>Timeline</CardTitle>
          {/* D25-011 — `data.data` vient d'une reponse d'API que rien ne valide
              a l'execution : sans `?.`, une clef absente jette et emporte tout
              l'ecran (timeline ET l'encart « univers » au-dessus). On accepte
              ici de confondre « absent » et « vide » : l'ecran ne sait de toute
              facon pas distinguer les deux, et un ecran blanc est pire.
              Mesure du 2026-08-22. */}
          {(data.data?.length ?? 0) === 0 ? (
            <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">Aucun touchpoint enregistré.</p>
          ) : (
            <ol className="mt-3 flex flex-col gap-3">
              {data.data.map((entry) => (
                <li key={`${entry.universe}-${entry.id}`} className="border-l-2 border-slate-200 pl-3 dark:border-slate-700">
                  <div className="text-xs text-slate-400">{entry.occurred_at ?? '—'}</div>
                  <div className="text-sm text-slate-900 dark:text-white">{entry.title ?? entry.kind}</div>
                  <div className="text-[11px] text-slate-400">
                    {entry.universe === 'vivier' ? 'Vivier' : 'Business'}
                    {entry.external_ref !== null && <> · {entry.external_ref}</>}
                  </div>
                </li>
              ))}
            </ol>
          )}
        </Card>
      </div>
    </div>
  );
}

function UniverseLine({
  label,
  accessible,
  exists,
}: {
  label: string;
  accessible: boolean;
  exists: boolean;
}) {
  return (
    <li className="flex items-center justify-between gap-2">
      <span className="text-slate-600 dark:text-slate-300">{label}</span>
      {!exists ? (
        <StatusPill tone="neutral">Aucune fiche</StatusPill>
      ) : accessible ? (
        <StatusPill tone="success">Fiche présente</StatusPill>
      ) : (
        // Un booléen, et rien d'autre : ni nom, ni étape, ni activité.
        <StatusPill tone="info">Existe — basculer d’univers pour voir</StatusPill>
      )}
    </li>
  );
}
