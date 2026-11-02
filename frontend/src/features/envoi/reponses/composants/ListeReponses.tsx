import { cn } from '@/components/ui';
import { formaterDate, initiales, nomComplet } from './libelles';
import { Initiales, PastilleClassement } from './PastilleClassement';
import type { ReponseResume } from './requetes';

export interface ListeReponsesProps {
  reponses: ReponseResume[];
  selection: string | null;
  onChoisir: (id: string) => void;
}

/** La file des réponses à traiter. La ligne choisie porte `aria-current`. */
export function ListeReponses({ reponses, selection, onChoisir }: ListeReponsesProps) {
  return (
    <ul aria-label="Réponses à traiter" className="space-y-2">
      {reponses.map((r) => {
        const choisie = String(r.id) === selection;
        return (
          <li key={String(r.id)}>
            <button
              type="button"
              aria-current={choisie ? 'true' : undefined}
              onClick={() => onChoisir(String(r.id))}
              className={cn(
                'flex w-full min-h-11 items-center gap-3 rounded-xl border bg-white p-3 text-left transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-produit',
                choisie ? 'border-produit ring-1 ring-produit' : 'border-slate-200',
              )}
            >
              <Initiales texte={initiales(r)} />
              <span className="min-w-0 flex-1">
                <span className="flex flex-wrap items-center justify-between gap-x-2">
                  <span className="truncate text-sm font-semibold text-slate-900">{nomComplet(r)}</span>
                  <PastilleClassement classement={r.classement} />
                </span>
                <span className="block truncate text-xs text-slate-500">« {r.extrait} »</span>
                <span className="block text-xs text-slate-500">{formaterDate(r.recue_le)}</span>
              </span>
            </button>
          </li>
        );
      })}
    </ul>
  );
}
