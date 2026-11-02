/** Blocs réutilisables (signatures, appels) et champs de fusion, à insérer dans le texte. */
import { Bloc } from '../../creation/composants';
import { Button } from '@/components/ui';
import type { BlocReutilisable } from '../../creation/api';

const CHAMPS = ['prénom', 'entreprise', 'ville', 'lien_rdv', 'signature'] as const;

export function BlocsReutilisables({ blocs, onInserer }: { blocs: readonly BlocReutilisable[]; onInserer: (texte: string) => void }) {
  return (
    <Bloc titre="Blocs réutilisables" etiquette="Blocs réutilisables">
      <p className="mb-2 text-xs text-slate-600">Champs de fusion : un champ vide prend sa valeur de repli, par exemple {'{prénom | Bonjour}'}.</p>
      <div className="mb-4 flex flex-wrap gap-2">
        {CHAMPS.map((c) => (
          <Button key={c} variant="secondary" size="sm" aria-label={`Insérer le champ ${c}`} onClick={() => { onInserer(`{${c}}`); }}>
            {`{${c}}`}
          </Button>
        ))}
      </div>
      <ul className="divide-y divide-slate-100">
        {blocs.map((b) => (
          <li key={b.id} className="flex items-center justify-between gap-3 py-2">
            <span className="min-w-0 text-sm text-slate-900">
              <strong className="block break-words font-semibold">{b.nom}</strong>
              <span className="text-xs text-slate-600">Utilisé {b.utilise} fois</span>
            </span>
            <Button variant="ghost" size="sm" aria-label={`Insérer le bloc ${b.nom}`} onClick={() => { onInserer(b.texte); }}>Insérer</Button>
          </li>
        ))}
      </ul>
    </Bloc>
  );
}
