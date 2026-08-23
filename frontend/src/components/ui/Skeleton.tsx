export function Skeleton({ className = '' }: { className?: string }) {
  return <div className={`animate-pulse rounded bg-slate-200 ${className}`} />;
}

export function CompaniesTableSkeleton({ rows = 10 }: { rows?: number }) {
  return (
    // D28-014 — `aria-busy` seul n'ANNONCE rien : il qualifie l'état d'une
    // région, il n'en fait pas une région d'annonce. Mesure du 2026-08-22 :
    // `grep -rn aria-live src --include=*.tsx` ne rendait AUCUNE ligne, alors
    // que ce squelette est l'attente la plus fréquente du produit — elle passait
    // donc entièrement sous silence. `role="status"` est poli par définition :
    // l'attente se dit, sans interrompre la lecture en cours.
    <div className="space-y-2" role="status" aria-live="polite" aria-busy="true" aria-label="Chargement de la liste">
      <Skeleton className="h-10 w-full" />
      {Array.from({ length: rows }).map((_, i) => (
        <Skeleton key={i} className="h-12 w-full" />
      ))}
    </div>
  );
}
