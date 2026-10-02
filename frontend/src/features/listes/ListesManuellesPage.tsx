/**
 * LISTES MANUELLES (2026-09-30) — des fiches choisies à la main, sous un nom.
 *
 * On coche des fiches dans Entreprises / Contacts (ou depuis une fiche), ou on
 * importe un fichier (SIREN, identifiants, adresses déjà présentes dans le
 * CRM) ; la liste sert ensuite de critère d'audience : « membres de la liste
 * X », « sauf liste Y ». Une liste ne se supprime pas : elle va à la corbeille.
 */
import { useState } from 'react';
import { Link } from '@tanstack/react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { ListChecks, Plus, RotateCcw } from 'lucide-react';
import { Button, Card, EmptyState, Input, PageHeader, QueryErrorState, Spinner } from '@/components/ui';
import { api } from '@/lib/api';
import { chargerListes, creerListe, messageServeur, type ListeManuelle } from './listes';

export function ListesManuellesPage() {
  const qc = useQueryClient();
  const [corbeille, setCorbeille] = useState(false);
  const [nom, setNom] = useState('');
  const [description, setDescription] = useState('');

  const listes = useQuery({
    queryKey: ['listes-manuelles', { corbeille }],
    queryFn: () => chargerListes({ corbeille }),
  });

  const creation = useMutation({
    mutationFn: () => creerListe(nom.trim(), description.trim()),
    onSuccess: (l) => {
      toast.success(`Liste « ${l.nom} » créée.`);
      setNom('');
      setDescription('');
      void qc.invalidateQueries({ queryKey: ['listes-manuelles'] });
    },
    onError: (e) => toast.error(messageServeur(e) ?? 'Création impossible.'),
  });

  const restauration = useMutation({
    mutationFn: async (id: number) => (await api.post<unknown>(`/listes-manuelles/${id}/restaurer`)).data,
    onSuccess: () => {
      toast.success('Liste sortie de la corbeille.');
      void qc.invalidateQueries({ queryKey: ['listes-manuelles'] });
    },
    onError: (e) => toast.error(messageServeur(e) ?? 'Restauration impossible.'),
  });

  const lignes = listes.data ?? [];

  return (
    <div className="px-6 py-6">
      <PageHeader
        title="Listes"
        subtitle="Des fiches choisies à la main, sous un nom — pour viser (ou exclure) exactement ces organisations et ces personnes dans une audience."
      />

      <Card padding="md" className="mb-6">
        <form
          className="flex flex-wrap items-end gap-3"
          onSubmit={(e) => {
            e.preventDefault();
            if (nom.trim() !== '') creation.mutate();
          }}
        >
          <label className="flex flex-col gap-1 text-xs font-semibold text-slate-600 dark:text-slate-300">
            Nom de la liste
            <Input
              value={nom}
              maxLength={160}
              placeholder="Ex. : Invités salon GOFAB"
              onChange={(e) => setNom(e.target.value)}
              className="w-72"
            />
          </label>
          <label className="flex flex-col gap-1 text-xs font-semibold text-slate-600 dark:text-slate-300">
            Description (facultative)
            <Input
              value={description}
              maxLength={1000}
              placeholder="Pour quelle campagne ?"
              onChange={(e) => setDescription(e.target.value)}
              className="w-80"
            />
          </label>
          <Button
            type="submit"
            size="md"
            iconLeft={<Plus className="h-4 w-4" />}
            disabled={nom.trim() === '' || creation.isPending}
            loading={creation.isPending}
          >
            Créer la liste
          </Button>
        </form>
      </Card>

      <div className="mb-3 flex items-center gap-2 text-sm">
        <Button size="sm" variant={corbeille ? 'ghost' : 'secondary'} onClick={() => setCorbeille(false)} aria-pressed={!corbeille}>
          Listes
        </Button>
        <Button size="sm" variant={corbeille ? 'secondary' : 'ghost'} onClick={() => setCorbeille(true)} aria-pressed={corbeille}>
          Corbeille
        </Button>
      </div>

      {listes.isLoading ? (
        <div className="flex h-40 items-center justify-center">
          <Spinner />
        </div>
      ) : listes.error !== null ? (
        <QueryErrorState error={listes.error} contexte="les listes manuelles" onRetry={() => void listes.refetch()} />
      ) : lignes.length === 0 ? (
        <EmptyState
          icon={<ListChecks className="h-10 w-10" />}
          title={corbeille ? 'La corbeille est vide' : 'Aucune liste pour l’instant'}
          description={
            corbeille
              ? 'Une liste mise à la corbeille reste ici, avec ses fiches, et peut en sortir.'
              : 'Créez une liste, puis cochez des fiches dans Entreprises ou Contacts, ou importez un fichier.'
          }
        />
      ) : (
        <Card padding="none" className="overflow-hidden">
          <table className="w-full text-sm">
            <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
              <tr>
                <th className="px-4 py-2.5">Liste</th>
                <th className="px-4 py-2.5">Organisations</th>
                <th className="px-4 py-2.5">Personnes</th>
                <th className="px-4 py-2.5">Modifiée</th>
                {corbeille ? <th className="px-4 py-2.5"><span className="sr-only">Actions</span></th> : null}
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
              {lignes.map((l: ListeManuelle) => (
                <tr key={l.id} data-testid={`liste-${l.id}`}>
                  <td className="px-4 py-2.5">
                    {corbeille ? (
                      <span className="font-medium text-slate-700 dark:text-slate-200">{l.nom}</span>
                    ) : (
                      <Link
                        to="/listes/$listeId"
                        params={{ listeId: String(l.id) }}
                        className="font-medium text-indigo-700 hover:underline dark:text-indigo-300"
                      >
                        {l.nom}
                      </Link>
                    )}
                    {l.description !== null && l.description !== '' ? (
                      <div className="text-xs text-slate-500 dark:text-slate-400">{l.description}</div>
                    ) : null}
                  </td>
                  <td className="px-4 py-2.5 tabular-nums">{l.organisations.toLocaleString('fr-FR')}</td>
                  <td className="px-4 py-2.5 tabular-nums">{l.personnes.toLocaleString('fr-FR')}</td>
                  <td className="px-4 py-2.5 text-xs text-slate-500">
                    {l.updated_at !== null ? new Date(l.updated_at).toLocaleDateString('fr-FR') : '—'}
                  </td>
                  {corbeille ? (
                    <td className="px-4 py-2.5 text-right">
                      <Button
                        size="sm"
                        variant="secondary"
                        iconLeft={<RotateCcw className="h-3.5 w-3.5" />}
                        disabled={restauration.isPending}
                        onClick={() => restauration.mutate(l.id)}
                      >
                        Sortir de la corbeille
                      </Button>
                    </td>
                  ) : null}
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}
    </div>
  );
}
