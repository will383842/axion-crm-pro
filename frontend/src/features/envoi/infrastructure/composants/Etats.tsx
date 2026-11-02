/**
 * DM2-INF — les quatre états d'une page (normal, vide, erreur, chargement) :
 * une seule enveloppe pour les sept pages, pour qu'ils soient identiques.
 */
import type { ReactNode } from 'react';
import { isAxiosError } from 'axios';
import type { UseQueryResult } from '@tanstack/react-query';
import { EmptyState, QueryErrorState, Skeleton } from '@/components/ui';

export interface EtatsProps<T> {
  requete: UseQueryResult<T>;
  /** Ce qu'on essaie de charger, au complément d'objet (« les boîtes d’envoi »). */
  contexte: string;
  /** Vrai quand la réponse est vide. */
  estVide?: (donnees: T) => boolean;
  vide?: ReactNode;
  children: (donnees: T) => ReactNode;
}

export function Chargement() {
  return (
    <div role="status" aria-busy="true" aria-label="Chargement en cours" className="space-y-3">
      <Skeleton className="h-8 w-2/5" />
      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        {[0, 1, 2, 3].map((i) => (
          <Skeleton key={i} className="h-24 w-full" />
        ))}
      </div>
      <Skeleton className="h-56 w-full" />
    </div>
  );
}

export function Etats<T>({ requete, contexte, estVide, vide, children }: EtatsProps<T>) {
  if (requete.isPending) return <Chargement />;
  if (requete.isError) {
    if (isAxiosError(requete.error) && requete.error.response?.status === 404) {
      return vide ? <>{vide}</> : <EmptyState title="Rien à afficher pour le moment" description="Les éléments apparaîtront ici dès qu’il y en aura." />;
    }
    return <QueryErrorState error={requete.error} contexte={contexte} onRetry={() => void requete.refetch()} />;
  }
  if (estVide?.(requete.data) === true) return <>{vide}</>;
  return <>{children(requete.data)}</>;
}
