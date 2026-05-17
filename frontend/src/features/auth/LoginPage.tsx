import { useState } from 'react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from '@tanstack/react-router';
import { Eye, EyeOff, LogIn, Sparkles } from 'lucide-react';
import { Button, Card, Input } from '@/components/ui';
import { api } from '@/lib/api';
import { toast } from 'sonner';

export function AuthShell({
  title,
  description,
  children,
}: {
  title: string;
  description?: string;
  children: ReactNode;
}) {
  return (
    <div className="relative flex min-h-screen flex-col items-center justify-center bg-gradient-to-br from-slate-50 via-white to-sky-50/30 px-4 py-12 dark:from-slate-950 dark:via-slate-900 dark:to-sky-950/30">
      {/* Subtle decorative orbs */}
      <div className="pointer-events-none absolute inset-0 overflow-hidden">
        <div className="absolute -left-32 top-1/3 h-72 w-72 rounded-full bg-sky-200/30 blur-3xl dark:bg-sky-900/20" />
        <div className="absolute -right-32 bottom-1/4 h-72 w-72 rounded-full bg-violet-200/20 blur-3xl dark:bg-violet-900/20" />
      </div>

      <div className="relative w-full max-w-sm">
        <div className="mb-6 flex items-center justify-center gap-2 text-slate-900 dark:text-white">
          <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-slate-900 to-slate-700 text-white shadow-md dark:from-white dark:to-slate-100 dark:text-slate-900">
            <Sparkles className="h-4 w-4" />
          </div>
          <div className="text-base font-semibold tracking-tight">Axion CRM Pro</div>
        </div>

        <Card variant="glass" padding="lg" className="backdrop-blur-md">
          <h1 className="mb-1 text-xl font-semibold tracking-tight text-slate-900 dark:text-white">
            {title}
          </h1>
          {description ? (
            <p className="mb-5 text-sm text-slate-500 dark:text-slate-400">{description}</p>
          ) : (
            <div className="mb-3" />
          )}
          {children}
        </Card>
      </div>
    </div>
  );
}

export function LoginPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [remember, setRemember] = useState(true);
  const [loading, setLoading] = useState(false);

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setLoading(true);
    try {
      const { data } = await api.post<{ requires_2fa?: boolean }>('/auth/login', {
        email,
        password,
        remember,
      });
      if (data.requires_2fa) {
        navigate({ to: '/2fa' });
      } else {
        navigate({ to: '/' });
      }
    } catch {
      toast.error(t('common.error'));
    } finally {
      setLoading(false);
    }
  }

  return (
    <AuthShell title={t('auth.login.title')} description="Connecte-toi à ton workspace Axion CRM Pro.">
      <form onSubmit={onSubmit} aria-labelledby="login-title" className="space-y-4">
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
            aria-label={t('auth.login.email')}
          />
        </label>

        <label className="block text-sm">
          <span className="mb-1 block font-medium text-slate-700 dark:text-slate-300">
            {t('auth.login.password')}
          </span>
          <Input
            type={showPassword ? 'text' : 'password'}
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
            autoComplete="current-password"
            aria-label={t('auth.login.password')}
            iconRight={
              <button
                type="button"
                onClick={() => setShowPassword((v) => !v)}
                aria-label={showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'}
                aria-pressed={showPassword}
                className="pointer-events-auto rounded-md p-0.5 text-slate-400 hover:text-slate-700 dark:hover:text-slate-200"
              >
                {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
              </button>
            }
          />
        </label>

        <label className="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
          <input
            type="checkbox"
            checked={remember}
            onChange={(e) => setRemember(e.target.checked)}
            className="h-4 w-4 rounded border-slate-300"
          />
          <span>Se souvenir de moi</span>
        </label>

        <Button
          type="submit"
          variant="primary"
          full
          loading={loading}
          iconLeft={<LogIn className="h-3.5 w-3.5" />}
        >
          {loading ? t('common.loading') : t('auth.login.submit')}
        </Button>

        <div className="flex justify-between text-xs text-slate-500 dark:text-slate-400">
          <a href="/magic-link" className="hover:text-slate-900 hover:underline dark:hover:text-white">
            {t('auth.login.magicLink')}
          </a>
          <a href="/password-reset" className="hover:text-slate-900 hover:underline dark:hover:text-white">
            {t('auth.login.forgot')}
          </a>
        </div>
      </form>
    </AuthShell>
  );
}
