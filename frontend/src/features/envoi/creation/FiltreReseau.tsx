/**
 * Étape 1 — « Restreindre par réseau d'origine ». Le filtre ne peut que restreindre la cible.
 * Le bloc n'existe que si la lecture des origines est ouverte ; sinon, un filtre déjà posé
 * sur le brouillon est signalé, avec le geste pour le retirer (échec fermé côté serveur).
 */
import { Button } from '@/components/ui';
import { libelleReseau } from '@/features/origines/libelles';
import { RESEAUX_MAX, useGuidesOrigine, useMotsOrigine, useReseauxOrigine, type ContenuOrigine } from './api';
import { reseauxNormalises } from './saisie';

export const MENTION_ESTIMATION =
  'L’estimation affichée ne tient pas compte du filtre ; le compte exact (hors réseau) apparaît à la préparation de l’envoi.';

/** Liste de cases « mot » ou « guide » (10 au plus) ; absente si la lecture est fermée ou indisponible. */
function ListeContenu({ titre, genre, choisis, modifier }: { titre: string; genre: 'mot' | 'guide'; choisis: readonly string[]; modifier: (valeurs: string[]) => void }) {
  const mots = useMotsOrigine(genre === 'mot');
  const guides = useGuidesOrigine(genre === 'guide');
  const donnees: ContenuOrigine | undefined = (genre === 'mot' ? mots : guides).data;
  if (donnees?.etat !== 'actif') return null;
  // Une valeur déjà retenue reste décochable même si elle n'est plus proposée.
  const proposes = donnees.valeurs.map((v) => v.valeur);
  for (const c of choisis) if (!proposes.includes(c)) proposes.push(c);
  if (proposes.length === 0) return null;
  const pleine = reseauxNormalises(choisis).length >= RESEAUX_MAX;
  return (
    <fieldset className="mt-3">
      <legend className="mb-1 text-xs text-slate-600">{titre} ({RESEAUX_MAX} au plus)</legend>
      <div className="flex flex-wrap gap-x-4">
        {proposes.map((v) => {
          const coche = choisis.includes(v);
          return (
            <label key={v} className="flex min-h-11 items-center gap-2 text-sm text-slate-900">
              <input
                type="checkbox"
                className="h-4 w-4 accent-[var(--color-produit)]"
                checked={coche}
                disabled={!coche && pleine}
                onChange={(ev) => { modifier(reseauxNormalises(ev.target.checked ? [...choisis, v] : choisis.filter((x) => x !== v))); }}
              />
              {v}
            </label>
          );
        })}
      </div>
    </fieldset>
  );
}

export function FiltreReseau({
  reseaux, modifier, mots = [], guides = [], modifierContenu,
}: {
  reseaux: readonly string[];
  modifier: (reseaux: string[]) => void;
  mots?: readonly string[];
  guides?: readonly string[];
  /** Absent : les listes de mots et de guides ne sont pas proposées. */
  modifierContenu?: (patch: { mots?: string[]; guides?: string[] }) => void;
}) {
  const q = useReseauxOrigine();
  if (q.data === undefined) return null;
  const pose = reseaux.length + mots.length + guides.length > 0;

  if (q.data.etat === 'inactif') {
    if (!pose) return null;
    return (
      <div role="alert" className="mt-4 flex flex-wrap items-center gap-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200">
        <p className="min-w-0 flex-1">Filtre par réseau inactif : cette campagne ne partira pas tant qu’il est posé.</p>
        <Button variant="secondary" onClick={() => { modifier([]); modifierContenu?.({ mots: [], guides: [] }); }}>Retirer le filtre</Button>
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
      {modifierContenu ? (
        <>
          <ListeContenu titre="Par mot : seules les personnes venues par ces mots sont retenues" genre="mot" choisis={mots} modifier={(v) => { modifierContenu({ mots: v }); }} />
          <ListeContenu titre="Par guide : seules les personnes venues par ces guides sont retenues" genre="guide" choisis={guides} modifier={(v) => { modifierContenu({ guides: v }); }} />
        </>
      ) : null}
      {pose ? <p className="mt-1 text-xs text-slate-600">{MENTION_ESTIMATION}</p> : null}
    </details>
  );
}
