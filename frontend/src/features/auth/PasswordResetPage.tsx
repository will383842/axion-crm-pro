import { useState } from 'react';
import { api } from '@/lib/api';
import { toast } from 'sonner';

export function PasswordResetPage() {
  const [email, setEmail] = useState('');
  const [sent, setSent] = useState(false);

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    await api.post('/auth/password/forgot', { email });
    setSent(true);
    toast.success('Email envoyé');
  }

  return (
    <div className="flex min-h-screen items-center justify-center px-4">
      <form onSubmit={onSubmit} className="w-full max-w-sm rounded-xl bg-white p-8 shadow-sm">
        <h1 className="mb-6 text-2xl font-semibold">Réinitialiser le mot de passe</h1>
        {sent ? (
          <p className="text-sm text-slate-600">Un lien vous a été envoyé par email.</p>
        ) : (
          <>
            <input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
              className="mb-5 w-full rounded-md border border-slate-300 px-3 py-2"
            />
            <button type="submit" className="w-full rounded-md bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-700">
              Envoyer
            </button>
          </>
        )}
      </form>
    </div>
  );
}
