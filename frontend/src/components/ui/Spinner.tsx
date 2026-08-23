import { cn } from './cn';

export function Spinner({ size = 'md', className }: { size?: 'sm' | 'md' | 'lg'; className?: string }) {
  const dim = size === 'sm' ? 'h-3.5 w-3.5' : size === 'lg' ? 'h-6 w-6' : 'h-4 w-4';
  return (
    // D28-014 — un `aria-label` sur un `<svg>` SANS rôle ne porte rien : `svg`
    // n'expose pas de rôle implicite, la plupart des lecteurs d'écran l'ignorent
    // et le libellé « Chargement » n'était jamais lu. `role="img"` en fait un
    // objet nommé, ce qui rend audible une étiquette déjà écrite.
    <svg viewBox="0 0 24 24" className={cn('animate-spin', dim, className)} fill="none" role="img" aria-label="Chargement">
      <circle cx="12" cy="12" r="10" stroke="currentColor" strokeOpacity="0.25" strokeWidth="3" />
      <path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
    </svg>
  );
}
