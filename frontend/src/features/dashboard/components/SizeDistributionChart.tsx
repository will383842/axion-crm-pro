import { Card } from '@/components/ui';
import { TAILLES } from '@/lib/referentiels.generated';

export type SizeDistribution = Record<string, number>;

/** Largeur minimale (en %) d'une barre NON nulle : une petite catégorie reste visible. */
const LARGEUR_MIN_PCT = 1;

/**
 * Part (en %) de chaque taille dans le total classé — échelle LINÉAIRE, comme
 * la maquette validée par Will (03/10/2026) : la barre dit la vraie part, et
 * le nombre exact est écrit au bout. Une valeur non nulle garde au moins 1 %
 * pour rester visible ; une valeur nulle, 0.
 */
export function largeursBarres(valeurs: number[]): number[] {
  const total = valeurs.reduce((s, v) => s + Math.max(0, v), 0);
  if (total <= 0) return valeurs.map(() => 0);
  return valeurs.map((v) => (v <= 0 ? 0 : Math.max(LARGEUR_MIN_PCT, Math.round((v / total) * 1000) / 10)));
}

/**
 * « Par taille » — barres horizontales TPE / PME / ETI / Grand groupe, avec le
 * nombre exact au bout de chaque barre. Tailles (clés ET libellés) issues du
 * référentiel unique généré depuis `Taxonomy::TAILLES`.
 *
 * `data === null` : le serveur n'a pas pu calculer la répartition — « Chiffre
 * indisponible pour le moment », jamais des barres à zéro.
 */
export function SizeDistributionChart({ data }: { data: SizeDistribution | null }) {
  const valeurs = TAILLES.map((t) => data?.[t.code] ?? 0);
  const largeurs = largeursBarres(valeurs);

  return (
    <Card padding="lg" className="flex min-w-0 flex-col gap-4">
      <h2 id="titre-par-taille" className="text-base font-bold text-slate-900">
        Par taille
      </h2>
      {data === null ? (
        <p className="text-sm text-slate-600" data-testid="tailles-indisponibles">
          Chiffre indisponible pour le moment.
        </p>
      ) : (
        <ul className="flex flex-col gap-3.5 text-sm" aria-labelledby="titre-par-taille">
          {TAILLES.map((t, i) => {
            const v = valeurs[i] ?? 0;
            return (
              <li key={t.code} className="flex items-center gap-3">
                <span className="w-[6.5rem] shrink-0 truncate text-slate-700">{t.libelle}</span>
                <span className="h-3 min-w-0 flex-1 overflow-hidden rounded-full bg-slate-100" aria-hidden>
                  <span
                    className="block h-full rounded-full bg-brand-600"
                    data-testid={`barre-${t.code}`}
                    style={{ width: `${largeurs[i] ?? 0}%` }}
                  />
                </span>
                <span className="shrink-0 text-right font-semibold tabular-nums text-slate-900">{v.toLocaleString('fr-FR')}</span>
              </li>
            );
          })}
        </ul>
      )}
    </Card>
  );
}
