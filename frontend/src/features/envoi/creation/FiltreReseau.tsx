/**
 * Étape 1 — « Restreindre par réseau d'origine ». Le filtre ne peut que restreindre la cible.
 * Le bloc n'existe que si la lecture des origines est ouverte ; sinon, un filtre déjà posé
 * sur le brouillon est signalé, avec le geste pour le retirer (échec fermé côté serveur).
 */
import { Button } from '@/components/ui';
import { libelleReseau } from '@/features/origines/libelles';
import { RESEAUX_MAX, useReseauxOrigine } from './api';
import { reseauxNormalises } from './saisie';

export const MENTION_ESTIMATION =
  'L’estimation affichée ne tient pas compte du filtre ; le compte exact (hors réseau) apparaît à la préparation de l’envoi.';

export function FiltreReseau({ reseaux, modifier }: { reseaux: readonly string[]; modifier: (reseaux: string[]) => void }) {
  const q = useReseauxOrigine();
  if (q.data === undefined) return null;
  const pose = reseaux.length > 0;

  if (q.data.etat === 'inactif') {
    if (!pose) return null;
    return (
      <div role="alert" className="mt-4 flex flex-wrap items-center gap-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200">
        <p className="min-w-0 flex-1">Filtre par réseau inactif : cette campagne ne partira pas tant qu’il est posé.</p>
        <Button variant="secondary" onClick={() => { modifier([]); }}>Retirer le filtre</Button>
      </div>
    );
  }
  if (q.data.etat !== 'actif') return null;

  // Un réseau déjà retenu reste décochable même s'il n'est plus proposé.
  const proposes = [...q.data.reseaux.map((r) => r.reseau)];
  for (const r of reseaux) if (!proposes.includes(r)) proposes.push(r);
  const pleine = reseauxNormalises(reseaux).length >= RESEAUX_MAX;

  const basculer = (r: string, coche: boolean) => {
    modifier(reseauxNormalises(coche ? [...reseaux, r] : reseaux.filter((x) => x !== r)));
  };

  return (
    <details className="mt-4 rounded-xl p-3 ring-1 ring-slate-200" open={pose}>
      <summary className="min-h-11 cursor-pointer text-sm font-medium text-slate-900">Restreindre par réseau d’origine</summary>
      <fieldset className="mt-2">
        <legend className="mb-1 text-xs text-slate-600">
          Seules les personnes venues de ces réseaux sont retenues ({RESEAUX_MAX} au plus). Aucune case cochée : aucune restriction.
        </legend>
        {proposes.length === 0 ? <p className="text-sm text-slate-600">Aucun réseau connu pour l’instant.</p> : null}
        <div className="flex flex-wrap gap-x-4">
          {proposes.map((r) => {
            const coche = reseaux.includes(r);
            return (
              <label key={r} className="flex min-h-11 items-center gap-2 text-sm text-slate-900">
                <input
                  type="checkbox"
                  className="h-4 w-4 accent-[var(--color-produit)]"
                  checked={coche}
                  disabled={!coche && pleine}
                  onChange={(ev) => { basculer(r, ev.target.checked); }}
                />
                {libelleReseau(r) ?? r}
              </label>
            );
          })}
        </div>
      </fieldset>
      {pose ? <p className="mt-1 text-xs text-slate-600">{MENTION_ESTIMATION}</p> : null}
    </details>
  );
}
