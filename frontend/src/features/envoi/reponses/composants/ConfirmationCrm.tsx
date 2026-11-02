import { Link } from '@tanstack/react-router';
import { CheckCircle2 } from 'lucide-react';
import { CLASSEMENTS, formaterDate } from './libelles';
import type { ReponseApresGeste } from './requetes';

export interface ConfirmationGeste {
  nom: string;
  resume: NonNullable<ReponseApresGeste['resume_crm']>;
}

/**
 * Confirmation d'un geste : le résumé envoyé au CRM (classement, date, lien,
 * tâche). Les réponses restent dans Envoi ; le CRM ne reçoit que ce résumé.
 */
export function ConfirmationCrm({ confirmation }: { confirmation: ConfirmationGeste }) {
  const { nom, resume } = confirmation;
  return (
    <div role="status" aria-label="Résumé envoyé au CRM" className="mb-4 flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
      <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
      <div className="min-w-0">
        <p className="font-semibold">Résumé envoyé au CRM · {nom}</p>
        <ul className="mt-1 space-y-0.5">
          <li>Classement : {CLASSEMENTS[resume.classement].libelle}</li>
          <li>Le {formaterDate(resume.le)}</li>
          <li>{resume.tache ? `Tâche CRM : ${resume.tache}` : 'Aucune tâche créée'}</li>
          {resume.opposition === true ? (
            <li>La personne est mise à l’écart des envois. Ses données sont conservées.</li>
          ) : null}
        </ul>
        <Link to="/envoi/reponses/traitees" className="mt-2 inline-block font-medium underline underline-offset-2">
          Voir dans les réponses traitées
        </Link>
      </div>
    </div>
  );
}
