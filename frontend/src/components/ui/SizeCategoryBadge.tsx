import { TAILLES, type CleTaille } from '@/lib/referentiels.generated';

/**
 * Pastille de taille. Les CLÉS et les LIBELLÉS viennent du référentiel unique
 * (`referentiels.generated.ts`, produit depuis `Taxonomy::TAILLES`) ; seules
 * les couleurs sont propres à l'écran. `Record<CleTaille, …>` : une taille
 * ajoutée au référentiel sans couleur ici fait échouer `pnpm typecheck`.
 */
const COULEURS: Record<CleTaille, { bg: string; fg: string }> = {
  tpe: { bg: 'bg-sky-100', fg: 'text-sky-800' },
  pme: { bg: 'bg-indigo-100', fg: 'text-indigo-800' },
  eti: { bg: 'bg-violet-100', fg: 'text-violet-800' },
  grand_groupe: { bg: 'bg-fuchsia-100', fg: 'text-fuchsia-800' },
};

const INCONNUE = { bg: 'bg-slate-100', fg: 'text-slate-600', label: 'Inconnue' };

export function SizeCategoryBadge({ size }: { size?: string | null | undefined }) {
  const taille = TAILLES.find((t) => t.code === size);
  const s = taille ? { ...COULEURS[taille.code], label: taille.libelle } : INCONNUE;
  return (
    <span className={`inline-flex rounded-md px-2 py-0.5 text-xs font-medium ${s.bg} ${s.fg}`}>
      {s.label}
    </span>
  );
}
