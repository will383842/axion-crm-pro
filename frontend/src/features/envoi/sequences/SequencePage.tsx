/**
 * F-SEQ — fiche d'une séquence : étapes et compteurs, « Mettre en pause » (avec confirmation)
 * et « Reprendre ». La reprise refusée (409) affiche la raison du serveur, en clair.
 * Boutons masqués selon la permission ; le serveur reste l'autorité. Aucune personne affichée.
 */
import { useState } from 'react';
import { isAxiosError } from 'axios';
import { Link, useParams } from '@tanstack/react-router';
import { Pause, Play } from 'lucide-react';
import { Button, Card, EmptyState, KpiCard, Modal, PageHeader, QueryErrorState, Skeleton, StatusPill, TableScroll } from '@/components/ui';
import { OngletsCampagnes } from '@/features/envoi/campagnes/OngletsCampagnes';
import { dateHeure, etapes, nombre, STATUT_LIBELLE, STATUT_TON, useDroitsSequences, useGestesSequence, useSequence, type SequenceDetail } from './api';

const GRID_ETAPES = '48px 80px minmax(200px,2fr) repeat(4,80px)';

function messageErreur(e: unknown): string {
  if (isAxiosError(e)) {
    const m = (e.response?.data as { message?: unknown } | undefined)?.message;
    if (typeof m === 'string' && m !== '') return m;
  }
  return 'Le geste n’a pas abouti. Rien n’a changé : réessayez dans un instant.';
}

function Gestes({ s }: { s: SequenceDetail }) {
  const droits = useDroitsSequences();
  const gestes = useGestesSequence();
  const [confirmer, setConfirmer] = useState(false);
  const peutPause = droits.pause && s.statut === 'active';
  const peutReprise = droits.reprise && s.statut === 'en_pause';
  if (!peutPause && !peutReprise) return null;
  return (
    <div>
      <div className="flex flex-wrap justify-end gap-2">
        {peutPause ? (
          <Button variant="secondary" className="max-md:h-11" iconLeft={<Pause className="h-4 w-4" aria-hidden />} onClick={() => setConfirmer(true)}>
            Mettre en pause
          </Button>
        ) : null}
        {peutReprise ? (
          <Button variant="secondary" className="max-md:h-11" iconLeft={<Play className="h-4 w-4" aria-hidden />} loading={gestes.reprise.isPending} onClick={() => gestes.reprise.mutate(s.id)}>
            Reprendre
          </Button>
        ) : null}
      </div>
      {gestes.reprise.isError ? (
        <p role="alert" className="mt-2 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">
          {messageErreur(gestes.reprise.error)}
        </p>
      ) : null}
      {confirmer ? (
        <Modal
          open
          onClose={() => setConfirmer(false)}
          size="sm"
          title="Mettre en pause ?"
          footer={
            <>
              <Button variant="ghost" onClick={() => setConfirmer(false)}>
                Annuler
              </Button>
              <Button loading={gestes.pause.isPending} onClick={() => gestes.pause.mutate(s.id, { onSuccess: () => setConfirmer(false) })}>
                Mettre en pause
              </Button>
            </>
          }
        >
          <p className="text-sm text-slate-700">
            <strong className="font-semibold text-slate-900">{s.nom}</strong> s’arrête tout de suite : aucune inscription ne passe à l’étape suivante. Rien n’est perdu, la reprise est possible ensuite.
          </p>
          {gestes.pause.isError ? (
            <p role="alert" className="mt-3 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">
              {messageErreur(gestes.pause.error)}
            </p>
          ) : null}
        </Modal>
      ) : null}
    </div>
  );
}

export function SequencePage() {
  const { sequenceId } = useParams({ strict: false });
  const requete = useSequence(sequenceId ?? '');
  const s = requete.data;
  const introuvable = requete.isError && isAxiosError(requete.error) && requete.error.response?.status === 404;
  return (
    <div>
      <PageHeader title={s?.nom ?? 'Séquence'} surTitre="Envoi" subtitle={s ? `${etapes(s.nb_etapes)} · créée le ${dateHeure(s.creee_le)}` : undefined} actions={s ? <Gestes s={s} /> : undefined} />
      <OngletsCampagnes />
      {requete.isPending ? (
        <div role="status" aria-busy="true" aria-label="Chargement de la séquence" className="mt-4 space-y-2">
          <Skeleton className="h-24 w-full" />
          <Skeleton className="h-12 w-full" />
        </div>
      ) : introuvable ? (
        <div className="mt-4">
          <EmptyState title="Cette séquence n’existe pas" description="Elle a peut-être été retirée. Retournez à la liste des séquences." />
          <p className="mt-3 text-center text-sm">
            <Link to="/envoi/sequences" className="font-medium underline">
              Voir les séquences
            </Link>
          </p>
        </div>
      ) : requete.isError || !s ? (
        <div className="mt-4">
          <QueryErrorState error={requete.error} contexte="la séquence" onRetry={() => void requete.refetch()} />
        </div>
      ) : (
        <div className="mt-4 space-y-6">
          <div className="flex flex-wrap items-center gap-3 text-sm text-slate-700">
            <StatusPill tone={STATUT_TON[s.statut]}>{STATUT_LIBELLE[s.statut]}</StatusPill>
            {s.reservee_aux_temoins ? <span>Boîtes témoins seulement</span> : null}
            <span>Prochain envoi : {dateHeure(s.prochain_envoi)}</span>
          </div>
          {s.statut === 'en_pause' && s.raison_refus_reprise !== null ? (
            <p className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">{s.raison_refus_reprise}</p>
          ) : null}
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
            <KpiCard label="Inscrits" value={nombre(s.inscriptions.total)} />
            <div data-testid="inscriptions-en-cours">
              <KpiCard label="En cours" value={nombre(s.inscriptions.en_cours)} />
            </div>
            <KpiCard label="Terminées" value={nombre(s.inscriptions.terminees)} />
            <KpiCard label="Arrêtées" value={nombre(s.inscriptions.arretees)} />
            <KpiCard label="Reportées" value={nombre(s.inscriptions.reportees)} />
          </div>
          <Card className="p-4">
            <h2 className="text-lg font-bold text-slate-900">Étapes</h2>
            <TableScroll template={GRID_ETAPES} className="mt-3">
            <div role="table" aria-label="Étapes">
              <div role="row" style={{ gridTemplateColumns: GRID_ETAPES }} className="grid gap-3 border-b border-slate-100 pb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                <div role="columnheader">Étape</div>
                <div role="columnheader">Délai</div>
                <div role="columnheader">Objet</div>
                <div role="columnheader">Envoyés</div>
                <div role="columnheader">Réponses</div>
                <div role="columnheader">Rebonds</div>
                <div role="columnheader">Désinsc.</div>
              </div>
              {s.etapes.map((e) => (
                <div key={e.rang} role="row" style={{ gridTemplateColumns: GRID_ETAPES }} className="grid gap-3 border-b border-slate-50 py-2 text-sm">
                  <div role="cell">{e.rang}</div>
                  <div role="cell">{e.delai_jours === 0 ? 'Tout de suite' : `+${e.delai_jours} j`}</div>
                  <div role="cell" className="min-w-0 truncate">{e.objet}</div>
                  <div role="cell">{nombre(e.envoyes)}</div>
                  <div role="cell">{nombre(e.reponses)}</div>
                  <div role="cell">{nombre(e.rebonds_durs)}</div>
                  <div role="cell">{nombre(e.desinscriptions)}</div>
                </div>
              ))}
            </div>
            </TableScroll>
          </Card>
        </div>
      )}
    </div>
  );
}
