/**
 * PROPOSITIONS À VALIDER (N13, 03/10/2026) — réservé au propriétaire.
 *
 * Quand une information venue d'un partenaire (apporteur d'affaires,
 * commercial, société partenaire) ne correspond pas à ce que dit déjà une
 * fiche, elle ne remplace JAMAIS la valeur existante : elle attend ici. Une
 * ligne = la fiche, l'information concernée, la valeur actuelle → la valeur
 * proposée, qui la propose, et deux boutons : Accepter / Refuser.
 *
 * La décision est prise par le serveur (403 pour tout autre rôle) ; cet écran
 * ne fait que la demander. Libellés en français simple, sans jargon ; lisible
 * sur téléphone (une carte par ligne, boutons pleine largeur).
 */
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Button, Card, EmptyState, PageHeader, QueryErrorState } from '@/components/ui';
import { api, messageApiLisible } from '@/lib/api';
import { ConsoleGate, ConsoleListSkeleton } from '@/features/crm-console/ConsoleGate';
import { COMPTEURS_A_TRAITER_KEY, formaterNombre } from '@/features/a-traiter/compteurs';

export type OriginePartenaire = 'apporteur' | 'commercial' | 'societe';

export interface Proposition {
  id: number;
  entite: 'entreprise' | 'personne';
  entite_id: number;
  entreprise_id: number | null;
  fiche: string | null;
  champ: string;
  libelle_champ: string;
  valeur_actuelle: string | null;
  valeur_proposee: string;
  origine: OriginePartenaire;
  recue_le: string | null;
}

export interface PropositionsResponse {
  data: Proposition[];
  meta: { total: number; per_page: number; page: number };
}

const PAR_PAGE = 25;

export const PROPOSITIONS_KEY = ['crm', 'propositions'] as const;

/** Qui propose, en mots simples. */
export const LIBELLES_ORIGINE: Record<OriginePartenaire, string> = {
  apporteur: 'Un apporteur d’affaires',
  commercial: 'Un commercial partenaire',
  societe: 'Une société partenaire',
};

export function PropositionsPage() {
  return (
    <ConsoleGate>
      <PropositionsContent />
    </ConsoleGate>
  );
}

function PropositionsContent() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);

  const liste = useQuery<PropositionsResponse>({
    queryKey: [...PROPOSITIONS_KEY, page],
    queryFn: async () =>
      (await api.get<PropositionsResponse>(`/crm/propositions?per_page=${PAR_PAGE}&page=${page}`)).data,
  });

  const rafraichir = () => {
    void queryClient.invalidateQueries({ queryKey: PROPOSITIONS_KEY });
    // La pastille du menu suit le geste.
    void queryClient.invalidateQueries({ queryKey: COMPTEURS_A_TRAITER_KEY });
  };

  const decider = useMutation({
    mutationFn: async (input: { id: number; choix: 'accepter' | 'refuser' }) =>
      (await api.post<{ statut: string }>(`/crm/propositions/${input.id}/${input.choix}`)).data,
    onSuccess: (_data, input) => {
      toast.success(
        input.choix === 'accepter'
          ? 'C’est fait : la fiche a été mise à jour.'
          : 'C’est noté : la fiche reste telle quelle.',
      );
      rafraichir();
    },
    onError: (err) => {
      toast.error(messageApiLisible(err) ?? 'Cela n’a pas fonctionné. Réessayez dans un instant.');
      rafraichir();
    },
  });

  const lignes = liste.data?.data ?? [];
  // L'échec n'est pas une file vide (patron de « Personnes à rattacher »).
  const echec = liste.error !== null && liste.data === undefined;
  const total = liste.data?.meta.total;
  const pages = total === undefined ? 1 : Math.max(1, Math.ceil(total / PAR_PAGE));

  return (
    <div>
      <PageHeader
        title="Propositions à valider"
        subtitle={
          total === undefined
            ? 'Des partenaires proposent de changer des informations. Rien ne change sans votre accord.'
            : `${total === 1 ? '1 proposition' : `${formaterNombre(total)} propositions`} à valider. Rien ne change sans votre accord.`
        }
      />

      {echec ? (
        <QueryErrorState error={liste.error} contexte="les propositions" onRetry={() => void liste.refetch()} />
      ) : liste.isLoading ? (
        <ConsoleListSkeleton rows={4} />
      ) : lignes.length === 0 ? (
        <EmptyState
          title="Aucune proposition à valider"
          description="Quand un partenaire proposera une information différente de celle d’une fiche, elle apparaîtra ici."
        />
      ) : (
        <>
          <ul className="flex flex-col gap-3" aria-label="Propositions à valider">
            {lignes.map((p) => (
              <li key={p.id}>
                <CarteProposition
                  proposition={p}
                  occupe={decider.isPending}
                  onAccepter={() => decider.mutate({ id: p.id, choix: 'accepter' })}
                  onRefuser={() => decider.mutate({ id: p.id, choix: 'refuser' })}
                />
              </li>
            ))}
          </ul>
          {pages > 1 ? (
            <nav className="mt-4 flex items-center justify-between gap-2" aria-label="Pages">
              <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => setPage((n) => n - 1)}>
                Page précédente
              </Button>
              <span className="text-xs text-slate-500 dark:text-slate-400">
                Page {page} sur {pages}
              </span>
              <Button variant="secondary" size="sm" disabled={page >= pages} onClick={() => setPage((n) => n + 1)}>
                Page suivante
              </Button>
            </nav>
          ) : null}
        </>
      )}
    </div>
  );
}

function CarteProposition({
  proposition: p,
  occupe,
  onAccepter,
  onRefuser,
}: {
  proposition: Proposition;
  occupe: boolean;
  onAccepter: () => void;
  onRefuser: () => void;
}) {
  const type = p.entite === 'entreprise' ? 'Entreprise' : 'Personne';
  return (
    <Card>
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="min-w-0 flex-1">
          <div className="text-xs text-slate-500 dark:text-slate-400">
            {type} · {LIBELLES_ORIGINE[p.origine] ?? 'Un partenaire'} propose
          </div>
          <div className="mt-0.5 break-words text-sm font-semibold text-slate-900 dark:text-white">
            {p.fiche ?? 'Fiche introuvable'}
          </div>
          <div className="mt-2 text-sm text-slate-700 dark:text-slate-200">
            <span className="font-medium">{p.libelle_champ}</span>
            <span className="mt-1 flex flex-col gap-1 sm:flex-row sm:flex-wrap sm:items-center sm:gap-2">
              <span className="break-words text-slate-500 line-through decoration-slate-400 dark:text-slate-400">
                <span className="sr-only">Valeur actuelle : </span>
                {p.valeur_actuelle ?? '(vide)'}
              </span>
              <span aria-hidden="true" className="text-slate-400">
                →
              </span>
              <span className="break-words font-semibold text-slate-900 dark:text-white">
                <span className="sr-only">Valeur proposée : </span>
                {p.valeur_proposee}
              </span>
            </span>
          </div>
        </div>
        <div className="flex gap-2 sm:shrink-0">
          <Button
            variant="primary"
            size="sm"
            className="flex-1 sm:flex-none"
            disabled={occupe}
            onClick={onAccepter}
            aria-label={`Accepter : ${p.libelle_champ} de ${p.fiche ?? 'la fiche'}`}
          >
            Accepter
          </Button>
          <Button
            variant="secondary"
            size="sm"
            className="flex-1 sm:flex-none"
            disabled={occupe}
            onClick={onRefuser}
            aria-label={`Refuser : ${p.libelle_champ} de ${p.fiche ?? 'la fiche'}`}
          >
            Refuser
          </Button>
        </div>
      </div>
    </Card>
  );
}
