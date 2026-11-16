/**
 * F-SEQ — liste des séquences d'Envoi : statut, étapes, inscriptions, prochain envoi.
 * Des compteurs seulement : aucune personne n'est affichée.
 */
import { Link } from '@tanstack/react-router';
import { EmptyState, PageHeader, QueryErrorState, Skeleton, StatusPill, TableScroll } from '@/components/ui';
import { OngletsCampagnes } from '@/features/envoi/campagnes/OngletsCampagnes';
import { dateHeure, etapes, nombre, STATUT_LIBELLE, STATUT_TON, useSequences } from './api';

const GRID = 'minmax(220px,2.4fr) 112px 96px 80px 80px 140px';

export function SequencesPage() {
  const requete = useSequences();
  const lignes = requete.data?.sequences ?? [];
  return (
    <div>
      <PageHeader title="Séquences" surTitre="Envoi" subtitle="Vos suites de un à trois messages. Les chiffres sont des compteurs : aucune personne n’est affichée ici." />
      <OngletsCampagnes />
      {requete.isPending ? (
        <div role="status" aria-busy="true" aria-label="Chargement des séquences" className="mt-4 space-y-2">
          <Skeleton className="h-12 w-full" />
          <Skeleton className="h-12 w-full" />
        </div>
      ) : requete.isError ? (
        <div className="mt-4">
          <QueryErrorState error={requete.error} contexte="la liste des séquences" onRetry={() => void requete.refetch()} />
        </div>
      ) : lignes.length === 0 ? (
        <div className="mt-4">
          <EmptyState title="Aucune séquence pour l’instant" description="Une séquence enchaîne jusqu’à trois messages pour une même cible. Elle est créée automatiquement quand vous programmez une campagne : rien à faire ici." />
        </div>
      ) : (
        <TableScroll template={GRID} className="mt-4 rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <div role="table" aria-label="Séquences">
          <div role="row" style={{ gridTemplateColumns: GRID }} className="grid gap-3 border-b border-slate-100 bg-slate-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
            <div role="columnheader">Séquence</div>
            <div role="columnheader">Statut</div>
            <div role="columnheader">Étapes</div>
            <div role="columnheader">Inscrits</div>
            <div role="columnheader">En cours</div>
            <div role="columnheader">Prochain envoi</div>
          </div>
          <div role="rowgroup" className="divide-y divide-slate-100">
            {lignes.map((s) => (
              <div key={s.id} role="row" aria-label={s.nom} style={{ gridTemplateColumns: GRID }} className="grid items-center gap-3 px-4 py-3 text-sm">
                <div role="cell" className="min-w-0">
                  <Link to="/envoi/sequences/$sequenceId" params={{ sequenceId: s.id }} className="font-semibold text-slate-900 underline-offset-2 hover:underline">
                    {s.nom}
                  </Link>
                  {s.reservee_aux_temoins ? <span className="ml-2 text-xs text-slate-500">Boîtes témoins seulement</span> : null}
                </div>
                <div role="cell">
                  <StatusPill tone={STATUT_TON[s.statut]}>{STATUT_LIBELLE[s.statut]}</StatusPill>
                </div>
                <div role="cell">{etapes(s.nb_etapes)}</div>
                <div role="cell">{nombre(s.inscriptions.total)}</div>
                <div role="cell">{nombre(s.inscriptions.en_cours)}</div>
                <div role="cell" className="text-slate-600">{dateHeure(s.prochain_envoi)}</div>
              </div>
            ))}
          </div>
        </div>
        </TableScroll>
      )}
    </div>
  );
}
