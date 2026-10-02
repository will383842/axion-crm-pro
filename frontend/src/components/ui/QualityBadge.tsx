type Quality = 'complete' | 'partielle' | 'basique';

const STYLES: Record<Quality, { bg: string; fg: string; label: string; dot: string }> = {
  complete:  { bg: 'bg-emerald-100', fg: 'text-emerald-800', label: 'Complète',  dot: 'bg-emerald-500' },
  partielle: { bg: 'bg-amber-100',   fg: 'text-amber-800',   label: 'Partielle', dot: 'bg-amber-500' },
  basique:   { bg: 'bg-rose-100',    fg: 'text-rose-800',    label: 'Basique',   dot: 'bg-rose-500' },
};

export function QualityBadge({ score, badge }: { score?: number | null | undefined; badge?: Quality }) {
  const q = badge ?? (score == null ? 'basique' : score >= 90 ? 'complete' : score >= 50 ? 'partielle' : 'basique');
  const s = STYLES[q];
  return (
    <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${s.bg} ${s.fg}`}>
      <span aria-hidden className={`h-2 w-2 rounded-full ${s.dot}`} />
      {s.label}{score != null ? ` ${score}` : ''}
    </span>
  );
}
