/**
 * Corbeille des contacts (DM1-CON) : les personnes retirées, restaurables.
 *
 * Retirer ne supprime jamais une personne (règle de fer n° 2) : la fiche garde
 * son historique et revient d'un geste.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Button, EmptyState, Modal, QueryErrorState } from '@/components/ui';
import { api } from '@/lib/api';
import { dateFr, messageServeur, type CorbeilleLigne } from '../ajout-rapide/types';

export function CorbeilleContacts({ open, onClose }: { open: boolean; onClose: () => void }) {
  const client = useQueryClient();
  const liste = useQuery<{ data: CorbeilleLigne[] }>({
    queryKey: ['crm', 'contacts-corbeille'],
    enabled: open,
    queryFn: async ({ signal }) => (await api.get<{ data: CorbeilleLigne[] }>('/crm/contacts/corbeille', { signal })).data,
  });

  const restaurer = useMutation({
    mutationFn: async (id: number) => {
      await api.post(`/crm/contacts/${id}/restaurer`);
    },
    onSuccess: () => {
      toast.success('Fiche restaurée');
      void client.invalidateQueries({ queryKey: ['crm'] });
    },
    onError: (e) => toast.error(messageServeur(e) ?? 'La restauration n’a pas pu se faire.'),
  });

  const lignes = liste.data?.data ?? [];

  return (
    <Modal open={open} onClose={onClose} title="Corbeille" description="Les personnes retirées ne sont jamais supprimées : vous pouvez les restaurer à tout moment." size="lg">
      {liste.error !== null && liste.data === undefined ? (
        <QueryErrorState error={liste.error} contexte="la corbeille" onRetry={() => void liste.refetch()} />
      ) : liste.isLoading ? (
        <p className="text-sm text-slate-500" role="status">
          Chargement de la corbeille…
        </p>
      ) : lignes.length === 0 ? (
        <EmptyState title="La corbeille est vide" description="Les fiches que vous retirez arrivent ici, prêtes à être restaurées." />
      ) : (
        <ul className="divide-y divide-slate-100">
          {lignes.map((l) => (
            <li key={l.id} className="flex flex-wrap items-center justify-between gap-2 py-3">
              <div className="min-w-0">
                <div className="text-sm font-semibold text-slate-900">{`${l.first_name} ${l.last_name}`.trim()}</div>
                <div className="text-xs text-slate-500">
                  {l.email} · {l.organisation} · retirée le {dateFr(l.retire_le)}
                </div>
              </div>
              <Button
                variant="secondary"
                size="sm"
                className="h-11 md:h-8"
                aria-label={`Restaurer ${`${l.first_name} ${l.last_name}`.trim()}`}
                loading={restaurer.isPending && restaurer.variables === l.id}
                onClick={() => restaurer.mutate(l.id)}
              >
                Restaurer
              </Button>
            </li>
          ))}
        </ul>
      )}
    </Modal>
  );
}
