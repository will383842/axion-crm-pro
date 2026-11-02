/**
 * Petites pièces communes à l'assistant et à l'éditeur de message : bloc
 * d'étape, interrupteur, puces, jauge, liste de contrôles.
 */
import type { ReactNode } from 'react';
import { AlertTriangle, CheckCircle2, XCircle } from 'lucide-react';
import { cn } from '@/components/ui';

export function Bloc({
  titre,
  mot,
  children,
  etiquette,
  className,
}: {
  titre: string;
  mot?: string;
  children: ReactNode;
  etiquette?: string;
  className?: string;
}) {
  return (
    <section aria-label={etiquette ?? titre} className={cn('rounded-2xl bg-white p-4 ring-1 ring-slate-200/70 shadow-[var(--shadow-card)] md:p-5', className)}>
      <div className="mb-4 flex items-center justify-between gap-3">
        <h2 className="text-lg font-bold tracking-tight text-slate-900">{titre}</h2>
        {mot ? <span className="text-xs font-medium text-slate-500">{mot}</span> : null}
      </div>
      {children}
    </section>
  );
}

/** Interrupteur : une vraie case à cocher présentée comme un interrupteur. */
export function Interrupteur({
  id,
  libelle,
  aide,
  coche,
  onChange,
}: {
  id: string;
  libelle: string;
  aide?: string;
  coche: boolean;
  onChange: (coche: boolean) => void;
}) {
  return (
    <div className="flex items-start justify-between gap-4 rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200">
      <div className="min-w-0">
        <label htmlFor={id} className="block text-sm font-semibold text-slate-900">
          {libelle}
        </label>
        {aide ? (
          <span id={`${id}-aide`} className="mt-0.5 block text-xs text-slate-600">
            {aide}
          </span>
        ) : null}
      </div>
      <input
        id={id}
        type="checkbox"
        role="switch"
        checked={coche}
        aria-describedby={aide ? `${id}-aide` : undefined}
        onChange={(e) => { onChange(e.target.checked); }}
        className={cn(
          'relative mt-0.5 h-6 w-11 shrink-0 cursor-pointer appearance-none rounded-full bg-slate-300 transition-colors',
          "before:absolute before:left-0.5 before:top-0.5 before:h-5 before:w-5 before:rounded-full before:bg-white before:shadow before:transition-transform before:content-['']",
          'checked:bg-produit checked:before:translate-x-5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-produit focus-visible:ring-offset-2',
        )}
      />
    </div>
  );
}

export function Puces<T extends string>({
  libelle,
  options,
  valeur,
  onChange,
}: {
  libelle: string;
  options: ReadonlyArray<{ id: T; libelle: string }>;
  valeur: T;
  onChange: (v: T) => void;
}) {
  return (
    <div role="group" aria-label={libelle} className="flex flex-wrap gap-2">
      {options.map((o) => (
        <button
          key={o.id}
          type="button"
          aria-pressed={o.id === valeur}
          onClick={() => { onChange(o.id); }}
          className={cn(
            'inline-flex h-11 items-center rounded-full px-4 text-sm font-medium ring-1 transition md:h-9',
            o.id === valeur ? 'bg-produit-pale text-produit ring-produit' : 'bg-white text-slate-700 ring-slate-200 hover:bg-slate-50',
          )}
        >
          {o.libelle}
        </button>
      ))}
    </div>
  );
}

export function Jauge({ valeur, max, libelle }: { valeur: number; max: number; libelle: string }) {
  const part = max <= 0 ? 0 : Math.round((valeur / max) * 100);
  return (
    <div role="meter" aria-valuemin={0} aria-valuemax={max} aria-valuenow={valeur} aria-label={libelle} className="mt-2">
      <div className="flex justify-between text-xs text-slate-600">
        <span>Joignables</span>
        <span>{valeur.toLocaleString('fr-FR')} sur {max.toLocaleString('fr-FR')}</span>
      </div>
      <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100">
        <span className="block h-full rounded-full bg-emerald-500" style={{ width: `${part}%` }} />
      </div>
    </div>
  );
}

export type EtatControle = 'ok' | 'attention' | 'bloquant';

const ICONES: Record<EtatControle, ReactNode> = {
  ok: <CheckCircle2 className="h-4 w-4 text-emerald-600" aria-hidden />,
  attention: <AlertTriangle className="h-4 w-4 text-amber-600" aria-hidden />,
  bloquant: <XCircle className="h-4 w-4 text-rose-600" aria-hidden />,
};
const MOTS: Record<EtatControle, string> = { ok: 'En règle', attention: 'À surveiller', bloquant: 'Bloquant' };
const TONS: Record<EtatControle, string> = {
  ok: 'bg-emerald-50 text-emerald-700',
  attention: 'bg-amber-50 text-amber-700',
  bloquant: 'bg-rose-50 text-rose-700',
};

/** Une ligne par contrôle : l'état se lit par le texte et l'icône, jamais par la couleur seule. */
export function ListeControles({
  controles,
  etiquette,
}: {
  controles: ReadonlyArray<{ code: string; libelle: string; etat: EtatControle; detail: string | null }>;
  etiquette: string;
}) {
  return (
    <ul aria-label={etiquette} className="divide-y divide-slate-100">
      {controles.map((c) => (
        <li key={c.code} className="flex items-start gap-3 py-2.5">
          <span className="mt-0.5 shrink-0">{ICONES[c.etat]}</span>
          <span className="min-w-0 flex-1 text-sm text-slate-900">
            {c.libelle}
            {c.detail ? <span className="mt-0.5 block text-xs text-slate-600">{c.detail}</span> : null}
          </span>
          <span className={cn('shrink-0 rounded-full px-2 py-0.5 text-xs font-medium', TONS[c.etat])}>{MOTS[c.etat]}</span>
        </li>
      ))}
    </ul>
  );
}

export const CHAMP =
  'h-11 w-full rounded-lg bg-white px-3 text-sm text-slate-900 ring-1 ring-slate-200 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-produit md:h-9';
