import { useState } from 'react';
import { Button, Modal } from '@/components/ui';
import { nomComplet, type Reponse } from './libelles';

export interface ModaleStopProps {
  reponse: Reponse;
  ouverte: boolean;
  enCours: boolean;
  onAnnuler: () => void;
  onConfirmer: (touteOrganisation: boolean) => void;
}

/** Confirmation du STOP : mise à l'écart des envois, données gardées, jamais de suppression. */
export function ModaleStop({ reponse, ouverte, enCours, onAnnuler, onConfirmer }: ModaleStopProps) {
  const [organisation, setOrganisation] = useState(false);
  return (
    <Modal
      open={ouverte}
      onClose={onAnnuler}
      title={`Mettre ${nomComplet(reponse)} à l’écart des envois ?`}
      description="L’adresse est inscrite au registre d’opposition et les relances sont annulées. Les données sont conservées : rien n’est supprimé."
      footer={
        <>
          <Button variant="secondary" className="max-md:h-11" onClick={onAnnuler}>
            Annuler
          </Button>
          <Button variant="destructive" className="max-md:h-11" loading={enCours} onClick={() => onConfirmer(organisation)}>
            Confirmer le STOP
          </Button>
        </>
      }
    >
      {reponse.organisation ? (
        <label className="flex items-start gap-2 text-sm text-slate-700">
          <input
            type="checkbox"
            className="mt-0.5 h-4 w-4 rounded border-slate-300"
            checked={organisation}
            onChange={(e) => setOrganisation(e.target.checked)}
          />
          <span>Étendre à toute l’organisation ({reponse.organisation})</span>
        </label>
      ) : null}
    </Modal>
  );
}
