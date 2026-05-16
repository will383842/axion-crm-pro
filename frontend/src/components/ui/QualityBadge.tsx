type Quality = 'complete' | 'partielle' | 'basique';

const STYLES: Record<Quality, { bg: string; fg: string; label: string; icon: string }> = {
  complete:  { bg: 'bg-emerald-100', fg: 'text-emerald-800', label: 'Complète',  icon: '🟢' },
  partielle: { bg: 'bg-amber-100',   fg: 'text-amber-800',   label: 'Partielle', icon: '🟡' },
  basique:   { bg: 'bg-rose-100',    fg: 'text-rose-800',    label: 'Basique',   icon: '🔴' },
};

export function QualityBadge({ score, badge }: { score?: number; badge?: Quality }) {
  const q = badge ?? (score === undefined ? 'basique' : score >= 90 ? 'complete' : score >= 50 ? 'partielle' : 'basique');
  const s = STYLES[q];
  return (
    <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${s.bg} ${s.fg}`}>
      <span aria-hidden>{s.icon}</span>
      {s.label}{score !== undefined ? ` ${score}` : ''}
    </span>
  );
}
