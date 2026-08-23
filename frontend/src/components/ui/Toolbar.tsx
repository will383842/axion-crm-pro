import { useId, type ReactNode } from 'react';
import { cn } from './cn';

export function Toolbar({ left, right, className }: { left?: ReactNode; right?: ReactNode; className?: string }) {
  return (
    <div className={cn('mb-4 flex flex-wrap items-center gap-3 rounded-xl bg-white/70 p-2 ring-1 ring-slate-200/60 backdrop-blur-sm dark:bg-slate-900/60 dark:ring-slate-800', className)}>
      <div className="flex flex-wrap items-center gap-2">{left}</div>
      {right ? <div className="ml-auto flex flex-wrap items-center gap-2">{right}</div> : null}
    </div>
  );
}

/**
 * D28-007 — `label` est OBLIGATOIRE, et il ne se remplace pas par le placeholder.
 *
 * Mesure du 2026-08-22 : ce champ n'avait ni `id`, ni `<label>`, ni
 * `aria-label`, et AUCUNE propriete ne permettait a un appelant d'en poser un —
 * huit ecrans le rendaient ainsi. Un `placeholder` n'est pas un nom accessible :
 * il disparait a la premiere frappe, il n'est pas restitue par toutes les aides
 * techniques, et il ne donne aucune cible a la commande vocale. Un lecteur
 * d'ecran annoncait donc « zone d'edition », sans dire ce qu'on y cherche.
 *
 * `label` est requis PLUTOT qu'optionnel pour que l'oubli devienne impossible :
 * `tsc` refuse un `<SearchInput>` sans nom. Un defaut d'accessibilite qui se
 * repare a la revue revient au premier ecran suivant ; celui-ci ne peut plus
 * revenir en silence.
 *
 * Le libelle est VISUELLEMENT MASQUE (`sr-only`) et non `aria-label` : un vrai
 * `<label for>` rend aussi le champ cliquable par son nom et reste lisible par
 * les outils qui ignorent `aria-label`. Aucun pixel ne change a l'ecran.
 */
export function SearchInput({
  value,
  onChange,
  label,
  placeholder = 'Rechercher…',
  className,
}: {
  value: string;
  onChange: (v: string) => void;
  /** Ce que ce champ cherche, en clair : « Rechercher une entreprise ». */
  label: string;
  placeholder?: string;
  className?: string;
}) {
  // `useId()` et non un compteur de module : deux champs de recherche peuvent
  // coexister sur un ecran, et un identifiant stable au rendu serveur comme au
  // rendu client evite un `for` qui pointe dans le vide apres hydratation.
  const id = useId();

  return (
    <div className={cn('relative inline-flex w-full max-w-xs', className)}>
      <label htmlFor={id} className="sr-only">
        {label}
      </label>
      <svg viewBox="0 0 20 20" className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" fill="none">
        <circle cx="9" cy="9" r="6" stroke="currentColor" strokeWidth="2" />
        <path d="M14 14l3 3" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
      </svg>
      <input
        id={id}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        className="h-9 w-full rounded-lg bg-white pl-8 pr-3 text-sm text-slate-900 ring-1 ring-slate-200 transition placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:bg-slate-900 dark:text-white dark:ring-slate-700 dark:focus:ring-slate-600"
      />
    </div>
  );
}
