import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link as LinkIcon, MailCheck } from 'lucide-react';
import { Button, Input } from '@/components/ui';
import { AuthShell } from './LoginPage';
import { api } from '@/lib/api';
import { toast } from 'sonner';
import { messageErreurAuth } from './messagesErreur';

export function MagicLinkPage() {
  const { t } = useTranslation();
  const [email, setEmail] = useState('');
  const [sent, setSent] = useState(false);
  const [loading, setLoading] = useState(false);

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setLoading(true);
    try {
      await api.post('/auth/magic-link', { email });
      setSent(true);
      // Audit UX du 02/10 (P0-5) — le toast de succès affichait le LIBELLÉ DU
      // BOUTON (« Recevoir un lien magique »). Il dit désormais ce qui s'est passé.
      toast.success('Lien envoyé. Consultez votre boîte de réception.');
    } catch (err) {
      toast.error(messageErreurAuth(err, 'Le lien n’a pas pu être envoyé. Vérifiez l’adresse e-mail, puis réessayez.'));
    } finally {
      setLoading(false);
    }
  }

  return (
    <AuthShell
      title={t('auth.login.magicLink')}
      description="Recevez un lien de connexion par e-mail, sans mot de passe."
    >
      {sent ? (
        <div className="space-y-3 text-center">
          <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
            <MailCheck className="h-5 w-5" />
          </div>
          <p className="text-sm text-slate-700 dark:text-slate-200">
            Lien envoyé à <strong>{email}</strong>
          </p>
          <p className="text-xs text-slate-500 dark:text-slate-400">
            Consultez votre boîte de réception (et les indésirables). Le lien est valable 15 minutes.
          </p>
          <a
            href="/login"
            className="inline-block text-xs text-slate-500 hover:text-slate-900 hover:underline dark:text-slate-400 dark:hover:text-white"
          >
            Retour à la connexion
          </a>
        </div>
      ) : (
        <form onSubmit={(e) => void onSubmit(e)} className="space-y-4">
          <label className="block text-sm">
            <span className="mb-1 block font-medium text-slate-700 dark:text-slate-300">
              {t('auth.login.email')}
            </span>
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
            iconLeft={<LinkIcon className="h-3.5 w-3.5" />}
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
