import { useEffect, useState } from 'react';
import { useNavigate } from '@tanstack/react-router';
import { useTranslation } from 'react-i18next';
import { Shield, Copy, Check } from 'lucide-react';
import { Button } from '@/components/ui';
import { AuthShell } from './LoginPage';
import { api } from '@/lib/api';
import { toast } from 'sonner';

/**
 * Écran de la double authentification — les DEUX moments, pas seulement un.
 *
 * 🔴 POURQUOI CETTE PAGE A ÉTÉ RÉÉCRITE (audit 360, D22-001, S0).
 *
 * Le serveur EXIGE l'enrôlement 2FA avant tout usage : tant que
 * `first_login_completed_at` est nul, `EnforceFirstLoginSetup` répond 403 sur
 * toute route métier, et le seul endroit qui pose ce champ est
 * `TwoFactorService::confirmEnrolment()`.
 *
 * Or **aucun écran n'appelait `/auth/2fa/setup` ni `/auth/2fa/confirm`** :
 * recherche exhaustive sur `frontend/src`, zéro occurrence. Cette page ne savait
 * que saisir un code, pour un secret qu'il était **impossible de créer**. Le
 * propriétaire du CRM ne pouvait donc pas franchir sa première connexion — c'est
 * l'un des quatre verrous qui expliquent 0 session en production depuis le
 * 2026-05-17.
 *
 * La page décide maintenant elle-même où en est le compte :
 * - pas encore de 2FA  → enrôlement (clé, saisie du code, codes de secours) ;
 * - 2FA déjà active    → saisie du code, comme avant.
 *
 * Pas de QR code : aucune bibliothèque de rendu QR n'est présente dans ce dépôt,
 * et on n'en ajoute pas une au passage. Toutes les applications
 * d'authentification acceptent la saisie manuelle de la clé, et le lien
 * `otpauth://` ouvre l'application directement sur mobile.
 */

type Etape = 'chargement' | 'enrolement' | 'code-a-saisir' | 'codes-de-secours';

interface ReponseMoi {
  user?: { totp_enabled_at?: string | null };
}

export function TwoFactorPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();

  const [etape, setEtape] = useState<Etape>('chargement');
  const [code, setCode] = useState('');
  const [loading, setLoading] = useState(false);
  const [secret, setSecret] = useState<string | null>(null);
  const [urlOtp, setUrlOtp] = useState<string | null>(null);
  const [codesDeSecours, setCodesDeSecours] = useState<string[]>([]);
  const [copie, setCopie] = useState(false);

  // Où en est ce compte ? On le demande au serveur plutôt que de le deviner.
  useEffect(() => {
    let annule = false;
    api
      .get<ReponseMoi>('/auth/me')
      .then(({ data }) => {
        if (annule) return;
        setEtape(data.user?.totp_enabled_at ? 'code-a-saisir' : 'enrolement');
      })
      .catch(() => {
        if (!annule) setEtape('enrolement');
      });

    return () => {
      annule = true;
    };
  }, []);

  async function demarrerEnrolement() {
    setLoading(true);
    try {
      const { data } = await api.post<{ secret: string; qr_url: string }>('/auth/2fa/setup');
      setSecret(data.secret);
      setUrlOtp(data.qr_url ?? null);
    } catch {
      toast.error("L'activation n'a pas pu démarrer. Réessayez dans un instant.");
    } finally {
      setLoading(false);
    }
  }

  async function confirmerEnrolement(e: React.FormEvent) {
    e.preventDefault();
    setLoading(true);
    try {
      const { data } = await api.post<{ recovery_codes: string[] }>('/auth/2fa/confirm', { code });
      setCodesDeSecours(data.recovery_codes ?? []);
      setEtape('codes-de-secours');
      setCode('');
    } catch {
      toast.error("Code refusé. Vérifiez l'heure de votre téléphone, puis réessayez.");
    } finally {
      setLoading(false);
    }
  }

  async function verifierCode(e: React.FormEvent) {
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

  function copier(texte: string) {
    void navigator.clipboard?.writeText(texte);
    setCopie(true);
    window.setTimeout(() => setCopie(false), 2000);
  }

  const champCode = (
    <input
      inputMode="numeric"
      pattern="[0-9]*"
      maxLength={6}
      value={code}
      onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
      aria-label="Code à 6 chiffres"
      autoComplete="one-time-code"
      required
      className="w-full rounded-lg bg-white px-3 py-3 text-center text-2xl font-semibold tracking-[0.5em] text-slate-900 ring-1 ring-slate-200 transition focus:outline-none focus:ring-2 focus:ring-slate-300 dark:bg-slate-900 dark:text-white dark:ring-slate-700"
      placeholder="••••••"
    />
  );

  if (etape === 'chargement') {
    return (
      <AuthShell title="Double authentification" description="Un instant…">
        <p className="text-sm text-slate-500 dark:text-slate-400">Chargement…</p>
      </AuthShell>
    );
  }

  // ── Les codes de secours : montrés UNE fois, et on le dit ──────────────────
  if (etape === 'codes-de-secours') {
    return (
      <AuthShell
        title="Vos codes de secours"
        description="Ils s'affichent une seule fois. Notez-les maintenant, ailleurs que sur cet ordinateur."
      >
        <div className="space-y-4">
          <ul className="grid grid-cols-2 gap-2 rounded-lg bg-slate-50 p-3 dark:bg-slate-900">
            {codesDeSecours.map((c) => (
              <li key={c} className="font-mono text-sm tracking-wider text-slate-800 dark:text-slate-100">
                {c}
              </li>
            ))}
          </ul>
          <p className="text-xs text-slate-500 dark:text-slate-400">
            Chacun ne sert qu&apos;une fois. Ils vous permettent d&apos;entrer si vous perdez votre
            téléphone.
          </p>
          <Button
            type="button"
            variant="secondary"
            full
            iconLeft={copie ? <Check className="h-3.5 w-3.5" /> : <Copy className="h-3.5 w-3.5" />}
            onClick={() => copier(codesDeSecours.join('\n'))}
          >
            {copie ? 'Copiés' : 'Copier les codes'}
          </Button>
          <Button type="button" variant="primary" full onClick={() => navigate({ to: '/' })}>
            J&apos;ai noté mes codes, continuer
          </Button>
        </div>
      </AuthShell>
    );
  }

  // ── L'enrôlement : ce qui n'existait nulle part ────────────────────────────
  if (etape === 'enrolement') {
    return (
      <AuthShell
        title="Activer la double authentification"
        description="Elle est obligatoire pour accéder au CRM. Comptez une minute."
      >
        {!secret ? (
          <div className="space-y-4">
            <p className="text-sm text-slate-600 dark:text-slate-300">
              Vous aurez besoin d&apos;une application d&apos;authentification sur votre téléphone —
              Google Authenticator, Microsoft Authenticator, 1Password ou équivalent.
            </p>
            <Button
              type="button"
              variant="primary"
              full
              loading={loading}
              iconLeft={<Shield className="h-3.5 w-3.5" />}
              onClick={demarrerEnrolement}
            >
              Commencer
            </Button>
          </div>
        ) : (
          <form onSubmit={confirmerEnrolement} className="space-y-4">
            <div className="space-y-2">
              <p className="text-sm text-slate-600 dark:text-slate-300">
                <span className="font-medium">1.</span> Dans votre application, ajoutez un compte par
                saisie manuelle et collez cette clé&nbsp;:
              </p>
              <div className="flex items-center gap-2">
                <code className="flex-1 break-all rounded-lg bg-slate-50 p-3 font-mono text-sm text-slate-800 dark:bg-slate-900 dark:text-slate-100">
                  {secret}
                </code>
                <Button
                  type="button"
                  variant="secondary"
                  aria-label="Copier la clé"
                  iconLeft={copie ? <Check className="h-3.5 w-3.5" /> : <Copy className="h-3.5 w-3.5" />}
                  onClick={() => copier(secret)}
                >
                  {copie ? 'Copiée' : 'Copier'}
                </Button>
              </div>
              {urlOtp ? (
                <p className="text-xs text-slate-500 dark:text-slate-400">
                  Sur mobile, ce lien ouvre directement votre application&nbsp;:{' '}
                  <a href={urlOtp} className="underline">
                    ajouter le compte
                  </a>
                </p>
              ) : null}
            </div>

            <div className="space-y-2">
              <p className="text-sm text-slate-600 dark:text-slate-300">
                <span className="font-medium">2.</span> Saisissez le code à 6 chiffres qu&apos;elle
                affiche&nbsp;:
              </p>
              {champCode}
            </div>

            <Button type="submit" variant="primary" full loading={loading} disabled={code.length !== 6}>
              Activer
            </Button>
          </form>
        )}
      </AuthShell>
    );
  }

  // ── La saisie du code, à chaque connexion ──────────────────────────────────
  return (
    <AuthShell
      title={t('auth.twoFactor.title')}
      description="Saisis le code à 6 chiffres généré par ton authenticator."
    >
      <form onSubmit={verifierCode} className="space-y-4">
        {champCode}

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
