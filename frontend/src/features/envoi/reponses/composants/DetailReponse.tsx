import { useState } from 'react';
import { Sparkles } from 'lucide-react';
import { Button, QueryErrorState, Skeleton } from '@/components/ui';
import { CLASSEMENTS, CLASSEMENTS_AVEC_TACHE, EFFET_SUGGESTION, formaterDate, initiales, nomComplet, type Classement } from './libelles';
import { FilMessages } from './FilMessages';
import { FormulaireReponse } from './FormulaireReponse';
import { Initiales, PastilleClassement } from './PastilleClassement';
import { ModaleStop } from './ModaleStop';
import { useClasser, useDetailReponse, type ReponseApresGeste } from './requetes';

export interface DetailReponseProps {
  id: string;
  stopOuvert: boolean;
  onStopOuvert: (ouvert: boolean) => void;
  onGeste: (nom: string, resume: NonNullable<ReponseApresGeste['resume_crm']>) => void;
}

const GESTES: ReadonlyArray<{ touche: string; classement: Classement; libelle: string }> = [
  { touche: '1', classement: 'refus', libelle: 'Pas intéressé' },
  { touche: '2', classement: 'pas_maintenant', libelle: 'Plus tard' },
  { touche: '3', classement: 'absence', libelle: 'Absent' },
];

/** Le détail d'une réponse : fil, suggestion appliquée en un geste, réponse, classement. */
export function DetailReponse({ id, stopOuvert, onStopOuvert, onGeste }: DetailReponseProps) {
  const detail = useDetailReponse(id);
  const classer = useClasser();
  const [erreur, setErreur] = useState(false);

  if (detail.isPending) {
    return <div role="status" aria-label="Chargement de la réponse"><Skeleton className="h-64 w-full" /></div>;
  }
  if (detail.isError) {
    return <QueryErrorState error={detail.error} contexte="cette réponse" onRetry={() => void detail.refetch()} />;
  }
  const { reponse, fil } = detail.data;
  const nom = nomComplet(reponse);

  function classerEn(classement: Classement, options: { creer_tache?: boolean; toute_organisation?: boolean } = {}) {
    setErreur(false);
    classer.mutate(
      { id, classement, ...options },
      {
        onSuccess: (r) => {
          if (r.resume_crm) onGeste(nom, r.resume_crm);
          onStopOuvert(false);
        },
        onError: () => setErreur(true),
      },
    );
  }

  const suggestion = reponse.suggestion;
  function appliquerSuggestion() {
    if (suggestion.classement === 'stop') {
      onStopOuvert(true);
      return;
    }
    classerEn(suggestion.classement, { creer_tache: CLASSEMENTS_AVEC_TACHE.includes(suggestion.classement) });
  }

  return (
    <article aria-label={`Réponse de ${nom}`} className="space-y-5 rounded-xl border border-slate-200 bg-white p-4 md:p-5">
      <header className="flex flex-wrap items-start gap-3">
        <Initiales texte={initiales(reponse)} />
        <div className="min-w-0 flex-1">
          <h2 className="text-lg font-bold text-slate-900">{nom}</h2>
          <p className="break-words text-xs text-slate-500">
            {[reponse.organisation, reponse.de].filter(Boolean).join(' · ')} · {formaterDate(reponse.recue_le)}
          </p>
        </div>
        <PastilleClassement classement={reponse.classement} />
      </header>

      <FilMessages fil={fil} />

      <section aria-label="Suggestion" className="rounded-xl bg-produit-pale p-4">
        <p className="flex items-center gap-2 text-sm font-semibold text-slate-900">
          <Sparkles className="h-4 w-4 text-produit" aria-hidden />
          Suggestion : {CLASSEMENTS[suggestion.classement].libelle} ({suggestion.confiance} %)
        </p>
        <p className="mt-1 text-sm text-slate-700">{EFFET_SUGGESTION[suggestion.classement]}</p>
        <div className="mt-3">
          <Button className="max-md:h-11" loading={classer.isPending} onClick={appliquerSuggestion}>
            Appliquer la suggestion
          </Button>
        </div>
      </section>

      <section aria-label="Répondre">
        <h3 className="mb-2 text-sm font-semibold text-slate-900">Répondre</h3>
        <FormulaireReponse id={id} />
      </section>

      <div role="group" aria-label="Classer autrement" className="flex flex-wrap gap-2 border-t border-slate-100 pt-4">
        {GESTES.map((g) => (
          <Button key={g.touche} variant="secondary" aria-keyshortcuts={g.touche} className="max-md:h-11" onClick={() => classerEn(g.classement)}>
            <Touche>{g.touche}</Touche>
            {g.libelle}
          </Button>
        ))}
        <Button variant="destructive" aria-keyshortcuts="S" className="max-md:h-11" onClick={() => onStopOuvert(true)}>
          <Touche>S</Touche>
          STOP
        </Button>
      </div>
      {erreur ? (
        <p role="alert" className="text-sm text-rose-700">
          Le classement n’a pas pu être enregistré. Rien n’est perdu : réessayez dans un instant.
        </p>
      ) : null}

      <ModaleStop
        reponse={reponse}
        ouverte={stopOuvert}
        enCours={classer.isPending}
        onAnnuler={() => onStopOuvert(false)}
        onConfirmer={(toute) => classerEn('stop', { toute_organisation: toute })}
      />
    </article>
  );
}

function Touche({ children }: { children: string }) {
  return (
    <kbd aria-hidden className="mr-1 inline-flex h-5 min-w-5 items-center justify-center rounded bg-white/70 px-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-300">
      {children}
    </kbd>
  );
}
