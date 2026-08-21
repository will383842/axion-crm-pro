/**
 * ⚖️ RAPPROCHEMENTS À ARBITRER.
 *
 * Ces lignes existent parce que l'ingestion REFUSE de deviner : sans SIREN,
 * rapprocher une entreprise par sa dénomination est une proposition, jamais une
 * certitude (4,29 M de fiches INSEE en face). Cet écran est la contrepartie de
 * ce refus — sans lui, refuser de deviner serait perdre la donnée.
 *
 * Deux issues, et DEUX seulement :
 *   - rattacher à une entreprise (la fiche personne est créée, dédoublonnée par
 *     le même code que l'ingestion) ;
 *   - écarter AVEC UN MOTIF. Sans cette seconde porte, l'opérateur finit par
 *     rattacher au hasard pour vider l'écran.
 */
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Button, Card, EmptyState, Input, PageHeader, QueryErrorState } from '@/components/ui';
import { api } from '@/lib/api';
import { ConsoleGate, ConsoleListSkeleton } from './ConsoleGate';
import type { ArbitrageResponse, ArbitrageRow } from './types';

export function ArbitragePage() {
  return (
    <ConsoleGate>
      <ArbitrageContent />
    </ConsoleGate>
  );
}

function ArbitrageContent() {
  const queryClient = useQueryClient();

  const list = useQuery<ArbitrageResponse>({
    queryKey: ['crm', 'arbitrage'],
    queryFn: async () => (await api.get<ArbitrageResponse>('/crm/arbitrage?per_page=50')).data,
  });

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ['crm', 'arbitrage'] });
    void queryClient.invalidateQueries({ queryKey: ['crm', 'contacts-hub'] });
  };

  const attach = useMutation({
    mutationFn: async (input: { activityId: number; companyId: number }) =>
      (
        await api.post<{ contact_created: boolean }>(`/crm/arbitrage/${input.activityId}/attach`, {
          company_id: input.companyId,
        })
      ).data,
    onSuccess: (data) => {
      toast.success(
        data.contact_created
          ? 'Rattaché — fiche personne créée ou retrouvée.'
          : "Rattaché — aucun nom transmis, aucune fiche personne fabriquée.",
      );
      invalidate();
    },
    onError: () => toast.error('Rattachement impossible : vérifiez l’identifiant d’entreprise.'),
  });

  const dismiss = useMutation({
    mutationFn: async (input: { activityId: number; reason: string }) =>
      (
        await api.post<{ dismissed: boolean }>(`/crm/arbitrage/${input.activityId}/dismiss`, {
          reason: input.reason,
        })
      ).data,
    onSuccess: () => {
      toast.success('Écarté de la file, avec son motif.');
      invalidate();
    },
    onError: () => toast.error('Le motif est obligatoire (3 caractères minimum).'),
  });

  const rows = list.data?.data ?? [];

  /**
   * 🔴 D25-001 — l'échec n'est PAS un état vide.
   *
   * Avant ce correctif, `list.data?.data ?? []` rendait `[]` sous 403 comme
   * sous 500, et l'écran affichait « Rien à arbitrer — tous les événements
   * entrants ont trouvé leur entreprise ». Mesuré : le texte rendu sous 403,
   * sous 500 et sous 200-liste-vide était STRICTEMENT le même. Un opérateur
   * sans le rôle lisait donc que la file était traitée, alors qu'il n'avait
   * simplement pas le droit de la lire.
   *
   * ⚠️ `list.data === undefined` fait partie de la condition, et ce n'est pas
   * une précaution de style : React Query v5 CONSERVE la dernière page réussie
   * quand un rafraîchissement échoue (`status: 'error'`, `data` intacte).
   * Effacer l'écran dans ce cas ferait PERDRE à l'opérateur la file qu'il avait
   * sous les yeux — on remplacerait un mensonge par une régression.
   */
  const echec = list.error !== null && list.data === undefined;

  /**
   * 🔴 D25-003 — LE ZÉRO N'EST PAS SEULEMENT AFFICHÉ SOUS ÉCHEC.
   *
   * Le correctif D25-001 avait neutralisé le compteur sous `echec`. Mais
   * `list.data?.meta.total ?? 0` vaut AUSSI `0` PENDANT LE CHARGEMENT : entre
   * le montage et la réponse, l'écran annonçait « 0 événement(s) reçus sans
   * SIREN » sans avoir rien lu du tout. Mesuré (garde
   * `arbitrage-conclusion-metier.test.tsx`, cas « PENDANT LE CHARGEMENT »).
   *
   * On chiffre donc sur la SEULE condition qui l'autorise : une réponse
   * existe. `undefined` couvre l'échec ET l'en-vol, sans les confondre — la
   * branche d'affichage plus bas les distingue, elle.
   */
  const total = list.data?.meta.total;

  return (
    <div className="px-6 py-6">
      <PageHeader
        title="Rapprochements à arbitrer"
        subtitle={
          total === undefined
            ? 'Événements reçus sans SIREN — à rattacher ou à écarter.'
            : `${total} événement(s) reçus sans SIREN — à rattacher ou à écarter.`
        }
      />

      {echec ? (
        <QueryErrorState
          error={list.error}
          contexte="la file d’arbitrage"
          onRetry={() => void list.refetch()}
        />
      ) : list.isLoading ? (
        <ConsoleListSkeleton rows={5} />
      ) : rows.length === 0 ? (
        /*
          🔴 D25-003 — CE TEXTE DISAIT : « Tous les événements entrants ont
          trouvé leur entreprise. »

          Ce n'était pas un compteur à zéro, c'était une AFFIRMATION SUR L'ÉTAT
          DU SYSTÈME — et une affirmation que cet écran n'est pas en position de
          faire. Il ne voit QUE ce que l'ingestion lui dépose ; il ne sait rien
          des événements qui n'y sont jamais arrivés. B13-001 mesure justement
          qu'aucun émetteur du site ne transmet de SIREN : la phrase était donc
          fausse même quand la file était réellement vide, et fausse de la façon
          la plus rassurante possible. Un dirigeant qui l'a lue en concluait que
          le rapprochement automatique fonctionnait parfaitement.

          Ce qui la remplace est un FAIT, borné à ce que l'écran a vraiment lu,
          et qui nomme sa propre limite.
        */
        <EmptyState
          title="Rien à arbitrer"
          description={
            'Aucun événement n’attend d’arbitrage dans cette file. ' +
            'Cet écran ne voit que ce que l’ingestion y dépose : il ne dit rien ' +
            'des événements qui ne sont jamais arrivés jusqu’ici.'
          }
        />
      ) : (
        <div className="flex flex-col gap-3">
          {rows.map((row) => (
            <ArbitrageCard
              key={row.activity_id}
              row={row}
              onAttach={(companyId) => attach.mutate({ activityId: row.activity_id, companyId })}
              onDismiss={(reason) => dismiss.mutate({ activityId: row.activity_id, reason })}
              busy={attach.isPending || dismiss.isPending}
            />
          ))}
        </div>
      )}
    </div>
  );
}

function ArbitrageCard({
  row,
  onAttach,
  onDismiss,
  busy,
}: {
  row: ArbitrageRow;
  onAttach: (companyId: number) => void;
  onDismiss: (reason: string) => void;
  busy: boolean;
}) {
  const [companyId, setCompanyId] = useState('');
  const [reason, setReason] = useState('');

  const match = row.pending_match;
  const parsedCompanyId = Number.parseInt(companyId, 10);

  return (
    <Card>
      <div className="text-sm font-semibold text-slate-900 dark:text-white">
        {match.denomination ?? 'Entreprise non nommée'}
      </div>
      <div className="mt-1 text-xs text-slate-500 dark:text-slate-400">
        {row.title ?? row.kind} · {row.occurred_at ?? '—'}
      </div>
      <dl className="mt-2 grid gap-x-6 gap-y-1 text-xs text-slate-600 sm:grid-cols-2 dark:text-slate-300">
        <Field label="Personne" value={[match.first_name, match.last_name].filter(Boolean).join(' ')} />
        <Field label="E-mail" value={match.email} />
        <Field label="Code postal" value={match.postcode} />
        <Field label="Ville" value={match.city} />
      </dl>

      <div className="mt-3 flex flex-wrap items-end gap-2">
        <label className="flex flex-col gap-1 text-xs text-slate-500 dark:text-slate-400">
          Identifiant d’entreprise
          <Input
            value={companyId}
            onChange={(event) => setCompanyId(event.target.value)}
            placeholder="ex. 1842"
            className="w-40"
            inputMode="numeric"
          />
        </label>
        <Button
          variant="primary"
          size="sm"
          disabled={busy || Number.isNaN(parsedCompanyId)}
          onClick={() => onAttach(parsedCompanyId)}
        >
          Rattacher
        </Button>

        <label className="flex flex-1 flex-col gap-1 text-xs text-slate-500 dark:text-slate-400">
          Motif d’écartement
          <Input
            value={reason}
            onChange={(event) => setReason(event.target.value)}
            placeholder="ex. entreprise inexistante au RNE"
          />
        </label>
        <Button
          variant="secondary"
          size="sm"
          disabled={busy || reason.trim().length < 3}
          onClick={() => onDismiss(reason.trim())}
        >
          Écarter
        </Button>
      </div>
    </Card>
  );
}

function Field({ label, value }: { label: string; value: string | null | undefined }) {
  return (
    <div className="flex gap-2">
      <dt className="text-slate-400">{label}</dt>
      <dd className="font-medium">{value !== undefined && value !== null && value !== '' ? value : '—'}</dd>
    </div>
  );
}
