import { cn } from './cn';

export function Avatar({
  name,
  src,
  size = 'md',
  className,
}: {
  name?: string;
  src?: string | null;
  size?: 'xs' | 'sm' | 'md' | 'lg';
  className?: string;
}) {
  const dim = size === 'xs' ? 'h-6 w-6 text-[10px]' : size === 'sm' ? 'h-8 w-8 text-xs' : size === 'lg' ? 'h-12 w-12 text-base' : 'h-9 w-9 text-sm';
  const initials = (name ?? '?')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((s) => s[0]?.toUpperCase() ?? '')
    .join('') || '?';

  // Couleur déterministe depuis le nom (hue 0-360)
  const hue = name ? [...name].reduce((s, c) => s + c.charCodeAt(0), 0) % 360 : 220;
  const bg = `oklch(0.85 0.08 ${hue})`;
  const fg = `oklch(0.30 0.10 ${hue})`;

  if (src) {
    return (
      <img
        src={src}
        alt={name ?? 'avatar'}
        className={cn('rounded-full object-cover ring-1 ring-slate-200 dark:ring-slate-700', dim, className)}
      />
    );
  }
  return (
    <span
      className={cn('inline-flex items-center justify-center rounded-full font-semibold ring-1 ring-slate-200 dark:ring-slate-700', dim, className)}
      style={{ backgroundColor: bg, color: fg }}
      aria-label={name ?? 'avatar'}
    >
      {initials}
    </span>
  );
}
