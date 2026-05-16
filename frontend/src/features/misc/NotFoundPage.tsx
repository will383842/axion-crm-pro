import { Link } from '@tanstack/react-router';

export function NotFoundPage() {
  return (
    <div className="flex min-h-screen flex-col items-center justify-center text-center">
      <p className="text-5xl font-bold text-slate-300">404</p>
      <h1 className="mt-3 text-xl font-semibold">Page introuvable</h1>
      <Link to="/" className="mt-4 text-brand-600 hover:underline">Retour au tableau de bord</Link>
    </div>
  );
}
