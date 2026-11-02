import { cn } from '@/components/ui';
import { formaterDate } from './libelles';
import type { MessageFil } from './requetes';

/** Le fil d'une réponse : message envoyé, réponse reçue, réponses envoyées depuis l'outil. */
export function FilMessages({ fil }: { fil: MessageFil[] }) {
  return (
    <ol aria-label="Fil de la conversation" className="space-y-3">
      {fil.map((m) => (
        <li
          key={m.id}
          className={cn(
            'rounded-xl border p-3 text-sm',
            m.sens === 'recu' ? 'border-slate-200 bg-white' : 'border-produit/20 bg-produit-pale',
          )}
        >
          <p className="flex flex-wrap items-center justify-between gap-x-3 text-xs text-slate-500">
            <span className="min-w-0 break-words">
              <span className="font-semibold text-slate-700">{m.sens === 'recu' ? 'Reçu' : 'Envoyé'}</span> · {m.de}
            </span>
            <span>{formaterDate(m.le)}</span>
          </p>
          <p className="mt-1 whitespace-pre-line break-words text-slate-900">{m.corps}</p>
        </li>
      ))}
    </ol>
  );
}
