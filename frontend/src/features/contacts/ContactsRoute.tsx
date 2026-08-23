/**
 * D22-005 — `/contacts` cesse d'exister quand la console v2 est ouverte.
 *
 * Le défaut, mesuré le 2026-08-22 : la barre latérale RETIRE l'entrée
 * `/contacts` dès que `console_v2` est vrai (`Sidebar.tsx`, `sectionContacts` —
 * l'entrée n'existe que dans la branche « console fermée »), mais la ROUTE
 * restait vivante en toutes circonstances, sans la moindre redirection. Un
 * signet, un lien profond ou l'historique du navigateur ramenaient donc
 * l'utilisateur sur un écran que la navigation ne montre plus — et qui n'avait
 * aucun moyen de dire qu'il était périmé.
 *
 * UN SEUL DRAPEAU DÉCIDE, comme partout ailleurs dans la console : la
 * redirection est CONDITIONNÉE à `console_v2` et n'est écrite nulle part en dur.
 * Le retour arrière annoncé « en une minute » (cf. `useConsoleFeatures.ts`)
 * reste donc possible : drapeau refermé, `/contacts` redevient l'écran, et les
 * signets pris entre-temps sur `/console/contacts` ne sont pas cassés par ce
 * fichier.
 *
 * L'état de chargement ne préjuge de RIEN — même règle que `ConsoleGate` : tant
 * que `/config/features` n'a pas répondu, on ne redirige pas et on n'affiche pas
 * non plus l'ancien écran, on attend. Rediriger pendant qu'on ne sait pas
 * enverrait sur `/console/contacts` un utilisateur dont la console est fermée.
 */
import { Navigate } from '@tanstack/react-router';
import { CONSOLE_FEATURES_CLOSED, useConsoleFeaturesQuery } from '@/features/crm-console/useConsoleFeatures';
import { ConsoleListSkeleton } from '@/features/crm-console/ConsoleGate';
import { ContactsListPage } from './ContactsListPage';

export function ContactsRoute() {
  const { data, isPending } = useConsoleFeaturesQuery();

  if (isPending) {
    return (
      <div className="px-6 py-6">
        <ConsoleListSkeleton rows={4} />
      </div>
    );
  }

  // Sur ÉCHEC de `/config/features`, `data` reste indéfini et l'on retombe sur
  // « console fermée » : l'ancien écran s'affiche. C'est la bonne réponse — il
  // fonctionne, et un hoquet réseau ne doit pas rediriger l'utilisateur.
  const features = data ?? CONSOLE_FEATURES_CLOSED;

  if (features.console_v2) {
    // `replace` : la page périmée ne doit pas laisser d'entrée d'historique,
    // sinon le bouton Précédent y ramène en boucle.
    return <Navigate to="/console/contacts" replace />;
  }

  return <ContactsListPage />;
}
