/**
 * `RouteErrorBoundary` — la frontiere d'erreur, rearmee par le chemin courant.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ CE QUI SE PASSAIT VRAIMENT AVANT (mesure, pas hypothese)                 ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * L'audit (P6-UI-005 / D24-008) annonce « page blanche sur toute exception de
 * rendu ». MESURE : c'est FAUX, et le vrai comportement n'est pas meilleur.
 *
 * `RouterProvider` installe un filet GLOBAL, sans qu'on le demande :
 * `@tanstack/react-router@1.170.4`, `dist/esm/Matches.js:36-44` —
 *
 *     router.options.disableGlobalCatchBoundary ? matchComponent : jsx(CatchBoundary, {
 *       getResetKey: () => resetKey,
 *       errorComponent: ErrorComponent,          // <- le defaut de la librairie
 *       ...
 *
 * Consequences reellement observees (cf. la sortie ROUGE de
 * `tests/components/ErrorBoundary.montage.test.tsx`) : le contenu de la page
 * devient exactement
 *
 *     « Something went wrong!Hide Error<message brut de l'exception> »
 *
 *  - en ANGLAIS, dans un produit francais ;
 *  - la coquille ENTIERE disparait (barre laterale, en-tete, `#main`) : ce filet
 *    est pose au-dessus de TOUS les « matches », il ne remplace pas l'ecran
 *    fautif, il remplace l'application. Plus aucune navigation possible ;
 *  - le message d'exception est OFFERT a l'utilisateur derriere un bouton
 *    « Show Error » (`CatchBoundary.js:51` : le contenu est masque par defaut en
 *    production, mais le bouton, lui, est toujours la).
 *
 * Et `tests/e2e/console-locale.spec.ts:379` assurait
 * `not.toContainText('Une erreur est survenue.')` : ce texte n'etant monte
 * nulle part, l'assertion etait vraie par construction. Elle ne mesurait rien.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ POURQUOI UN COMPOSANT REACT ET PAS `defaultErrorComponent`               ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * `createRouter({ defaultErrorComponent })` aurait aussi marche (c'est la ligne
 * `Match.js:71-76` qui, quand l'option existe, installe une `CatchBoundary` PAR
 * match). On ne l'a pas prise : le depot possede DEJA `ErrorBoundary`, ecrit,
 * exporte et jamais monte. Le defaut a reparer est un defaut de MONTAGE. Poser
 * un second mecanisme a cote aurait laisse le premier orphelin — c'est le
 * travers mesure 25 fois dans ce depot (le correctif existe, il n'est pas
 * porte).
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ LES TROIS SITES DE MONTAGE — ET POURQUOI IL EN FAUT TROIS                ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 *  1. `src/app/routeTree.tsx`, composant de `rootRoute` — niveau `root`.
 *     Couvre `/login`, `/2fa`, `/magic-link`, `/password-reset`, qui sont
 *     enfants de `rootRoute` et NON de `layoutRoute` (`routeTree.tsx:61-64`).
 *     C'est le SITE JUMEAU qu'on oublie : reparer la coquille seule aurait
 *     laisse les quatre ecrans d'authentification sur l'ecran anglais.
 *     Couvre aussi une explosion dans `RootLayout` lui-meme (barre laterale,
 *     en-tete), qui est au-DESSUS de l'`Outlet`.
 *
 *  2. `src/app/RootLayout.tsx`, autour de l'`Outlet` — niveau `page`.
 *     Une frontiere REACT posee plus bas gagne toujours : c'est elle qui
 *     attrape en premier. Resultat : l'ecran fautif est remplace, la coquille
 *     reste, l'utilisateur peut cliquer ailleurs. Sans ce niveau, un bug sur
 *     une seule page condamnerait toute l'application.
 *
 *  3. `src/app/AppRoot.tsx`, autour du routeur — niveau `root`.
 *     Filet de dernier recours pour ce qui explose HORS du routeur
 *     (`QueryClientProvider`, `Toaster`, ou les entrailles de
 *     `RouterProvider`) : le filet global de la librairie est POSE A
 *     L'INTERIEUR de `RouterProvider`, il ne voit rien de tout cela.
 */
import type { ReactElement, ReactNode } from 'react';
import { useRouterState } from '@tanstack/react-router';
import { ErrorBoundary } from '@/components/ui';

export interface RouteErrorBoundaryProps {
  children: ReactNode;
  level: 'root' | 'page';
}

export function RouteErrorBoundary({ children, level }: RouteErrorBoundaryProps): ReactElement {
  /**
   * ⚠️ `pathname` + `status`, ET NON `pathname` SEUL — corrige par la MESURE.
   *
   * Premiere version : `resetKey={pathname}`. La garde « se rearme apres
   * navigation » est restee ROUGE, avec l'ecran d'erreur encore affiche alors
   * que le fil d'Ariane indiquait deja la nouvelle page (« Accueil/Ailleurs »).
   * Explication : `pathname` change AVANT que l'arbre de matches ne soit
   * bascule. La frontiere se rearmait donc trop tot, re-rendait l'ANCIEN ecran
   * — celui qui explose —, reprenait l'erreur, et cette fois avec la nouvelle
   * cle. Elle restait bloquee pour de bon : le rearmement fabriquait
   * exactement la panne qu'il devait eviter.
   *
   * 🔴 LA DEUXIEME VERSION EMPLOYAIT `state.loadedAt`, ET CETTE PROPRIETE
   *    N'EXISTE PLUS. Mesure du 2026-08-21 :
   *
   *      router-core 1.171.2  (installe en local) : RouterState.loadedAt EXISTE
   *      router-core 1.171.22 (resolu par le lock) : RouterState = { status,
   *                             isLoading, matches, location, resolvedLocation }
   *
   *    `loadedAt` a disparu de la librairie ENTIERE — ni dans l'etat, ni dans
   *    `router.stores`. Le typecheck passait donc en local et rougissait en CI
   *    (`TS2339`), parce que le `node_modules` de ce worktree est un LIEN vers
   *    celui du depot principal, fige a `react-router@1.170.4`. Un typecheck
   *    joue ici ne prouve rien tant que ce lien existe : la seule reference est
   *    `pnpm-lock.yaml`.
   *
   * La librairie a remplace `loadedAt` par exactement la cle ci-dessous —
   * `@tanstack/react-router@1.170.27/dist/esm/not-found.js:26` :
   *
   *     const resetKey = `not-found-${...location.pathname}-${...status}`
   *
   * `status` passe par `pending` avant `idle` : la cle ne prend sa valeur
   * finale qu'une fois les matches en place. C'est la propriete qui manquait a
   * `pathname` seul, et c'est pour cela que le couple tient.
   */
  const resetKey = useRouterState({
    select: (state) => `${state.location.pathname}-${state.status}`,
  });

  return (
    <ErrorBoundary level={level} resetKey={resetKey}>
      {children}
    </ErrorBoundary>
  );
}
