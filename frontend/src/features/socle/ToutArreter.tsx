/**
 * DM0-COQ — « TOUT ARRÊTER », LE BOUTON DE SÉCURITÉ D'ENVOI (maquettes M2).
 *
 *  - Rouge, dans l'en-tête de l'espace Envoi. DEUX clics : le bouton, puis
 *    « Oui, tout arrêter » dans la confirmation. Un clic seul n'arrête rien.
 *  - `POST /envoi/tout-arreter` : plus aucun message ne part, toutes boîtes et
 *    campagnes confondues. Rien n'est perdu.
 *  - Tant que les envois sont arrêtés (`GET /envoi/etat` → `arrete`), un
 *    bandeau rouge PERSISTANT le dit, avec « Reprendre les envois »
 *    (`POST /envoi/reprendre`).
 *
 * Point ouvert du test de Will : le bouton doit-il AUSSI être dans le CRM ?
 * L'ADR-14 dit « dans les deux espaces », les maquettes validées le montrent
 * dans Envoi seulement. La constante ci-dessous tranche, fausse par défaut.
 */
import { useEffect, useId, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Power } from 'lucide-react';
import { toast } from 'sonner';
import { Button, usePiegeFocus } from '@/components/ui';
import { api } from '@/lib/api';
import { CLE_COMPTEURS_ENVOI, CLE_ETAT_ENVOIS, useEtatEnvois, type EtatEnvois } from './EtatEnvois';

/** Montrer « Tout arrêter » aussi dans l'espace CRM (si Will le demande). */
export const TOUT_ARRETER_AUSSI_DANS_LE_CRM = false;

function useChangerEtatEnvois(chemin: '/envoi/tout-arreter' | '/envoi/reprendre', messageErreur: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async () => (await api.post<EtatEnvois>(chemin)).data,
    onSuccess: (etat) => {
      queryClient.setQueryData(CLE_ETAT_ENVOIS, etat);
      void queryClient.invalidateQueries({ queryKey: CLE_COMPTEURS_ENVOI });
    },
    onError: () => {
      toast.error(messageErreur);
    },
  });
}

/** Le bouton rouge de l'en-tête et sa confirmation. */
export function ToutArreter() {
  const [confirmation, setConfirmation] = useState(false);
  const arreter = useChangerEtatEnvois(
    '/envoi/tout-arreter',
    'Les envois n’ont pas pu être arrêtés. Réessayez ; si cela persiste, suspendez les boîtes une à une.',
  );

  return (
    <>
      <Button
        variant="destructive"
        size="sm"
        onClick={() => setConfirmation(true)}
        iconLeft={<Power className="h-3.5 w-3.5" aria-hidden />}
        aria-label="Tout arrêter"
        className="shrink-0"
      >
        <span className="hidden sm:inline">Tout arrêter</span>
      </Button>
      {confirmation ? (
        <ConfirmationToutArreter
          enCours={arreter.isPending}
          onAnnuler={() => setConfirmation(false)}
          onConfirmer={() => arreter.mutate(undefined, { onSuccess: () => setConfirmation(false) })}
        />
      ) : null}
    </>
  );
}

/**
 * La confirmation. Dialogue NOMMÉ (`aria-labelledby`) : `Modal` ne nomme pas
 * le sien, et une confirmation d'arrêt général doit s'annoncer pour ce qu'elle
 * est. Piège de focus partagé (`usePiegeFocus`), `Échap` annule.
 */
function ConfirmationToutArreter({
  enCours,
  onAnnuler,
  onConfirmer,
}: {
  enCours: boolean;
  onAnnuler: () => void;
  onConfirmer: () => void;
}) {
  const idTitre = useId();
  const refDialogue = usePiegeFocus<HTMLDivElement>(true);

  useEffect(() => {
    const auClavier = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onAnnuler();
    };
    document.addEventListener('keydown', auClavier);
    return () => document.removeEventListener('keydown', auClavier);
  }, [onAnnuler]);

  return (
    <div
      ref={refDialogue}
      role="dialog"
      aria-modal="true"
      aria-labelledby={idTitre}
      className="fixed inset-0 z-50 flex items-center justify-center p-4"
    >
      <div className="absolute inset-0 bg-slate-950/40" onClick={onAnnuler} aria-hidden />
      <div className="relative w-full max-w-sm rounded-2xl bg-white p-6 shadow-[var(--shadow-popover)] ring-1 ring-slate-200">
        <h2 id={idTitre} className="text-lg font-semibold tracking-tight text-slate-900">
          Tout arrêter ?
        </h2>
        <p className="mt-2 text-sm text-slate-700">
          Plus aucun message ne part, toutes boîtes et campagnes confondues. Rien n’est perdu : les envois
          reprennent quand vous cliquez sur « Reprendre les envois ».
        </p>
        <div className="mt-5 flex justify-end gap-2">
          <Button variant="secondary" onClick={onAnnuler}>
            Annuler
          </Button>
          <Button variant="destructive" loading={enCours} onClick={onConfirmer}>
            Oui, tout arrêter
          </Button>
        </div>
      </div>
    </div>
  );
}

function heure(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  return d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Paris' });
}

/** Le bandeau rouge persistant, tant que les envois sont arrêtés. */
export function BandeauEnvoisArretes() {
  const { data } = useEtatEnvois(true);
  const reprendre = useChangerEtatEnvois('/envoi/reprendre', 'Les envois n’ont pas pu reprendre. Réessayez dans un instant.');
  if (data?.etat !== 'arrete') return null;
  const depuis = heure(data.depuis);
  return (
    <div role="alert" className="flex flex-wrap items-center gap-3 bg-rose-700 px-4 py-2 text-sm font-medium text-white md:px-6">
      <Power className="h-4 w-4 shrink-0" aria-hidden />
      <span className="min-w-0 flex-1">
        Envois arrêtés
        {depuis !== '' ? ` depuis ${depuis}` : ''}
        {data.par !== null ? ` par ${data.par}` : ''} : plus aucun message ne part.
      </span>
      <Button
        variant="secondary"
        size="sm"
        loading={reprendre.isPending}
        onClick={() => reprendre.mutate()}
      >
        Reprendre les envois
      </Button>
    </div>
  );
}
