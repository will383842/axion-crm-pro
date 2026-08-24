import { useState } from 'react';
import { useSearch } from '@tanstack/react-router';
import { KeyRound, MailCheck, ShieldCheck } from 'lucide-react';
import { Button, Input } from '@/components/ui';
import { AuthShell } from './LoginPage';
import { api } from '@/lib/api';
import { toast } from 'sonner';

/**
 * 🔴 X39-037 — CE LIEN NE MENAIT NULLE PART.
 *
 * Le courriel de réinitialisation — et, depuis le 2026-08-24, celui
 * d'invitation — pointe vers `/password-reset?token=…&email=…`. Cette page
 * ignorait ces deux paramètres : elle réaffichait le formulaire de DEMANDE, et
 * redemandait l'adresse. Cliquer le lien renvoyait donc à son point de départ.
 *
 * Mesure du 2026-08-24, avant correctif :
 *   grep -rn "password/reset" frontend/src  →  AUCUN résultat
 *
 * Le point d'entrée `POST /auth/password/reset` existait côté serveur, complet
 * et gardé (jeton de 64 caractères, expiration, contrôle HIBP) — **personne ne
 * l'appelait**. Il n'y avait aucun écran pour choisir un mot de passe.
 *
 * La conséquence dépassait la gêne : un compte créé par « Inviter un
 * utilisateur » naît SANS mot de passe, par conception. Sans cet écran, il ne
 * pouvait donc **jamais** se connecter — le CRM était structurellement
 * mono-utilisateur, quoi qu'affiche l'écran d'administration.
 *
 * 🔑 DEUX ÉCRANS, UNE SEULE ROUTE, ET C'EST VOULU. Le lien reçu par courriel ne
 * doit pas obliger à retaper l'adresse : le jeton et l'adresse sont DANS
 * l'URL. La page choisit donc son mode d'après ce qu'elle y trouve — demande si
 * elle est nue, définition si elle porte les deux paramètres.
 */

/** Longueur exacte imposée par `PasswordResetController::reset()` (`size:64`). */
const LONGUEUR_JETON = 64;

/** Minimum imposé par `Password::min(12)` côté serveur. */
const LONGUEUR_MIN_MOT_DE_PASSE = 12;

/**
 * Lit le jeton et l'adresse depuis les paramètres de recherche du ROUTEUR.
 *
 * ⚠️ `useSearch({ strict: false })` et NON `window.location.search`. Ma première
 * version lisait l'URL globale ; le banc l'a refusée, et il avait raison : les
 * écrans se montent sur un historique EN MÉMOIRE, où `window.location` ne
 * reflète rien. Un composant qui lit l'URL du navigateur n'est donc pas
 * testable — et, plus profondément, il ignore le routeur qui gouverne la page.
 *
 * `strict: false` parce que cette route ne déclare pas de schéma de recherche :
 * on ne fait que relayer les deux paramètres au serveur, c'est lui qui les
 * juge. C'est le patron déjà employé par `PasEncoreLivrePage`.
 */
function lireLienRecu(recherche: Record<string, unknown>): { token: string; email: string } | null {
  const token = typeof recherche.token === 'string' ? recherche.token : '';
  const email = typeof recherche.email === 'string' ? recherche.email : '';

  // Les deux, ou rien. Un jeton sans adresse (ou l'inverse) ne peut pas aboutir
  // — autant réafficher la demande plutôt qu'un formulaire voué au 422.
  if (token.length !== LONGUEUR_JETON || email === '') return null;

  return { token, email };
}

export function PasswordResetPage() {
  const recherche = useSearch({ strict: false });
  const lien = lireLienRecu(recherche);

  // ── Mode DEMANDE (URL nue) ────────────────────────────────────────────────
  const [email, setEmail] = useState('');
  const [sent, setSent] = useState(false);
  const [loading, setLoading] = useState(false);

  // ── Mode DÉFINITION (URL porteuse du lien reçu) ───────────────────────────
  const [motDePasse, setMotDePasse] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [defini, setDefini] = useState(false);
  const [erreur, setErreur] = useState<string | null>(null);

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

  async function onDefinir(e: React.FormEvent) {
    e.preventDefault();
    if (!lien) return;

    setLoading(true);
    setErreur(null);
    try {
      await api.post('/auth/password/reset', {
        email: lien.email,
        token: lien.token,
        password: motDePasse,
        // `confirmed` côté serveur exige EXACTEMENT ce nom de champ.
        password_confirmation: confirmation,
      });
      setDefini(true);
      toast.success('Mot de passe enregistré');
    } catch (e: unknown) {
      // 🔑 ON DIT CE QUE LE SERVEUR A RÉPONDU, pas « une erreur est survenue ».
      // Les trois refus possibles n'appellent pas le même geste : un lien
      // expiré se redemande, un mot de passe trop faible se rechoisit. Un
      // message unique laisserait la personne bloquée sans savoir quoi faire.
      const reponse = (e as { response?: { status?: number; data?: { error?: string; message?: string } } }).response;
      const code = reponse?.data?.error;

      if (code === 'expired_token') {
        setErreur('Ce lien a expiré. Demandez-en un nouveau depuis « Mot de passe oublié ».');
      } else if (code === 'invalid_token') {
        setErreur('Ce lien n’est plus valide. Il a peut-être déjà servi, ou été remplacé par un plus récent.');
      } else if (reponse?.status === 422) {
        setErreur(
          reponse?.data?.message ??
            `Mot de passe refusé : ${LONGUEUR_MIN_MOT_DE_PASSE} caractères minimum, et il ne doit pas figurer dans une fuite connue.`,
        );
      } else {
        setErreur('Le serveur a refusé la demande. Réessayez, ou demandez un nouveau lien.');
      }
    } finally {
      setLoading(false);
    }
  }

  // ═══ MODE DÉFINITION ══════════════════════════════════════════════════════
  if (lien) {
    return (
      <AuthShell
        title="Choisir un mot de passe"
        description={`Pour le compte ${lien.email}. Ce lien est valable 60 minutes.`}
      >
        {defini ? (
          <div className="space-y-3 text-center">
            <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
              <ShieldCheck className="h-5 w-5" />
            </div>
            <p className="text-sm text-slate-700 dark:text-slate-200">
              Votre mot de passe est enregistré.
            </p>
            <a
              href="/login"
              className="inline-block text-xs text-slate-500 hover:text-slate-900 hover:underline dark:text-slate-400 dark:hover:text-white"
            >
              Se connecter
            </a>
          </div>
        ) : (
          <form onSubmit={(e) => void onDefinir(e)} className="space-y-4">
            {erreur ? (
              <div
                role="alert"
                className="rounded-xl border-2 border-dashed border-rose-300 bg-rose-50/60 px-4 py-3 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-300"
              >
                {erreur}
              </div>
            ) : null}

            <label className="block text-sm">
              <span className="mb-1 block font-medium text-slate-700 dark:text-slate-300">
                Nouveau mot de passe
              </span>
              <Input
                type="password"
                value={motDePasse}
                onChange={(e) => setMotDePasse(e.target.value)}
                required
                autoComplete="new-password"
                placeholder={`${LONGUEUR_MIN_MOT_DE_PASSE} caractères minimum`}
              />
            </label>

            <label className="block text-sm">
              <span className="mb-1 block font-medium text-slate-700 dark:text-slate-300">
                Confirmation
              </span>
              <Input
                type="password"
                value={confirmation}
                onChange={(e) => setConfirmation(e.target.value)}
                required
                autoComplete="new-password"
                placeholder="Retapez le même mot de passe"
              />
            </label>

            {/* Contrôle LOCAL, en plus de celui du serveur : deux saisies qui
                diffèrent n'ont pas besoin d'un aller-retour pour être vues. */}
            {confirmation !== '' && confirmation !== motDePasse ? (
              <p className="text-xs text-rose-700 dark:text-rose-400">
                Les deux saisies ne correspondent pas.
              </p>
            ) : null}

            <Button
              type="submit"
              variant="primary"
              full
              loading={loading}
              iconLeft={<ShieldCheck className="h-3.5 w-3.5" />}
              disabled={
                motDePasse.length < LONGUEUR_MIN_MOT_DE_PASSE || confirmation !== motDePasse
              }
            >
              Enregistrer le mot de passe
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

  // ═══ MODE DEMANDE (comportement d'origine, inchangé) ══════════════════════
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
        <form onSubmit={(e) => void onSubmit(e)} className="space-y-4">
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
