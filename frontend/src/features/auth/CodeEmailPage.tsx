import { useState } from 'react';
import { useNavigate } from '@tanstack/react-router';
import { MailCheck } from 'lucide-react';
import { Button } from '@/components/ui';
import { api } from '@/lib/api';
import { AuthShell } from './LoginPage';

/**
 * Code à 6 chiffres reçu par e-mail (deuxième étape de la connexion).
 *
 * Il vit à côté de `TwoFactorPage` (le code de l'application d'authentification
 * reste valable tant que le lot S12 n'est pas livré) : `TwoFactorPage` le
 * montre quand le serveur annonce `two_factor_method: 'email'`. En démonstration,
 * le code est celui affiché dans le bandeau.
 */

const LONGUEUR = 6;
const MAX_RENVOIS = 3;

function messageErreur(err: unknown): string {
  const r = (err as { response?: { status?: number; data?: { errors?: { code?: string[] } } } }).response;
  if (r?.status === 422) return r.data?.errors?.code?.[0] ?? 'Code incorrect. Vérifiez-le et réessayez.';
  if (r?.status === 429) return 'Trop d’essais. Patientez quelques minutes, puis réessayez.';
  return 'Le code n’a pas pu être vérifié. Réessayez dans un instant.';
}

export function CodeEmailPage() {
  const navigate = useNavigate();
  const [code, setCode] = useState('');
  const [confiance, setConfiance] = useState(false);
  const [envoi, setEnvoi] = useState(false);
  const [erreur, setErreur] = useState<string | null>(null);
  const [renvois, setRenvois] = useState(0);
  const [info, setInfo] = useState<string | null>(null);
  const [limite, setLimite] = useState(false);

  async function valider(e: React.FormEvent) {
    e.preventDefault();
    setEnvoi(true);
    setErreur(null);
    try {
      await api.post('/auth/code-email/verifier', { code, appareil_confiance: confiance });
      void navigate({ to: '/' });
    } catch (err) {
      setErreur(messageErreur(err));
    } finally {
      setEnvoi(false);
    }
  }

  async function renvoyer() {
    setErreur(null);
    try {
      await api.post('/auth/code-email/renvoyer');
      const n = renvois + 1;
      setRenvois(n);
      setInfo('Un nouveau code vient de partir. Il remplace le précédent.');
      if (n >= MAX_RENVOIS) setLimite(true);
    } catch {
      setInfo(null);
      setLimite(true);
    }
  }

  return (
    <AuthShell
      title="Code reçu par e-mail"
      description="Saisissez le code à 6 chiffres que nous venons de vous envoyer."
    >
      <form onSubmit={(e) => void valider(e)} aria-labelledby="auth-title" className="space-y-4">
        <input
          inputMode="numeric"
          pattern="[0-9]*"
          maxLength={LONGUEUR}
          value={code}
          onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, LONGUEUR))}
          onPaste={(e) => {
            e.preventDefault();
            setCode(e.clipboardData.getData('text').replace(/\D/g, '').slice(0, LONGUEUR));
          }}
          aria-label="Code à 6 chiffres"
          autoComplete="one-time-code"
          required
          className="h-11 w-full rounded-lg bg-white px-3 text-center text-2xl font-semibold tracking-[0.5em] text-slate-900 ring-1 ring-slate-200 transition focus:outline-none focus:ring-2 focus:ring-slate-300 sm:h-12"
          placeholder="••••••"
        />

        <label className="flex min-h-11 items-center gap-2 text-sm text-slate-700 sm:min-h-0">
          <input
            type="checkbox"
            checked={confiance}
            onChange={(e) => setConfiance(e.target.checked)}
            className="h-4 w-4 rounded border-slate-300"
          />
          <span>Faire confiance à cet appareil pendant 30 jours</span>
        </label>

        {erreur !== null ? (
          <p role="alert" className="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700 ring-1 ring-rose-200">
            {erreur}
          </p>
        ) : null}
        {info !== null ? (
          <p role="status" className="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 ring-1 ring-emerald-200">
            {info}
          </p>
        ) : null}

        <Button
          type="submit"
          variant="primary"
          full
          loading={envoi}
          iconLeft={<MailCheck className="h-3.5 w-3.5" />}
          disabled={code.length !== LONGUEUR}
        >
          Valider le code
        </Button>

        <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
          <Button type="button" variant="ghost" onClick={() => void renvoyer()} disabled={limite}>
            Renvoyer le code
          </Button>
          <a href="/login" className="hover:text-slate-900 hover:underline">
            Retour à la connexion
          </a>
        </div>
        {limite ? (
          <p className="text-xs text-slate-500">Limite de renvois atteinte : patientez quelques minutes avant de réessayer.</p>
        ) : null}
      </form>
    </AuthShell>
  );
}
