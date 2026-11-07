import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Button } from '@/components/ui';
import { messageApiLisible } from '@/lib/api';
import type { Filter } from '../filtresUrl';
import { CLE_VUES, chargerVues, enregistrerVue, filtreDeLaVue } from './vuesSauvegardees';

const CHAMP =
  'h-9 rounded-lg bg-white px-3 text-sm text-slate-900 ring-1 ring-slate-200 focus:outline-none focus:ring-2 focus:ring-slate-300 max-sm:h-11';

/** Choisir une vue enregistrée, ou enregistrer les filtres du moment. */
export function VuesSauvegardees({ filtre, onAppliquer }: { filtre: Filter; onAppliquer: (f: Filter) => void }) {
  const qc = useQueryClient();
  const [formulaire, setFormulaire] = useState(false);
  const [nom, setNom] = useState('');
  const vues = useQuery({ queryKey: CLE_VUES, queryFn: chargerVues });

  const enregistrer = useMutation({
    mutationFn: () => enregistrerVue(nom, filtre),
    onSuccess: (vue) => {
      toast.success(`Vue « ${vue.name} » enregistrée.`);
      setNom('');
      setFormulaire(false);
      void qc.invalidateQueries({ queryKey: CLE_VUES });
    },
    onError: (e: unknown) => toast.error(messageApiLisible(e) ?? 'La vue n’a pas pu être enregistrée.'),
  });

  return (
    <div className="flex flex-wrap items-center gap-2">
      {(vues.data?.length ?? 0) > 0 ? (
        <select
          aria-label="Vues enregistrées"
          className={CHAMP}
          value=""
          onChange={(e) => {
            const vue = vues.data?.find((v) => String(v.id) === e.target.value);
            if (vue) onAppliquer(filtreDeLaVue(vue));
          }}
        >
          <option value="">Vues enregistrées</option>
          {vues.data?.map((v) => (
            <option key={v.id} value={v.id}>
              {v.name}
            </option>
          ))}
        </select>
      ) : null}
      {formulaire ? (
        <form
          className="flex flex-wrap items-center gap-2"
          onSubmit={(e) => {
            e.preventDefault();
            if (nom.trim() !== '') enregistrer.mutate();
          }}
        >
          <input
            type="text"
            aria-label="Nom de la vue"
            placeholder="Nom de la vue"
            maxLength={120}
            value={nom}
            onChange={(e) => setNom(e.target.value)}
            className={CHAMP}
          />
          <Button type="submit" variant="secondary" size="md" disabled={nom.trim() === ''} loading={enregistrer.isPending}>
            Enregistrer
          </Button>
          <Button type="button" variant="ghost" size="md" onClick={() => setFormulaire(false)}>
            Annuler
          </Button>
        </form>
      ) : (
        <Button variant="ghost" size="md" onClick={() => setFormulaire(true)}>
          Enregistrer cette vue
        </Button>
      )}
    </div>
  );
}
