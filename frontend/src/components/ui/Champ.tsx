import type { ReactNode } from 'react';

/**
 * Un champ de filtre avec son étiquette VISIBLE au-dessus (audit P2-4).
 *
 * Né dans la liste des entreprises, partagé depuis le lot 18 de l'audit UX
 * (2026-10-03) : un filtre déroulant qui ne porte qu'un `aria-label` ne dit
 * rien à l'œil — « Tous » quoi ? L'étiquette reste associée au contrôle par
 * `htmlFor`, l'accessibilité ne perd rien.
 */
export function Champ({
  label,
  htmlFor,
  children,
}: {
  label: string;
  /** `null` quand le champ porte déjà sa propre étiquette (la recherche). */
  htmlFor: string | null;
  children: ReactNode;
}) {
  return (
    <div className="flex flex-col gap-1">
      {htmlFor === null ? (
        <span aria-hidden className="text-xs font-medium text-slate-600">
          {label}
        </span>
      ) : (
        <label htmlFor={htmlFor} className="text-xs font-medium text-slate-600">
          {label}
        </label>
      )}
      {children}
    </div>
  );
}
