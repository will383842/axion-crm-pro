/**
 * Le réglage « à qui écrire dans chaque organisation » (2026-09-30) : mode,
 * filtre par fonction, personnes cochées seulement, adresses partagées.
 * Composant CONTRÔLÉ : il ne sauve rien lui-même.
 */
import { useState } from 'react';
import { X } from 'lucide-react';
import { cn } from '@/components/ui';
import { FONCTIONS_PROPOSEES, MODES, type ReglageDestinataires } from './destinataires';

export function ReglageDestinatairesChamps({
  valeur,
  onChange,
  listeExigee,
}: {
  valeur: ReglageDestinataires;
  onChange: (r: ReglageDestinataires) => void;
  /** Une liste manuelle est-elle exigée par les critères ? (sinon « personnes cochées » n'a pas de sens) */
  listeExigee: boolean;
}) {
  const [saisie, setSaisie] = useState('');
  const ajouterFonction = (f: string) => {
    const propre = f.trim();
    if (propre === '' || valeur.fonctions.includes(propre) || valeur.fonctions.length >= 20) return;
    onChange({ ...valeur, fonctions: [...valeur.fonctions, propre] });
  };

  return (
    <div className="space-y-4">
      <fieldset className="space-y-1.5">
        <legend className="mb-1 text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
          À qui écrire dans chaque organisation
        </legend>
        {MODES.map((m) => (
          <label key={m.code} className="flex cursor-pointer items-start gap-2 text-sm text-slate-700 dark:text-slate-300">
            <input
              type="radio"
              name="destinataires-mode"
              className="mt-1"
              checked={valeur.mode === m.code}
              onChange={() => onChange({ ...valeur, mode: m.code })}
            />
            <span>
              {m.libelle}
              <span className="block text-[11px] text-slate-500 dark:text-slate-400">{m.aide}</span>
            </span>
          </label>
        ))}
      </fieldset>

      <div>
        <div className="mb-1 text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
          Fonctions des personnes (facultatif)
        </div>
        <p className="mb-2 text-[11px] text-slate-500 dark:text-slate-400">
          Seules les personnes dont la fonction contient l’un de ces mots (accents et majuscules ignorés).
        </p>
        <div className="mb-2 flex flex-wrap gap-1.5">
          {FONCTIONS_PROPOSEES.map((f) => {
            const active = valeur.fonctions.includes(f);
            return (
              <button
                key={f}
                type="button"
                aria-pressed={active}
                onClick={() =>
                  active ? onChange({ ...valeur, fonctions: valeur.fonctions.filter((x) => x !== f) }) : ajouterFonction(f)
                }
                className={cn(
                  'inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-medium transition',
                  active
                    ? 'bg-sky-500 text-white ring-1 ring-sky-600'
                    : 'bg-slate-100 text-slate-700 ring-1 ring-slate-200 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700',
                )}
              >
                {f}
                {active ? <X className="h-3 w-3" /> : null}
              </button>
            );
          })}
          {valeur.fonctions
            .filter((f) => !FONCTIONS_PROPOSEES.includes(f))
            .map((f) => (
              <button
                key={f}
                type="button"
                aria-pressed
                onClick={() => onChange({ ...valeur, fonctions: valeur.fonctions.filter((x) => x !== f) })}
                className="inline-flex items-center gap-1 rounded-full bg-sky-500 px-2.5 py-1 text-[11px] font-medium text-white ring-1 ring-sky-600"
              >
                {f}
                <X className="h-3 w-3" />
              </button>
            ))}
        </div>
        <input
          type="text"
          aria-label="Autre fonction"
          placeholder="Autre fonction, puis Entrée"
          value={saisie}
          maxLength={60}
          onChange={(e) => setSaisie(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              e.preventDefault();
              ajouterFonction(saisie);
              setSaisie('');
            }
          }}
          className="h-8 w-64 rounded-lg bg-white px-2 text-xs text-slate-900 ring-1 ring-slate-200 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:bg-slate-900 dark:text-white dark:ring-slate-700"
        />
      </div>

      <label className={cn('flex items-center gap-2 text-sm', listeExigee ? 'text-slate-700 dark:text-slate-300' : 'text-slate-400')}>
        <input
          type="checkbox"
          disabled={!listeExigee}
          checked={valeur.personnes_listees}
          onChange={(e) => onChange({ ...valeur, personnes_listees: e.target.checked })}
        />
        Seulement les personnes cochées dans la liste manuelle
      </label>
      <label className="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
        <input
          type="checkbox"
          checked={valeur.avec_adresses_partagees}
          onChange={(e) => onChange({ ...valeur, avec_adresses_partagees: e.target.checked })}
        />
        Garder les adresses de cabinet ou de domiciliation partagées (écartées par défaut)
      </label>
    </div>
  );
}
