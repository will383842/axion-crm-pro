import { useState } from 'react';
import { useNavigate } from '@tanstack/react-router';
import { useTranslation } from 'react-i18next';
import { api } from '@/lib/api';

export function TwoFactorPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const [code, setCode] = useState('');

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    await api.post('/auth/2fa/verify', { code });
    navigate({ to: '/' });
  }

  return (
    <div className="flex min-h-screen items-center justify-center px-4">
      <form onSubmit={onSubmit} className="w-full max-w-sm rounded-xl bg-white p-8 shadow-sm">
        <h1 className="mb-6 text-2xl font-semibold">{t('auth.twoFactor.title')}</h1>
        <input
          inputMode="numeric"
          pattern="[0-9]*"
          maxLength={6}
          value={code}
          onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
          className="mb-5 w-full rounded-md border border-slate-300 px-3 py-2 text-center text-2xl tracking-widest"
          aria-label="6-digit code"
          autoComplete="one-time-code"
          required
        />
        <button type="submit" className="w-full rounded-md bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-700">
          {t('auth.twoFactor.submit')}
        </button>
      </form>
    </div>
  );
}
