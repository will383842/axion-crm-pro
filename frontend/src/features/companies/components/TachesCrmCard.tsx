/**
 * Carte « Tâches du CRM » de la fiche entreprise : les tâches propres au CRM
 * (intitulé, échéance, faite ou non). Elles restent DANS le CRM — rien n'en
 * part vers la console. On ne supprime jamais une tâche : on la marque faite.
 */
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';

import { Button, Card, CardEyebrow, CardHeader, CardTitle, Input } from '@/components/ui';
import { api, messageApiLisible } from '@/lib/api';

export interface TacheCrm {
  id: number;
  title: string;
  content: string | null;
  due_at: string | null;
  done_at: string | null;
  done: boolean;
}

function avantLe(iso: string): string {
  return new Date(iso).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' });
}

export function TachesCrmCard({ companyId }: { companyId: number }) {
  const queryClient = useQueryClient();
  const cle = ['taches-crm-entreprise', companyId];
  const [formulaireOuvert, setFormulaireOuvert] = useState(false);
  const [intitule, setIntitule] = useState('');
  const [echeance, setEcheance] = useState('');

  const {
    data: taches,
    isLoading,
    isError,
    refetch,
  } = useQuery({
    queryKey: cle,
    queryFn: async () =>
      (await api.get<{ data: TacheCrm[] }>(`/companies/${companyId}/taches`)).data.data,
  });

  const ajouter = useMutation({
    mutationFn: async () =>
      api.post(`/companies/${companyId}/taches`, {
        title: intitule.trim(),
        due_at: echeance || null,
      }),
    onSuccess: () => {
      toast.success('Tâche ajoutée.');
      setIntitule('');
      setEcheance('');
      setFormulaireOuvert(false);
      void queryClient.invalidateQueries({ queryKey: cle });
    },
    onError: (e: unknown) => toast.error(messageApiLisible(e) ?? 'L’opération a échoué.'),
  });

  const terminer = useMutation({
    mutationFn: async (id: number) => api.post(`/companies/${companyId}/taches/${id}/terminer`),
    onSuccess: () => {
      toast.success('Tâche marquée comme faite.');
      void queryClient.invalidateQueries({ queryKey: cle });
    },
    onError: (e: unknown) => toast.error(messageApiLisible(e) ?? 'L’opération a échoué.'),
  });

  return (
    <Card padding="md">
      <CardHeader>
        <div>
          <CardEyebrow>Propres au CRM</CardEyebrow>
          <CardTitle>Tâches du CRM</CardTitle>
        </div>
      </CardHeader>

      {isLoading ? (
        <p className="text-sm text-slate-500 dark:text-slate-400">Chargement…</p>
      ) : isError ? (
        <div className="space-y-2">
          <p role="alert" className="text-sm text-rose-600 dark:text-rose-400">
            Impossible de charger les tâches.
          </p>
          <Button variant="secondary" size="sm" onClick={() => void refetch()}>
            Réessayer
          </Button>
        </div>
      ) : !taches || taches.length === 0 ? (
        <p className="text-sm text-slate-500 dark:text-slate-400">Aucune tâche pour cette entreprise.</p>
      ) : (
        <ul className="divide-y divide-slate-100 dark:divide-slate-800">
          {taches.map((t) => (
            <li key={t.id} className="flex items-start justify-between gap-2 py-2 text-sm">
              <div className="min-w-0">
                <div
                  className={
                    t.done
                      ? 'font-medium text-slate-400 line-through dark:text-slate-500'
                      : 'font-medium text-slate-900 dark:text-white'
                  }
                >
                  {t.title}
                </div>
                <div className="text-xs text-slate-500 dark:text-slate-400">
                  {t.done
                    ? 'Faite'
                    : t.due_at
                      ? `À faire avant le ${avantLe(t.due_at)}`
                      : 'Sans échéance'}
                </div>
              </div>
              {t.done ? null : (
                <Button
                  variant="ghost"
                  size="sm"
                  loading={terminer.isPending && terminer.variables === t.id}
                  onClick={() => terminer.mutate(t.id)}
                  aria-label={`Marquer comme faite : ${t.title}`}
                >
                  Marquer comme faite
                </Button>
              )}
            </li>
          ))}
        </ul>
      )}

      {formulaireOuvert ? (
        <form
          className="mt-3 space-y-2"
          onSubmit={(e) => {
            e.preventDefault();
            if (intitule.trim().length >= 3) ajouter.mutate();
          }}
        >
          <Input
            aria-label="Intitulé de la tâche"
            placeholder="Intitulé de la tâche"
            value={intitule}
            maxLength={300}
            onChange={(e) => setIntitule(e.target.value)}
          />
          <Input
            type="date"
            aria-label="Avant le"
            value={echeance}
            onChange={(e) => setEcheance(e.target.value)}
          />
          <div className="flex gap-2">
            <Button
              type="submit"
              variant="secondary"
              size="sm"
              loading={ajouter.isPending}
              disabled={intitule.trim().length < 3}
            >
              Enregistrer
            </Button>
            <Button type="button" variant="ghost" size="sm" onClick={() => setFormulaireOuvert(false)}>
              Annuler
            </Button>
          </div>
        </form>
      ) : (
        <div className="mt-3">
          <Button variant="primary" size="md" full onClick={() => setFormulaireOuvert(true)}>
            Ajouter une tâche CRM
          </Button>
        </div>
      )}
    </Card>
  );
}
