/** États communs des écrans neufs : chargement, vide, erreur, accès refusé (textes de la maquette validée). */
import type { ReactNode } from 'react';
import { Lock, Inbox, TriangleAlert } from 'lucide-react';
import { Button, EmptyState, Skeleton } from '@/components/ui';

export function Chargement({ texte }: { texte: string }) {
  return (
    <div role="status" aria-live="polite" className="space-y-3 py-6">
      <p className="text-sm text-slate-600 dark:text-slate-300">{texte}</p>
      <Skeleton className="h-16 w-full" />
      <Skeleton className="h-40 w-full" />
    </div>
  );
}

export function Vide({ titre, description }: { titre: string; description: string }) {
  return <EmptyState icon={<Inbox />} title={titre} description={description} />;
}

export function Erreur({ onRetry }: { onRetry: () => void }) {
  return (
    <div role="alert" className="flex flex-col items-center rounded-xl border-2 border-dashed border-rose-200 px-6 py-12 text-center dark:border-rose-900">
      <TriangleAlert className="mb-3 h-10 w-10 text-rose-500" aria-hidden />
      <h2 className="text-lg font-semibold text-slate-900 dark:text-white">Impossible de charger cet écran</h2>
      <p className="mt-1 max-w-xl text-sm text-slate-600 dark:text-slate-300">
        Le serveur n’a pas répondu. Vos données ne sont pas touchées. Réessayez dans un instant ; si le problème continue, prévenez le propriétaire.
      </p>
      <div className="mt-4">
        <Button variant="secondary" onClick={onRetry}>Réessayer</Button>
      </div>
    </div>
  );
}

export function AccesRefuse({ children }: { children: ReactNode }) {
  return (
    <div role="alert" className="flex flex-col items-center rounded-xl border-2 border-dashed border-amber-200 px-6 py-12 text-center dark:border-amber-900">
      <Lock className="mb-3 h-10 w-10 text-amber-500" aria-hidden />
      <h2 className="text-lg font-semibold text-slate-900 dark:text-white">Accès refusé</h2>
      <p className="mt-1 max-w-xl text-sm text-slate-600 dark:text-slate-300">{children}</p>
    </div>
  );
}
