import { useId, useState, type FormEvent } from 'react';
import { Send } from 'lucide-react';
import { Button } from '@/components/ui';
import { useRaccourcis, useRepondre } from './requetes';

/** « Répondre » : même fil, même boîte. Réponses rapides, attribution à un collègue. */
export function FormulaireReponse({ id, onEnvoyee }: { id: string; onEnvoyee?: () => void }) {
  const idTexte = useId();
  const idCollegue = useId();
  const [corps, setCorps] = useState('');
  const [raccourciId, setRaccourciId] = useState<string | undefined>(undefined);
  const [collegue, setCollegue] = useState('');
  const raccourcis = useRaccourcis();
  const repondre = useRepondre();

  function envoyer(e: FormEvent) {
    e.preventDefault();
    if (corps.trim() === '') return;
    repondre.mutate(
      { id, corps: corps.trim(), ...(raccourciId ? { raccourci_id: raccourciId } : {}), attribuer_a: collegue === '' ? null : collegue },
      {
        onSuccess: () => {
          setCorps('');
          setRaccourciId(undefined);
          onEnvoyee?.();
        },
      },
    );
  }

  return (
    <form onSubmit={envoyer} className="space-y-3" aria-label="Répondre dans le même fil">
      <p className="text-xs text-slate-500">Même fil, même boîte</p>
      <div role="group" aria-label="Réponses rapides" className="flex flex-wrap gap-2">
        {(raccourcis.data?.data ?? []).map((r) => (
          <button
            key={r.id}
            type="button"
            onClick={() => {
              setCorps(r.corps);
              setRaccourciId(r.id);
            }}
            className="inline-flex min-h-9 items-center rounded-full bg-white px-3 text-xs font-medium text-slate-700 ring-1 ring-slate-200 hover:bg-slate-50 max-md:min-h-11"
          >
            {r.nom}
          </button>
        ))}
      </div>
      <div className="flex flex-col gap-1">
        <label htmlFor={idTexte} className="text-xs font-medium text-slate-600">
          Votre réponse
        </label>
        <textarea
          id={idTexte}
          value={corps}
          onChange={(e) => setCorps(e.target.value)}
          rows={4}
          className="w-full rounded-lg bg-white p-3 text-sm text-slate-900 ring-1 ring-slate-200 focus:outline-none focus:ring-2 focus:ring-produit"
        />
      </div>
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div className="flex min-w-0 flex-col gap-1">
          <label htmlFor={idCollegue} className="text-xs font-medium text-slate-600">
            Attribuer à
          </label>
          <select
            id={idCollegue}
            value={collegue}
            onChange={(e) => setCollegue(e.target.value)}
            className="h-9 rounded-lg bg-white px-3 text-sm text-slate-900 ring-1 ring-slate-200 max-md:h-11"
          >
            <option value="">Moi</option>
            {(raccourcis.data?.collegues ?? []).map((c) => (
              <option key={c.id} value={c.nom}>
                {c.nom}
              </option>
            ))}
          </select>
        </div>
        <Button type="submit" variant="secondary" className="max-md:h-11" iconLeft={<Send className="h-4 w-4" aria-hidden />} loading={repondre.isPending} disabled={corps.trim() === ''}>
          Envoyer la réponse
        </Button>
      </div>
      {repondre.isError ? (
        <p role="alert" className="text-sm text-rose-700">
          La réponse n’a pas pu partir. Rien n’est perdu : réessayez dans un instant.
        </p>
      ) : null}
    </form>
  );
}
