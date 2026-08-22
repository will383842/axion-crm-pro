/**
 * Portail de la console v2 : drapeau fermé, ces pages n'affichent RIEN.
 *
 * L'API répond déjà 404 sur les routes correspondantes ; ce garde-fou existe
 * pour que l'utilisateur qui tape l'URL à la main voie un écran honnête plutôt
 * qu'une page en erreur permanente. Les deux couches disent la même chose parce
 * qu'elles lisent le MÊME drapeau (`/config/features`).
 */
import type { ReactNode } from 'react';
import { EmptyState, QueryErrorState, Skeleton } from '@/components/ui';
import { CONSOLE_FEATURES_CLOSED, useConsoleFeaturesQuery } from './useConsoleFeatures';

export function ConsoleGate({
  children,
  requiresVivier = false,
}: {
  children: ReactNode;
  requiresVivier?: boolean;
}) {
  const { data, isPending, error, refetch } = useConsoleFeaturesQuery();
  const features = data ?? CONSOLE_FEATURES_CLOSED;

  // Tant que la réponse n'est pas revenue, la console reste fermée — mais on ne
  // l'ANNONCE pas. « Console non activée » pendant qu'on interroge encore le
  // serveur est une affirmation fausse, et elle s'affichait à chaque ouverture
  // de page. Fermé par défaut, muet tant qu'on ne sait pas.
  if (isPending) {
    return (
      <div className="px-6 py-6">
        <ConsoleListSkeleton rows={4} />
      </div>
    );
  }

  // « La console n'est pas ouverte sur ce serveur » est une affirmation sur la
  // CONFIGURATION. Quand `/config/features` échoue, on n'en sait rien : on sait
  // seulement qu'on n'a pas pu demander. Présenter une panne réseau comme une
  // décision d'exploitant envoie l'opérateur réclamer un drapeau à son
  // administrateur — qui le trouvera déjà levé (constat D22-004).
  //
  // Le défaut était masqué par le correctif du chargement : `isPending` retombe
  // à false sur ÉCHEC aussi, `data` reste `undefined`, et le flot atteignait la
  // branche « non activée ». `useConsoleFeatures.ts` pose `retry: false` : un
  // seul hoquet réseau suffisait à produire le faux message.
  //
  // La décision, elle, ne change PAS : `features` vaut toujours
  // CONSOLE_FEATURES_CLOSED, la console reste FERMÉE. Seul le texte change.
  //
  // `data === undefined` fait partie de la condition : React Query v5 conserve
  // la dernière réponse réussie quand un rafraîchissement échoue. Fermer une
  // console qui vient de s'ouvrir, sur un rafraîchissement raté, remplacerait
  // un mensonge par une régression.
  if (error !== null && data === undefined) {
    return (
      <div className="px-6 py-6">
        <QueryErrorState
          error={error}
          contexte="l’état de la console CRM v2"
          onRetry={() => void refetch()}
        />
      </div>
    );
  }

  if (!features.console_v2) {
    return (
      <div className="px-6 py-6">
        <EmptyState
          title="Console non activée"
          description="La console CRM v2 n'est pas ouverte sur ce serveur."
        />
      </div>
    );
  }

  if (requiresVivier && !features.universes.vivier) {
    return (
      <div className="px-6 py-6">
        <EmptyState
          title="Univers vivier candidats non accessible"
          description="L'accès au vivier suppose d'être membre de cet univers. Demandez à un administrateur de vous y rattacher."
        />
      </div>
    );
  }

  return <>{children}</>;
}

/** Squelette partagé des listes de la console (jamais d'écran blanc). */
export function ConsoleListSkeleton({ rows = 8 }: { rows?: number }) {
  return (
    <div className="flex flex-col gap-2" aria-hidden="true">
      {Array.from({ length: rows }, (_, index) => (
        <Skeleton key={index} className="h-14 w-full rounded-lg" />
      ))}
    </div>
  );
}
