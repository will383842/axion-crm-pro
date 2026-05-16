import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from '@tanstack/react-router';
import { api } from '@/lib/api';
import { toast } from 'sonner';

export function LoginPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setLoading(true);
    try {
      const { data } = await api.post<{ requires_2fa?: boolean }>('/auth/login', { email, password });
      if (data.requires_2fa) {
        navigate({ to: '/2fa' });
      } else {
        navigate({ to: '/' });
      }
    } catch (err) {
      toast.error(t('common.error'));
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-50 px-4">
      <form onSubmit={onSubmit} className="w-full max-w-sm rounded-xl bg-white p-8 shadow-sm" aria-labelledby="login-title">
        <h1 id="login-title" className="mb-6 text-2xl font-semibold">{t('auth.login.title')}</h1>
        <label className="mb-3 block text-sm">
          <span className="mb-1 block text-slate-700">{t('auth.login.email')}</span>
          <input
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
            autoComplete="email"
            className="w-full rounded-md border border-slate-300 px-3 py-2"
          />
        </label>
        <label className="mb-5 block text-sm">
          <span className="mb-1 block text-slate-700">{t('auth.login.password')}</span>
          <input
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
            autoComplete="current-password"
            className="w-full rounded-md border border-slate-300 px-3 py-2"
          />
        </label>
        <button
          type="submit"
          disabled={loading}
          className="w-full rounded-md bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-700 disabled:opacity-50"
        >
          {loading ? t('common.loading') : t('auth.login.submit')}
        </button>
        <div className="mt-4 flex justify-between text-sm text-slate-600">
          <a href="/magic-link" className="hover:underline">{t('auth.login.magicLink')}</a>
          <a href="/password-reset" className="hover:underline">{t('auth.login.forgot')}</a>
        </div>
      </form>
    </div>
  );
}
