import { Link } from '@tanstack/react-router';

/**
 * Écran « Page introuvable ».
 *
 * D22-007 / D28-013 — cet écran n'était atteignable par AUCUNE URL jusqu'au
 * 2026-08-22 : il pendait à une route `path: '/*'`, que TanStack Router v1 lit
 * comme un segment statique nommé « * ». Il est désormais branché en
 * `notFoundComponent` sur la racine (`src/app/routeTree.tsx`).
 *
 * D28-012 — le `<main>` n'est pas décoratif : rendu par la racine, cet écran vit
 * HORS de la coquille, donc hors du `<main id="main">` de `RootLayout`. Sans
 * lui, la page n'offrirait aucun repère de région à une navigation par points de
 * repère — le même vide que celui mesuré sur les quatre écrans
 * d'authentification.
 */
export function NotFoundPage() {
  return (
    <main
      id="main"
      className="flex min-h-screen flex-col items-center justify-center text-center"
    >
      <p className="text-5xl font-bold text-slate-300">404</p>
      <h1 className="mt-3 text-xl font-semibold">Page introuvable</h1>
      <Link to="/" className="mt-4 text-brand-600 hover:underline">Retour au tableau de bord</Link>
    </main>
  );
}
