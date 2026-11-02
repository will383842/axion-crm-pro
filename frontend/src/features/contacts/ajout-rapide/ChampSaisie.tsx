/**
 * Champ de formulaire des contacts : étiquette visible, 44 px au téléphone,
 * 36 px sur ordinateur (échelle du CRM). Mode clair seulement.
 */
import { useId, type ReactNode } from 'react';

const CLS_CONTROLE =
  'h-11 w-full rounded-lg bg-white px-3 text-sm text-slate-900 ring-1 ring-slate-300 placeholder:text-slate-400 ' +
  'focus:outline-none focus:ring-2 focus:ring-produit md:h-9';

export function ChampSaisie({
  label,
  obligatoire = false,
  aide,
  children,
}: {
  label: string;
  obligatoire?: boolean;
  aide?: string;
  children: (id: string, classe: string) => ReactNode;
}) {
  const id = useId();
  return (
    <div className="flex flex-col gap-1">
      <label htmlFor={id} className="text-sm font-medium text-slate-700">
        {label}
        {obligatoire ? <span aria-hidden className="ml-0.5 text-rose-600">*</span> : null}
      </label>
      {children(id, CLS_CONTROLE)}
      {aide ? <p className="text-xs text-slate-500">{aide}</p> : null}
    </div>
  );
}
