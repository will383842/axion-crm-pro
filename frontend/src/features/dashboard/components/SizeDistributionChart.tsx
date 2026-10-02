import { Card, CardHeader, CardTitle, CardEyebrow, cn } from '@/components/ui';
import { TAILLES, type CleTaille } from '@/lib/referentiels.generated';

export type SizeDistribution = Record<string, number>;

/**
 * Les tailles (clés ET libellés) viennent du référentiel unique généré depuis
 * `Taxonomy::TAILLES` ; seules les couleurs sont propres à l'écran. Une
 * taille ajoutée sans couleur fait échouer `pnpm typecheck`.
 */
const COULEURS: Record<CleTaille, { bar: string; ring: string }> = {
  tpe: { bar: 'from-violet-400 to-violet-600', ring: 'ring-violet-200/60' },
  pme: { bar: 'from-emerald-400 to-emerald-600', ring: 'ring-emerald-200/60' },
  eti: { bar: 'from-amber-400 to-amber-600', ring: 'ring-amber-200/60' },
  grand_groupe: { bar: 'from-rose-400 to-rose-600', ring: 'ring-rose-200/60' },
};

const BUCKETS: Array<{ key: string; label: string; bar: string; ring: string }> = TAILLES.map((t) => ({
  key: t.code,
  label: t.libelle,
  ...COULEURS[t.code],
}));

/** Hauteur minimale (en % de la zone) d'une barre NON nulle : toujours visible. */
const HAUTEUR_MIN_PCT = 4;

/**
 * Hauteur de chaque barre, en % de la zone du graphique.
 *
 * Échelle RACINE CARRÉE : les TPE (≈ 4 M) écrasaient en linéaire les PME
 * (≈ 185 k), ETI (≈ 75 k) et grands groupes (≈ 28 k) sous 1 % — des barres
 * invisibles. En racine carrée, elles tombent vers 21 %, 14 % et 8 % : l'ordre
 * est respecté, chaque catégorie se voit, et le nombre exact reste affiché
 * au-dessus de sa barre. La plus grande fait 100 %, une valeur nulle 0.
 */
function hauteursBarres(valeurs: number[]): number[] {
  const max = Math.max(0, ...valeurs);
  if (max <= 0) return valeurs.map(() => 0);
  const racineMax = Math.sqrt(max);
  return valeurs.map((v) =>
    v <= 0 ? 0 : Math.max(HAUTEUR_MIN_PCT, Math.round((Math.sqrt(v) / racineMax) * 1000) / 10),
  );
}

export function SizeDistributionChart({ data }: { data: SizeDistribution }) {
  const valeurs = BUCKETS.map((b) => data[b.key] ?? 0);
  const hauteurs = hauteursBarres(valeurs);
  const total = valeurs.reduce((s, v) => s + v, 0);

  return (
    <Card>
      <CardHeader>
        <div className="min-w-0">
          <CardEyebrow>Taille d'entreprise (INSEE)</CardEyebrow>
          <CardTitle>Distribution par catégorie</CardTitle>
        </div>
        <div className="shrink-0 text-right">
          <div className="text-xs font-semibold uppercase tracking-wider text-slate-500">Total classé</div>
          <div className="text-2xl font-semibold tabular-nums text-slate-900">{total.toLocaleString('fr-FR')}</div>
        </div>
      </CardHeader>

      {/*
        Barres verticales en CSS pur. Chaque colonne prend TOUTE la hauteur
        (`h-full`) et sa zone de barre (`flex-1`) a donc une hauteur définie :
        sans elle, le `height: X%` de la barre se résolvait sur une hauteur
        indéfinie, soit 0 — les barres étaient invisibles.
      */}
      <div className="flex h-44 gap-3 px-1 pt-2">
        {BUCKETS.map((b, i) => {
          const v = valeurs[i] ?? 0;
          const hauteur = hauteurs[i] ?? 0;
          const part = (total === 0 ? 0 : (v / total) * 100).toLocaleString('fr-FR', { maximumFractionDigits: 1 });
          return (
            <div key={b.key} className="group flex h-full flex-1 flex-col items-center gap-1.5">
              <div className="w-full text-center text-xs font-semibold tabular-nums text-slate-600" aria-hidden>
                {v.toLocaleString('fr-FR')}
              </div>
              <div className="flex w-full flex-1 items-end">
                <div
                  className={cn(
                    'relative w-full overflow-hidden rounded-t-md bg-gradient-to-t ring-1',
                    b.bar,
                    b.ring,
                    'transition-[height,transform] hover:-translate-y-0.5',
                  )}
                  style={{ height: `${hauteur}%`, minHeight: total === 0 ? 4 : undefined }}
                  role="img"
                  aria-label={`${b.label} : ${v.toLocaleString('fr-FR')} entreprise${v > 1 ? 's' : ''} (${part} %)`}
                  title={`${b.label} · ${v.toLocaleString('fr-FR')} (${part} %)`}
                />
              </div>
            </div>
          );
        })}
      </div>

      {/* Libellés sous les barres */}
      <div className="mt-2 flex gap-3 px-1">
        {BUCKETS.map((b) => (
          <div key={b.key} className="flex-1 text-center text-xs font-medium text-slate-600">
            {b.label}
          </div>
        ))}
      </div>
      <p className="mt-2 px-1 text-xs text-slate-500">
        Hauteurs adoucies pour que chaque catégorie reste visible ; le nombre exact est indiqué au-dessus de chaque barre.
      </p>
    </Card>
  );
}
