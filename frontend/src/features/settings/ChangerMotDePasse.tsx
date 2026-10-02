/**
 * « Mon compte → Changer mon mot de passe ».
 *
 * Constat prod du 2026-10-02 : aucun écran ne permettait de changer son mot de
 * passe une fois connecté. Le serveur (`POST /auth/password/change`) exige
 * l'ancien mot de passe, SAUF si la session a été ouverte par lien de connexion
 * il y a moins de 30 minutes ; `GET /auth/password/change` dit lequel des deux
 * cas s'applique. Si l'état a changé entre-temps (délai écoulé), le refus
 * `mot_de_passe_actuel_requis` fait apparaître le champ.
 */
import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { KeyRound, ShieldCheck } from 'lucide-react';
import { Button, Card, CardEyebrow, CardHeader, CardTitle, Input } from '@/components/ui';
import { api } from '@/lib/api';
import { toast } from 'sonner';

/** Minimum imposé par `Password::min(12)` côté serveur. */
const LONGUEUR_MIN = 12;

interface EtatChangement {
  email: string;
  mot_de_passe_actuel_requis: boolean;
  minutes_restantes_sans_ancien: number | null;
}

interface CorpsRefus {
  error?: string;
  message?: string;
  errors?: Record<string, string[]>;
}

function corpsRefus(err: unknown): { status: number | null; corps: CorpsRefus | undefined } {
  const r = (err as { response?: { status?: number; data?: CorpsRefus } }).response;
  return { status: r?.status ?? null, corps: r?.data };
}

export function ChangerMotDePasse() {
  const qc = useQueryClient();
  const etat = useQuery({
    queryKey: ['auth', 'password-change'],
    queryFn: async () => (await api.get<EtatChangement>('/auth/password/change')).data,
  });

  const [actuel, setActuel] = useState('');
  const [nouveau, setNouveau] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [forcerActuel, setForcerActuel] = useState(false);
  const [envoi, setEnvoi] = useState(false);
  const [erreur, setErreur] = useState<string | null>(null);
  const [fait, setFait] = useState(false);

  const actuelRequis = forcerActuel || etat.data?.mot_de_passe_actuel_requis !== false;
  const confirmationDiffere = confirmation !== '' && confirmation !== nouveau;

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setEnvoi(true);
    setErreur(null);
    try {
      await api.post('/auth/password/change', {
        ...(actuelRequis ? { current_password: actuel } : {}),
        password: nouveau,
        password_confirmation: confirmation,
      });
      setFait(true);
      setActuel('');
      setNouveau('');
      setConfirmation('');
      setForcerActuel(false);
      toast.success('Mot de passe modifié');
      void qc.invalidateQueries({ queryKey: ['auth', 'password-change'] });
    } catch (err: unknown) {
      const { status, corps } = corpsRefus(err);
      if (corps?.error === 'mot_de_passe_actuel_requis') {
        setForcerActuel(true);
        setErreur('Indiquez votre mot de passe actuel.');
      } else if (corps?.error === 'mot_de_passe_actuel_incorrect') {
        setErreur('Le mot de passe actuel est incorrect.');
      } else if (status === 429) {
        setErreur('Trop d’essais. Patientez une minute, puis réessayez.');
      } else if (status === 422) {
        setErreur(
          corps?.errors?.['password']?.[0] ??
            corps?.message ??
            `Mot de passe refusé : ${LONGUEUR_MIN} caractères minimum, et il ne doit pas figurer dans une fuite connue.`,
        );
      } else {
        setErreur('Le serveur a refusé la demande. Réessayez dans un instant.');
      }
    } finally {
      setEnvoi(false);
    }
  }

  return (
    <Card className="max-w-xl">
      <CardHeader>
        <div>
          <CardEyebrow>Mon compte</CardEyebrow>
          <CardTitle className="mt-1 text-base">Changer mon mot de passe</CardTitle>
        </div>
      </CardHeader>

      <ul className="mb-4 space-y-1 text-sm text-slate-600 dark:text-slate-300">
        <li>• {LONGUEUR_MIN} caractères minimum.</li>
        <li>• Il ne doit pas figurer dans une fuite de données connue (vérification anonyme).</li>
        <li>• Vos autres appareils seront déconnectés ; celui-ci reste connecté.</li>
      </ul>

      {!actuelRequis && etat.data?.minutes_restantes_sans_ancien ? (
        <p className="mb-4 rounded-lg bg-sky-50 px-3 py-2 text-sm text-sky-800 ring-1 ring-sky-200 dark:bg-sky-950/40 dark:text-sky-200 dark:ring-sky-900/40">
          Cette session a été ouverte par un lien de connexion&nbsp;: votre mot de passe actuel
          n’est pas demandé (encore {etat.data.minutes_restantes_sans_ancien}&nbsp;min).
        </p>
      ) : null}

      {fait ? (
        <p
          role="status"
          className="mb-4 flex items-center gap-2 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 ring-1 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-200 dark:ring-emerald-900/40"
        >
          <ShieldCheck className="h-4 w-4" /> Votre mot de passe est modifié. Si votre navigateur
          vous propose de l’enregistrer, acceptez.
        </p>
      ) : null}

      <form onSubmit={(e) => void onSubmit(e)} className="space-y-4" aria-label="Changer mon mot de passe">
        {/* Identifiant du compte, invisible : le gestionnaire de mots de passe
            du navigateur enregistre ainsi le NOUVEAU sous la bonne adresse. */}
        <input
          type="email"
          name="username"
          autoComplete="username"
          value={etat.data?.email ?? ''}
          readOnly
          hidden
        />

        {actuelRequis ? (
          <label className="block text-sm">
            <span className="mb-1 block font-medium text-slate-700 dark:text-slate-300">
              Mot de passe actuel
            </span>
            <Input
              type="password"
              name="current-password"
              value={actuel}
              onChange={(e) => setActuel(e.target.value)}
              required
              autoComplete="current-password"
            />
          </label>
        ) : null}

        <label className="block text-sm">
          <span className="mb-1 block font-medium text-slate-700 dark:text-slate-300">
            Nouveau mot de passe
          </span>
          <Input
            type="password"
            name="new-password"
            value={nouveau}
            onChange={(e) => setNouveau(e.target.value)}
            required
            autoComplete="new-password"
            placeholder={`${LONGUEUR_MIN} caractères minimum`}
          />
        </label>

        <label className="block text-sm">
          <span className="mb-1 block font-medium text-slate-700 dark:text-slate-300">
            Confirmation du nouveau mot de passe
          </span>
          <Input
            type="password"
            name="new-password-confirmation"
            value={confirmation}
            onChange={(e) => setConfirmation(e.target.value)}
            required
            autoComplete="new-password"
            placeholder="Retapez le même mot de passe"
          />
        </label>

        {confirmationDiffere ? (
          <p className="text-xs text-rose-700 dark:text-rose-400">Les deux saisies ne correspondent pas.</p>
        ) : null}

        {erreur !== null ? (
          <p
            role="alert"
            className="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-950/40 dark:text-rose-300 dark:ring-rose-900/40"
          >
            {erreur}
          </p>
        ) : null}

        <div className="flex justify-end border-t border-slate-100 pt-4 dark:border-slate-800">
          <Button
            type="submit"
            variant="primary"
            loading={envoi}
            iconLeft={<KeyRound className="h-3.5 w-3.5" />}
            disabled={
              nouveau.length < LONGUEUR_MIN ||
              confirmation !== nouveau ||
              (actuelRequis && actuel === '')
            }
          >
            Enregistrer le nouveau mot de passe
          </Button>
        </div>
      </form>
    </Card>
  );
}
