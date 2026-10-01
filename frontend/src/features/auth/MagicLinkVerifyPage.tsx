import { useEffect, useRef, useState } from 'react';
import { useNavigate, useSearch } from '@tanstack/react-router';
import { Loader2, ShieldAlert } from 'lucide-react';
import { AuthShell } from './LoginPage';
import { api } from '@/lib/api';

/**
 * Point d'arrivée du lien reçu par e-mail (`/magic-link/verify?token=…`).
 *
 * Le serveur sait consommer le jeton (`POST /auth/magic-link/verify`) et
 * `MagicLinkService::issue()` envoie un lien vers cette adresse, mais aucune
 * route du front ne la servait : le lien tombait sur la page 404 et la
 * connexion par lien magique n'a jamais pu aboutir (constaté le 01/10/2026,
 * seule porte de secours d'un compte dont le mot de passe est perdu).
 *
 * `useSearch({ strict: false })` et non `window.location.search`, comme
 * `PasswordResetPage`. Le jeton est consommé UNE fois (one-shot côté serveur) :
 * la garde `envoye` évite un second appel au double rendu.
 */

/** Longueur exacte imposée par `MagicLinkController::verify()` (`size:64`). */
const LONGUEUR_JETON = 64;

export function MagicLinkVerifyPage() {
  const navigate = useNavigate();
  const recherche = useSearch({ strict: false });
  const token = typeof recherche.token === 'string' ? recherche.token : '';
  const [echec, setEchec] = useState(false);
  const envoye = useRef(false);

  useEffect(() => {
    if (envoye.current) return;
    envoye.current = true;

    if (token.length !== LONGUEUR_JETON) {
      setEchec(true);
      return;
    }

    api
      .post<{ requires_2fa?: boolean }>('/auth/magic-link/verify', { token })
      .then(({ data }) => {
        void navigate({ to: data.requires_2fa ? '/2fa' : '/' });
      })
      .catch(() => setEchec(true));
  }, [token, navigate]);

  if (echec) {
    return (
      <AuthShell title="Lien invalide ou expiré" description="Ce lien de connexion ne peut plus être utilisé.">
        <div className="space-y-3 text-center">
          <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
            <ShieldAlert className="h-5 w-5" />
          </div>
          <p className="text-sm text-slate-700 dark:text-slate-200">
            Un lien n'est valable que 15 minutes et une seule fois. Demandez-en un nouveau.
          </p>
          <a
            href="/magic-link"
            className="inline-block text-sm font-medium text-slate-900 hover:underline dark:text-white"
          >
            Recevoir un nouveau lien
          </a>
        </div>
      </AuthShell>
    );
  }

  return (
    <AuthShell title="Connexion en cours" description="Vérification de votre lien de connexion…">
      <div className="flex justify-center py-4 text-slate-500 dark:text-slate-400">
        <Loader2 className="h-6 w-6 animate-spin" aria-label="Connexion en cours" />
      </div>
    </AuthShell>
  );
}
