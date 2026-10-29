/**
 * Carte « Étiquettes (propres au CRM, non synchronisées) » de la fiche
 * entreprise. Ajout et retrait passent par l'action de masse existante
 * (`/companies/tags/bulk`) à un seul élément. Retirer une étiquette retire le
 * LIEN avec cette entreprise, jamais l'étiquette elle-même.
 */
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';

import { Button, Card, CardEyebrow, CardHeader, CardTitle } from '@/components/ui';
import { api, messageApiLisible } from '@/lib/api';

export interface EtiquetteFiche {
  id: number;
  slug: string;
  name: string;
  is_locked?: boolean;
}

export function EtiquettesCard({
  companyId,
  etiquettes,
}: {
  companyId: number;
  etiquettes: EtiquetteFiche[];
}) {
  const queryClient = useQueryClient();
  const [choix, setChoix] = useState('');

  const { data: referentiel } = useQuery({
    queryKey: ['tags'],
    queryFn: async () => (await api.get<{ data: EtiquetteFiche[] }>('/tags')).data.data,
  });

  const modifier = useMutation({
    mutationFn: async ({ slug, action }: { slug: string; action: 'add' | 'remove' }) =>
      api.post('/companies/tags/bulk', { ids: [companyId], tag: slug, action }),
    onSuccess: () => {
      setChoix('');
      void queryClient.invalidateQueries({ queryKey: ['company', String(companyId)] });
      void queryClient.invalidateQueries({ queryKey: ['company', companyId] });
      void queryClient.invalidateQueries({ queryKey: ['companies'] });
    },
    onError: (e: unknown) => {
      toast.error(messageApiLisible(e) ?? 'L’opération a échoué.');
    },
  });

  const posees = new Set(etiquettes.map((t) => t.slug));
  const disponibles = (referentiel ?? []).filter((t) => !t.is_locked && !posees.has(t.slug));

  return (
    <Card padding="md">
      <CardHeader>
        <div>
          <CardEyebrow>Propres au CRM</CardEyebrow>
          <CardTitle>Étiquettes (propres au CRM, non synchronisées)</CardTitle>
        </div>
      </CardHeader>

      {etiquettes.length === 0 ? (
        <p className="text-sm text-slate-500 dark:text-slate-400">Aucune étiquette.</p>
      ) : (
        <ul className="flex flex-wrap gap-1.5">
          {etiquettes.map((t) => (
            <li
              key={t.id}
              className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-700 dark:bg-slate-800 dark:text-slate-200"
            >
              {t.name}
              {t.is_locked ? null : (
                <button
                  type="button"
                  aria-label={`Retirer l'étiquette ${t.name}`}
                  className="text-slate-400 hover:text-rose-600"
                  disabled={modifier.isPending}
                  onClick={() => modifier.mutate({ slug: t.slug, action: 'remove' })}
                >
                  ×
                </button>
              )}
            </li>
          ))}
        </ul>
      )}

      <div className="mt-3 flex gap-2">
        <select
          aria-label="Étiquette à ajouter"
          value={choix}
          onChange={(e) => setChoix(e.target.value)}
          className="h-9 min-w-0 flex-1 rounded-lg bg-white px-2 text-sm text-slate-900 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-white dark:ring-slate-700"
        >
          <option value="">Choisir une étiquette…</option>
          {disponibles.map((t) => (
            <option key={t.id} value={t.slug}>
              {t.name}
            </option>
          ))}
        </select>
        <Button
          variant="secondary"
          size="md"
          disabled={choix === ''}
          loading={modifier.isPending}
          onClick={() => modifier.mutate({ slug: choix, action: 'add' })}
        >
          Ajouter
        </Button>
      </div>
    </Card>
  );
}
