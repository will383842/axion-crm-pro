import { useState } from 'react';
import { KeyRound, MailCheck } from 'lucide-react';
import { Button, Input } from '@/components/ui';
import { AuthShell } from './LoginPage';
import { api } from '@/lib/api';
import { toast } from 'sonner';

export function PasswordResetPage() {
  const [email, setEmail] = useState('');
  const [sent, setSent] = useState(false);
  const [loading, setLoading] = useState(false);

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setLoading(true);
    try {
      await api.post('/auth/password/forgot', { email });
      setSent(true);
      toast.success('Email envoyé');
    } catch {
      toast.error('Erreur envoi du lien');
    } finally {
      setLoading(false);
    }
  }

  return (
    <AuthShell
      title="Réinitialiser le mot de passe"
      description="Saisis l'email associé à ton compte, on t'envoie un lien sécurisé."
    >
      {sent ? (
        <div className="space-y-3 text-center">
          <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
            <MailCheck className="h-5 w-5" />
          </div>
          <p className="text-sm text-slate-700 dark:text-slate-200">
            Un lien a été envoyé à <strong>{email}</strong>
          </p>
          <p className="text-xs text-slate-500 dark:text-slate-400">
            Vérifie ta boîte mail. Le lien expire dans 60 minutes.
          </p>
          <a
            href="/login"
            className="inline-block text-xs text-slate-500 hover:text-slate-900 hover:underline dark:text-slate-400 dark:hover:text-white"
          >
            Retour à la connexion
          </a>
        </div>
      ) : (
        <form onSubmit={onSubmit} className="space-y-4">
          <label className="block text-sm">
            <span className="mb-1 block font-medium text-slate-700 dark:text-slate-300">Email</span>
            <Input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
              autoComplete="email"
              placeholder="prenom.nom@exemple.com"
            />
          </label>

          <Button
            type="submit"
            variant="primary"
            full
            loading={loading}
            iconLeft={<KeyRound className="h-3.5 w-3.5" />}
            disabled={!email}
          >
            Envoyer le lien
          </Button>

          <a
            href="/login"
            className="block text-center text-xs text-slate-500 hover:text-slate-900 hover:underline dark:text-slate-400 dark:hover:text-white"
          >
            Retour à la connexion
          </a>
        </form>
      )}
    </AuthShell>
  );
}
