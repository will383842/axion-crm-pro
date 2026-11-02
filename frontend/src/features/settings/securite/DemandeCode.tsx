import { useState } from 'react';
import { Button, Modal } from '@/components/ui';

/** Fenêtre qui demande le code reçu par e-mail avant un geste sensible. */
export function DemandeCode({
  titre,
  description,
  libelleAction,
  onClose,
  onConfirmer,
}: {
  titre: string;
  description: string;
  libelleAction: string;
  onClose: () => void;
  /** Rejette avec un message pour l'afficher sous le champ. */
  onConfirmer: (code: string) => Promise<void>;
}) {
  const [code, setCode] = useState('');
  const [erreur, setErreur] = useState<string | null>(null);
  const [envoi, setEnvoi] = useState(false);

  async function confirmer() {
    setEnvoi(true);
    setErreur(null);
    try {
      await onConfirmer(code);
    } catch (e) {
      setErreur(e instanceof Error ? e.message : 'Code incorrect.');
    } finally {
      setEnvoi(false);
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={titre}
      description={description}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Annuler
          </Button>
          <Button variant="primary" loading={envoi} disabled={code.length !== 6} onClick={() => void confirmer()}>
            {libelleAction}
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        <input
          inputMode="numeric"
          maxLength={6}
          value={code}
          onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
          aria-label="Code à 6 chiffres"
          autoComplete="one-time-code"
          placeholder="••••••"
          className="h-11 w-full rounded-lg bg-white px-3 text-center text-2xl font-semibold tracking-[0.5em] text-slate-900 ring-1 ring-slate-200 focus:outline-none focus:ring-2 focus:ring-slate-300 sm:h-9"
        />
        {erreur !== null ? (
          <p role="alert" className="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700 ring-1 ring-rose-200">
            {erreur}
          </p>
        ) : null}
      </div>
    </Modal>
  );
}

/** Message lisible d'un refus de code (422) ou d'une panne. */
export function messageCode(err: unknown): string {
  const r = (err as { response?: { status?: number; data?: { errors?: { code?: string[] } } } }).response;
  if (r?.status === 422) return r.data?.errors?.code?.[0] ?? 'Code incorrect.';
  return 'Le serveur a refusé la demande. Réessayez dans un instant.';
}
