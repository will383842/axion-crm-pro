import { useState } from 'react';
import { useNavigate } from '@tanstack/react-router';
import { useTranslation } from 'react-i18next';
import { Shield } from 'lucide-react';
import { Button } from '@/components/ui';
import { AuthShell } from './LoginPage';
import { api } from '@/lib/api';
import { toast } from 'sonner';

export function TwoFactorPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const [code, setCode] = useState('');
  const [loading, setLoading] = useState(false);

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setLoading(true);
    try {
      await api.post('/auth/2fa/verify', { code });
      navigate({ to: '/' });
    } catch {
      toast.error('Code invalide');
    } finally {
      setLoading(false);
    }
  }

  return (
    <AuthShell title={t('auth.twoFactor.title')} description="Saisis le code à 6 chiffres généré par ton authenticator.">
      <form onSubmit={onSubmit} className="space-y-4">
        <input
          inputMode="numeric"
          pattern="[0-9]*"
          maxLength={6}
          value={code}
          onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
          aria-label="6-digit code"
          autoComplete="one-time-code"
          required
          className="w-full rounded-lg bg-white px-3 py-3 text-center text-2xl font-semibold tracking-[0.5em] text-slate-900 ring-1 ring-slate-200 transition focus:outline-none focus:ring-2 focus:ring-slate-300 dark:bg-slate-900 dark:text-white dark:ring-slate-700"
          placeholder="••••••"
        />

        <Button
          type="submit"
          variant="primary"
          full
          loading={loading}
          iconLeft={<Shield className="h-3.5 w-3.5" />}
          disabled={code.length !== 6}
        >
          {t('auth.twoFactor.submit')}
        </Button>

        <a
          href="/login"
          className="block text-center text-xs text-slate-500 hover:text-slate-900 hover:underline dark:text-slate-400 dark:hover:text-white"
        >
          Retour à la connexion
        </a>
      </form>
    </AuthShell>
  );
}
