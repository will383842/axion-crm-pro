/**
 * Portail de la console v2 : drapeau fermé, ces pages n'affichent RIEN.
 *
 * L'API répond déjà 404 sur les routes correspondantes ; ce garde-fou existe
 * pour que l'utilisateur qui tape l'URL à la main voie un écran honnête plutôt
 * qu'une page en erreur permanente. Les deux couches disent la même chose parce
 * qu'elles lisent le MÊME drapeau (`/config/features`).
 */
import type { ReactNode } from 'react';
import { EmptyState, Skeleton } from '@/components/ui';
import { CONSOLE_FEATURES_CLOSED, useConsoleFeaturesQuery } from './useConsoleFeatures';

export function ConsoleGate({
  children,
  requiresVivier = false,
}: {
  children: ReactNode;
  requiresVivier?: boolean;
}) {
  const { data, isPending } = useConsoleFeaturesQuery();
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
