/**
 * Briques communes des écrans de conformité et de réglages (DM2-CNF) :
 * les 4 états (chargement, vide, erreur, normal), un bloc titré, une annonce
 * vocale, des champs aux zones tactiles de 44 px au téléphone.
 */
import type { ReactNode } from 'react';
import type { UseQueryResult } from '@tanstack/react-query';
import { ShieldCheck } from 'lucide-react';
import { Card, EmptyState, QueryErrorState, Skeleton } from '@/components/ui';
import { cn } from '@/components/ui/cn';

/** Hauteur d'un champ : 36 px, 44 px au téléphone. */
export const CLASSE_CHAMP =
  'h-9 w-full rounded-lg bg-white px-3 text-sm text-slate-900 ring-1 ring-slate-200 transition placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-produit max-md:h-11';
export const CLASSE_BOUTON_MOBILE = 'max-md:h-11 max-md:min-w-11';

/** Le texte d'état vide commun à tous les écrans du lot. */
export function Vide({ titre = 'Rien à afficher pour le moment' }: { titre?: string }) {
  return <EmptyState title={titre} description="Les éléments apparaîtront ici dès qu’il y en aura." icon={<ShieldCheck />} />;
}

export function Chargement({ lignes = 4 }: { lignes?: number }) {
  return (
    <div role="status" aria-label="Chargement" className="space-y-3">
      {Array.from({ length: lignes }, (_, i) => (
        <Skeleton key={i} className="h-12 w-full rounded-xl" />
      ))}
    </div>
  );
}

/**
 * Aiguille une requête vers ses 4 états. `vide` : prédicat sur les données ;
 * l'échec n'est affiché que s'il n'y a aucune donnée à montrer.
 */
export function Etat<T>({
  requete,
  contexte,
  vide,
  lignes,
  children,
}: {
  requete: UseQueryResult<T>;
  contexte: string;
  vide?: (donnees: T) => boolean;
  lignes?: number;
  children: (donnees: T) => ReactNode;
}) {
  if (requete.error !== null && requete.data === undefined) {
    return <QueryErrorState error={requete.error} contexte={contexte} onRetry={() => void requete.refetch()} />;
  }
  if (requete.isLoading || requete.data === undefined) return <Chargement lignes={lignes ?? 4} />;
  if (vide?.(requete.data) === true) return <Vide />;
  return <>{children(requete.data)}</>;
}

export function Bloc({
  titre,
  icone,
  droite,
  children,
  className,
}: {
  titre: string;
  icone?: ReactNode;
  droite?: ReactNode;
  children: ReactNode;
  className?: string;
}) {
  return (
    <Card className={cn('min-w-0', className)}>
      <div className="mb-3 flex flex-wrap items-center gap-2">
        {icone ? <span className="text-produit" aria-hidden>{icone}</span> : null}
        <h2 className="text-lg font-bold text-slate-900">{titre}</h2>
        {droite ? <div className="ml-auto">{droite}</div> : null}
      </div>
      {children}
    </Card>
  );
}

/** Annonce lue par les lecteurs d'écran ; rien n'est rendu sans message. */
export function Annonce({ message, ton = 'ok' }: { message: string | null; ton?: 'ok' | 'erreur' }) {
  if (message === null) return null;
  return (
    <p
      role={ton === 'erreur' ? 'alert' : 'status'}
      className={cn('mt-3 rounded-lg px-3 py-2 text-sm', ton === 'erreur' ? 'bg-rose-50 text-rose-800' : 'bg-emerald-50 text-emerald-800')}
    >
      {message}
    </p>
  );
}

export function Etiquette({ htmlFor, children }: { htmlFor: string; children: ReactNode }) {
  return (
    <label htmlFor={htmlFor} className="mb-1 block text-xs font-medium text-slate-600">
      {children}
    </label>
  );
}

/** Phrase d'erreur d'une requête d'écriture (message du serveur si présent). */
export function messageDErreur(e: unknown, defaut: string): string {
  const corps = (e as { response?: { data?: { message?: unknown } } } | null)?.response?.data?.message;
  return typeof corps === 'string' ? corps : defaut;
}
