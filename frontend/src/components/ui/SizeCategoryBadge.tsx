type Size = 'artisan' | 'tpe' | 'pme' | 'eti' | 'grande_entreprise' | 'inconnue';

const STYLES: Record<Size, { bg: string; fg: string; label: string }> = {
  artisan:           { bg: 'bg-orange-100', fg: 'text-orange-800', label: 'Artisan' },
  tpe:               { bg: 'bg-sky-100',    fg: 'text-sky-800',    label: 'TPE' },
  pme:               { bg: 'bg-indigo-100', fg: 'text-indigo-800', label: 'PME' },
  eti:               { bg: 'bg-violet-100', fg: 'text-violet-800', label: 'ETI' },
  grande_entreprise: { bg: 'bg-fuchsia-100',fg: 'text-fuchsia-800',label: 'Grande' },
  inconnue:          { bg: 'bg-slate-100',  fg: 'text-slate-600',  label: 'Inconnue' },
};

export function SizeCategoryBadge({ size }: { size?: string | null | undefined }) {
  const key = (size as Size | null | undefined) ?? 'inconnue';
  const s = STYLES[key] ?? STYLES.inconnue;
  return (
    <span className={`inline-flex rounded-md px-2 py-0.5 text-xs font-medium ${s.bg} ${s.fg}`}>
      {s.label}
    </span>
  );
}
